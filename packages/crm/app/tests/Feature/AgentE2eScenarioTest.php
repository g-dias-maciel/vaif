<?php

namespace Tests\Feature;

use App\Services\Agent\Eval\ConversationScenario;
use Tests\TestCase;

class AgentE2eScenarioTest extends TestCase
{
    public function test_all_e2e_conversations_are_valid(): void
    {
        $scenarios = ConversationScenario::load(resource_path('agent-e2e'));

        $this->assertNotEmpty($scenarios);

        foreach ($scenarios as $scenario) {
            $turns = $scenario->turns();
            $this->assertNotEmpty($turns, $scenario->name().' has no turns');

            foreach ($turns as $index => $turn) {
                $this->assertNotEmpty(
                    trim((string) ($turn['message'] ?? '')),
                    $scenario->name().' turn '.($index + 1).' has no message',
                );
            }
        }
    }
}
