<?php

namespace App\Providers;

use App\Services\Agent\AgentMedia;
use App\Services\Agent\AgentStore;
use App\Services\Agent\AiClient;
use App\Services\Agent\ClaimExtractor;
use App\Services\Agent\ContactGate;
use App\Services\Agent\DatabaseAgentStore;
use App\Services\Agent\DraftComparator;
use App\Services\Agent\DraftProcessor;
use App\Services\Agent\DraftStore;
use App\Services\Agent\OpenRouterClient;
use App\Services\Agent\PreflightClassifier;
use App\Services\Agent\PromptBuilder;
use App\Services\Agent\ReplyComposer;
use App\Services\Agent\ReplyValidator;
use App\Services\Agent\TurnProcessor;
use App\Services\Agent\WahaTransport;
use App\Services\Agent\MessageTransport;
use App\Services\WahaService;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the WhatsApp agent (Beatriz) that used to run inside n8n.
 */
class AgentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AgentStore::class, DatabaseAgentStore::class);
        $this->app->singleton(AiClient::class, OpenRouterClient::class);
        $this->app->singleton(WahaService::class, fn () => WahaService::make());
        $this->app->singleton(MessageTransport::class, WahaTransport::class);
        $this->app->singleton(ContactGate::class);
        $this->app->singleton(AgentMedia::class, fn ($app) => new AgentMedia(
            $app->make(OpenRouterClient::class),
        ));

        $this->app->bind(PreflightClassifier::class, fn ($app) => new PreflightClassifier(
            $app->make(AiClient::class),
            (string) config('agent.models.preflight'),
        ));

        $this->app->bind(ClaimExtractor::class, fn ($app) => new ClaimExtractor(
            $app->make(AiClient::class),
            (string) config('agent.models.claims'),
        ));

        $this->app->singleton(DraftStore::class);

        $this->app->bind(DraftComparator::class, fn ($app) => new DraftComparator(
            $app->make(AiClient::class),
            $app->make(ClaimExtractor::class),
            $app->make(ReplyValidator::class),
            $app->make(AgentStore::class),
        ));

        $this->app->bind(ReplyComposer::class, fn ($app) => new ReplyComposer(
            $app->make(AiClient::class),
            $app->make(PromptBuilder::class),
            $app->make(ClaimExtractor::class),
            $app->make(ReplyValidator::class),
        ));

        $this->app->bind(DraftProcessor::class, fn ($app) => new DraftProcessor(
            $app->make(AgentStore::class),
            $app->make(ReplyComposer::class),
            $app->make(PromptBuilder::class),
            $app->make(PreflightClassifier::class),
            $app->make(DraftStore::class),
            $app->make(AgentMedia::class),
        ));

        $this->app->bind(TurnProcessor::class, fn ($app) => new TurnProcessor(
            $app->make(AgentStore::class),
            $app->make(ReplyComposer::class),
            $app->make(PromptBuilder::class),
            $app->make(PreflightClassifier::class),
            $app->make(MessageTransport::class),
            $app->make(ContactGate::class),
            $app->make(AgentMedia::class),
        ));
    }
}
