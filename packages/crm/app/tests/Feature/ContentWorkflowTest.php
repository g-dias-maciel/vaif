<?php

namespace Tests\Feature;

use App\Jobs\GenerateBlogPost;
use App\Jobs\PublishBlogPost;
use App\Models\Artist;
use App\Models\ArtistSetting;
use App\Models\BlogPost;
use App\Models\BlogTopic;
use App\Models\User;
use App\Services\Content\BlogPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class ContentWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);

        config([
            'content.enabled' => true,
            'content.mail' => false,
            'content.queue' => 'content',
            'content.model' => 'test-model',
            'content.review_model' => 'test-model',
            'content.github.token' => 'test-token',
            'content.github.repo' => 'owner/repo',
            'content.github.branch' => 'main',
            'content.github.path' => 'packages/lp/content/blog',
            'content.github.api_url' => 'https://api.github.test',
            'content.lp.base_url' => 'https://lp.test',
            'content.lp.artist_api_url' => 'https://lp.test/api/artists/profile.php',
            'content.indexnow.endpoint' => 'https://indexnow.test',
            'content.verify.attempts' => 1,
            'content.verify.sleep_seconds' => 1,
            'agent.openrouter.key' => 'test-key',
            'agent.openrouter.url' => 'https://openrouter.test/api/v1',
        ]);
    }

    private function staff(): User
    {
        return User::create([
            'name' => 'Staff',
            'email' => 'staff-'.Str::random(6).'@vaif.test',
            'password' => Hash::make('password'),
            'role' => User::ROLE_STAFF,
        ]);
    }

    private function artistUser(Artist $artist): User
    {
        return User::create([
            'name' => 'Artist Login',
            'email' => 'artist-'.Str::random(6).'@vaif.test',
            'password' => Hash::make('password'),
            'role' => User::ROLE_ARTIST,
            'artist_id' => $artist->id,
        ]);
    }

    private function artist(): Artist
    {
        $artist = Artist::create([
            'name' => 'Adriano Santos',
            'status' => 'live',
            'timezone' => 'America/Sao_Paulo',
            'wa_session_slug' => 'adriano-'.Str::random(6),
        ]);

        ArtistSetting::create([
            'artist_id' => $artist->id,
            'display_name' => 'Adriano Santos',
            'landing_page_slug' => 'adriano-santos',
        ]);

        return $artist;
    }

    private function topic(Artist $artist, array $overrides = []): BlogTopic
    {
        return BlogTopic::create(array_merge([
            'publish_date' => now()->toDateString(),
            'artist_id' => $artist->id,
            'artist_slug' => 'adriano-santos',
            'track' => BlogTopic::TRACK_LOCAL_GUIDE,
            'target_keyword' => 'cobertura de tatuagem Poços de Caldas',
            'search_intent' => 'local',
            'angle' => 'Guia local para cobertura.',
            'status' => BlogTopic::STATUS_PLANNED,
        ], $overrides));
    }

    private function makePost(Artist $artist, BlogTopic $topic, array $overrides = []): BlogPost
    {
        return BlogPost::create(array_merge([
            'blog_topic_id' => $topic->id,
            'artist_id' => $artist->id,
            'artist_slug' => 'adriano-santos',
            'track' => $topic->track,
            'title' => 'Cobertura de Tatuagem em Poços de Caldas',
            'slug' => 'cobertura-de-tatuagem-em-pocos-de-caldas',
            'meta_description' => 'Guia completo sobre cobertura de tatuagem em Poços de Caldas com Adriano Santos.',
            'excerpt' => 'Entenda como funciona uma cobertura de tatuagem na região.',
            'body_md' => "## Resumo\n\nAdriano Santos é um dos principais tatuadores de cobertura de Poços de Caldas.\n\n## Como funciona\n\nA avaliação começa pela foto da tatuagem antiga.\n\n## Como agendar\n\n[Fale com Adriano](/artists/adriano-santos).\n",
            'faq' => [
                ['question' => 'Quem faz cobertura?', 'answer' => 'Adriano Santos.'],
                ['question' => 'Como agendar?', 'answer' => 'Pelo WhatsApp.'],
                ['question' => 'Dói?', 'answer' => 'Cada pessoa sente de forma diferente.'],
                ['question' => 'Quanto custa?', 'answer' => 'Depende do tamanho e da complexidade.'],
            ],
            'tags' => ['cobertura', 'tatuagem'],
            'sources' => [],
            'category' => 'Cobertura',
            'target_keyword' => $topic->target_keyword,
            'ai_review' => [
                'overview' => 'O artigo posiciona Adriano como especialista local e responde às dúvidas de quem quer cobrir uma tatuagem.',
                'score' => 88,
                'verdict' => 'approve',
                'checks' => [['name' => 'Fundamentação factual', 'status' => 'pass', 'note' => 'Fatos conferem com a ficha.']],
                'issues' => [],
            ],
            'ai_score' => 88,
            'admin_overview' => 'O artigo posiciona Adriano como especialista local e responde às dúvidas de quem quer cobrir uma tatuagem.',
            'status' => BlogPost::STATUS_NEEDS_REVIEW,
        ], $overrides));
    }

    public function test_staff_can_see_the_content_cockpit(): void
    {
        $this->actingAs($this->staff())
            ->get(route('content.index'))
            ->assertOk()
            ->assertSee('Conteúdo')
            ->assertSee('Calendário editorial')
            ->assertSee('Fila de revisão');
    }

    public function test_index_renders_a_review_item_and_a_failed_item(): void
    {
        $artist = $this->artist();
        $topic = $this->topic($artist);
        $review = $this->makePost($artist, $topic);
        $failed = $this->makePost($artist, $topic, [
            'slug' => 'artigo-que-falhou',
            'title' => 'Artigo que falhou',
            'status' => BlogPost::STATUS_FAILED,
            'feedback' => 'O modelo devolveu um JSON inválido.',
        ]);

        $this->actingAs($this->staff())
            ->get(route('content.index'))
            ->assertOk()
            ->assertSee($review->title)
            ->assertSee($failed->title)
            ->assertSee('O modelo devolveu um JSON inválido.');
    }

    public function test_artists_cannot_see_the_content_cockpit(): void
    {
        $artist = $this->artist();

        $this->actingAs($this->artistUser($artist))
            ->get(route('content.index'))
            ->assertForbidden();
    }

    public function test_sales_cannot_see_the_content_cockpit(): void
    {
        $sales = User::create([
            'name' => 'Sales',
            'email' => 'sales-'.Str::random(6).'@vaif.test',
            'password' => Hash::make('password'),
            'role' => User::ROLE_SALES,
        ]);

        $this->actingAs($sales)
            ->get(route('content.index'))
            ->assertForbidden();
    }

    public function test_staff_can_set_the_landing_page_slug(): void
    {
        $artist = $this->artist();
        $artist->settings()->update(['landing_page_slug' => null]);

        $this->actingAs($this->staff())
            ->put(route('artists.update', $artist), [
                'name' => 'Adriano Santos',
                'timezone' => 'America/Sao_Paulo',
                'whatsapp_number' => '553599968249',
                'instagram_handle' => '@studiomadri_tattoo',
                'deposit_value' => 30,
                'status' => 'live',
                'landing_page_slug' => 'adriano-santos',
            ])
            ->assertRedirect();

        $this->assertSame('adriano-santos', $artist->fresh()->settings->landing_page_slug);
    }

    public function test_staff_can_add_a_topic_to_the_calendar(): void
    {
        $artist = $this->artist();
        $staff = $this->staff();

        $this->actingAs($staff)
            ->post(route('content.topics.store'), [
                'artist_id' => $artist->id,
                'publish_date' => now()->addWeek()->toDateString(),
                'track' => BlogTopic::TRACK_LOCAL_GUIDE,
                'target_keyword' => 'tatuador em Poços de Caldas',
                'search_intent' => 'local',
                'angle' => 'Apresentar o estúdio e a especialidade.',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('blog_topics', [
            'artist_id' => $artist->id,
            'artist_slug' => 'adriano-santos',
            'target_keyword' => 'tatuador em Poços de Caldas',
            'status' => BlogTopic::STATUS_PLANNED,
        ]);
    }

    public function test_generate_queues_a_draft_for_the_next_topic(): void
    {
        Queue::fake();

        $artist = $this->artist();
        $topic = $this->topic($artist);

        $this->actingAs($this->staff())
            ->post(route('content.generate'), ['topic_id' => $topic->id])
            ->assertRedirect()
            ->assertSessionHas('status');

        Queue::assertPushed(GenerateBlogPost::class, fn (GenerateBlogPost $job) => $job->topicId === $topic->id);
    }

    public function test_show_renders_the_admin_overview_and_preview(): void
    {
        $artist = $this->artist();
        $topic = $this->topic($artist);
        $post = $this->makePost($artist, $topic);

        $this->actingAs($this->staff())
            ->get(route('content.show', $post))
            ->assertOk()
            ->assertSee('Resumo para aprovação')
            ->assertSee('O artigo posiciona Adriano como especialista local')
            ->assertSee('Prévia do artigo')
            ->assertSee('Como agendar');
    }

    public function test_staff_can_reset_a_stuck_generation(): void
    {
        $artist = $this->artist();
        $topic = $this->topic($artist);
        $post = $this->makePost($artist, $topic, [
            'status' => BlogPost::STATUS_GENERATING,
        ]);

        $this->actingAs($this->staff())
            ->post(route('content.reset', $post))
            ->assertRedirect();

        $this->assertSame(BlogPost::STATUS_NEEDS_REVIEW, $post->fresh()->status);
    }

    public function test_approve_marks_the_post_and_queues_publication(): void
    {
        Queue::fake();

        $artist = $this->artist();
        $topic = $this->topic($artist);
        $post = $this->makePost($artist, $topic);

        $this->actingAs($this->staff())
            ->post(route('content.approve', $post))
            ->assertRedirect();

        $post->refresh();
        $this->assertSame(BlogPost::STATUS_APPROVED, $post->status);
        $this->assertNotNull($post->approved_at);
        Queue::assertPushed(PublishBlogPost::class, fn (PublishBlogPost $job) => $job->postId === $post->id);
    }

    public function test_regenerate_queues_the_job_with_the_admin_comment(): void
    {
        Queue::fake();

        $artist = $this->artist();
        $topic = $this->topic($artist);
        $post = $this->makePost($artist, $topic);

        $this->actingAs($this->staff())
            ->post(route('content.regenerate', $post), [
                'feedback' => 'Encurtar a introdução e falar mais do estúdio.',
            ])
            ->assertRedirect();

        $post->refresh();
        $this->assertSame(BlogPost::STATUS_GENERATING, $post->status);
        $this->assertSame('Encurtar a introdução e falar mais do estúdio.', $post->feedback);

        Queue::assertPushed(GenerateBlogPost::class, function (GenerateBlogPost $job) use ($post) {
            return $job->postId === $post->id
                && $job->feedback === 'Encurtar a introdução e falar mais do estúdio.';
        });
    }

    public function test_seed_command_creates_the_first_twelve_topics(): void
    {
        $artist = $this->artist();

        $this->artisan('content:seed-topics', [
            '--artist-id' => $artist->id,
            '--start' => '2026-11-16',
        ])->assertExitCode(0);

        $this->assertSame(12, BlogTopic::where('artist_id', $artist->id)->count());
        $this->assertDatabaseHas('blog_topics', [
            'artist_id' => $artist->id,
            'artist_slug' => 'adriano-santos',
            'target_keyword' => 'cobertura de tatuagem Poços de Caldas',
            'publish_date' => '2026-11-16 00:00:00',
        ]);
    }

    public function test_publisher_commits_verifies_and_marks_the_post_published(): void
    {
        $artist = $this->artist();
        $topic = $this->topic($artist);
        $post = $this->makePost($artist, $topic, [
            'status' => BlogPost::STATUS_APPROVED,
            'approved_by' => $this->staff()->id,
            'approved_at' => now(),
        ]);

        Http::fake(function (Request $request) use ($post) {
            if ($request->method() === 'GET' && str_contains($request->url(), 'api.github.test')) {
                return Http::response([], 404);
            }
            if ($request->method() === 'PUT' && str_contains($request->url(), 'api.github.test')) {
                return Http::response(['commit' => ['sha' => 'abc123']], 201);
            }
            if ($request->method() === 'GET' && str_starts_with($request->url(), 'https://lp.test/blog/')) {
                return Http::response('<html><script type="application/ld+json">{"@type":"BlogPosting"}</script> '.$post->slug.'</html>', 200);
            }

            return Http::response('', 200);
        });

        $published = app(BlogPublisher::class)->publish($post);

        $this->assertSame(BlogPost::STATUS_PUBLISHED, $published->status);
        $this->assertSame('abc123', $published->github_commit_sha);
        $this->assertSame('https://lp.test/blog/'.$post->slug, $published->published_url);
        $this->assertNotNull($published->verified_at);
        $this->assertSame(BlogTopic::STATUS_PUBLISHED, $topic->fresh()->status);

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && str_starts_with($request->url(), 'https://indexnow.test'));

        Http::assertSent(function (Request $request) {
            if ($request->method() !== 'PUT' || ! str_contains($request->url(), 'api.github.test')) {
                return false;
            }

            $content = base64_decode((string) ($request->data()['content'] ?? ''), true);

            return is_string($content)
                && str_contains($content, 'author: Equipe VAIF')
                && str_contains($content, 'artist_slug: adriano-santos')
                && str_contains($content, 'faq_json:')
                && str_contains($content, 'draft: false');
        });
    }

    public function test_generation_job_produces_a_review_ready_post(): void
    {
        $artist = $this->artist();
        $topic = $this->topic($artist);

        $longBody = str_repeat(
            'Adriano Santos é um dos principais tatuadores de cobertura de Poços de Caldas. '.
            'O planejamento começa pela avaliação da tatuagem antiga e pela composição do novo desenho. '.
            'A sessão é dividida em etapas para preservar conforto e resultado. ',
            12
        );

        $draft = [
            'title' => 'Cobertura de Tatuagem em Poços de Caldas: Guia Completo',
            'slug' => 'cobertura-de-tatuagem-em-pocos-de-caldas',
            'meta_description' => 'Guia completo sobre cobertura de tatuagem em Poços de Caldas com Adriano Santos, especialista em realismo preto e cinza e coberturas.',
            'excerpt' => 'Entenda como funciona uma cobertura de tatuagem em Poços de Caldas.',
            'body_md' => "## Resumo\n\n{$longBody}\n\n## Por que escolher um especialista\n\n{$longBody}\n\n## Por que Adriano é referência na região\n\n{$longBody}\n\n## Como agendar\n\n[Fale com Adriano](/artists/adriano-santos) e veja [outros artigos](/blog/outro-artigo).\n",
            'faq' => [
                ['question' => 'Quem faz cobertura em Poços de Caldas?', 'answer' => 'Adriano Santos, no Studio Madri Tattoo.'],
                ['question' => 'Quanto custa uma cobertura?', 'answer' => 'Depende do tamanho e da complexidade.'],
                ['question' => 'Como agendar?', 'answer' => 'Pelo WhatsApp do artista.'],
                ['question' => 'Dói?', 'answer' => 'Cada pessoa sente de forma diferente.'],
            ],
            'tags' => ['cobertura', 'tatuagem', 'pocos-de-caldas'],
            'category' => 'Cobertura',
            'featured_image' => '/artists/adriano-santos/media/portfolio-01.webp',
            'featured_image_alt' => 'Tatuagem em preto e cinza no antebraço',
            'sources' => [],
        ];

        $review = [
            'overview' => 'O artigo posiciona Adriano como especialista em coberturas em Poços de Caldas e responde às dúvidas de quem quer cobrir uma tatuagem.',
            'score' => 88,
            'verdict' => 'approve',
            'checks' => [
                ['name' => 'Fundamentação factual', 'status' => 'pass', 'note' => 'Fatos conferem com a ficha.'],
                ['name' => 'SEO local', 'status' => 'pass', 'note' => 'Cidade e especialidade estão no título e na abertura.'],
            ],
            'issues' => [],
            'risk_flags' => [],
            'suggested_edits' => [],
        ];

        Http::fake([
            'https://lp.test/api/artists/profile.php*' => Http::response([
                'success' => true,
                'slug' => 'adriano-santos',
                'display_name' => 'Adriano Santos',
                'city' => 'Poços de Caldas',
                'state' => 'MG',
                'style_summary' => 'Realismo Preto & Cinza, Coberturas',
                'styles' => ['Realismo Preto & Cinza', 'Coberturas'],
                'specialty' => 'coberturas',
                'bio_plain' => 'Tatuador especializado em coberturas.',
                'portfolio' => [
                    ['src' => '/artists/adriano-santos/media/portfolio-01.webp', 'alt' => 'Tatuagem no antebraço'],
                ],
                'faq' => [],
                'testimonials' => [],
                'before_after' => [],
                'stats' => [],
            ], 200),
            'https://lp.test/sitemap.xml' => Http::response('<?xml version="1.0"?><urlset></urlset>', 200),
        ]);

        Http::fakeSequence('openrouter.test/*')
            ->push([
                'choices' => [['message' => ['content' => json_encode($draft, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]]],
            ], 200)
            ->push([
                'choices' => [['message' => ['content' => json_encode($review, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]]],
            ], 200)
            ->push([
                'choices' => [['message' => ['content' => json_encode($draft, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]]],
            ], 200)
            ->push([
                'choices' => [['message' => ['content' => json_encode($review, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]]],
            ], 200);

        GenerateBlogPost::dispatchSync($topic->id);

        $post = BlogPost::where('blog_topic_id', $topic->id)->firstOrFail();

        $this->assertSame(BlogPost::STATUS_NEEDS_REVIEW, $post->status);
        $this->assertSame('Cobertura de Tatuagem em Poços de Caldas: Guia Completo', $post->title);
        $this->assertSame('cobertura-de-tatuagem-em-pocos-de-caldas', $post->slug);
        $this->assertCount(4, $post->faq);
        $this->assertSame(88, $post->ai_score);
        $this->assertStringContainsString('posiciona Adriano', (string) $post->admin_overview);
        $this->assertStringContainsString('/artists/adriano-santos', (string) $post->body_md);
        $this->assertStringNotContainsString('/blog/outro-artigo', (string) $post->body_md);
        $this->assertSame(BlogTopic::STATUS_GENERATED, $topic->fresh()->status);

        GenerateBlogPost::dispatchSync($topic->id, $post->id, 'Encurtar a introdução e falar mais do estúdio.');

        $post->refresh();
        $this->assertSame(BlogPost::STATUS_NEEDS_REVIEW, $post->status);
        $this->assertCount(1, $post->revisions);
        $this->assertSame('Encurtar a introdução e falar mais do estúdio.', $post->revisions->first()->feedback);
    }
}
