<?php

namespace App\Providers;

use App\Services\Agent\AgentMedia;
use App\Services\Agent\AgentStore;
use App\Services\Agent\AiClient;
use App\Services\Agent\ClaimExtractor;
use App\Services\Agent\ContactGate;
use App\Services\Agent\DatabaseAgentStore;
use App\Services\Agent\HandoffNotifier;
use App\Services\Agent\OpenRouterClient;
use App\Services\Agent\PreflightClassifier;
use App\Services\Agent\PromptBuilder;
use App\Services\Agent\ReplyValidator;
use App\Services\Agent\TelegramNotifier;
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
        $this->app->singleton(HandoffNotifier::class, TelegramNotifier::class);
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

        $this->app->bind(TurnProcessor::class, fn ($app) => new TurnProcessor(
            $app->make(AgentStore::class),
            $app->make(AiClient::class),
            $app->make(PromptBuilder::class),
            $app->make(PreflightClassifier::class),
            $app->make(ClaimExtractor::class),
            $app->make(ReplyValidator::class),
            $app->make(MessageTransport::class),
            $app->make(HandoffNotifier::class),
            $app->make(ContactGate::class),
            $app->make(AgentMedia::class),
        ));
    }
}
