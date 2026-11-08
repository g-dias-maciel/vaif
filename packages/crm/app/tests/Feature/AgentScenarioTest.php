<?php

namespace Tests\Feature;

use App\Services\Agent\Eval\Evaluator;
use App\Services\Agent\Eval\Scenario;
use App\Services\Agent\Eval\ScriptedAiClient;
use Tests\TestCase;

/**
 * The fake cases are the regression suite for the grounded pipeline: the real
 * prompt, validator, repair loop and send path run against scripted models.
 */
class AgentScenarioTest extends TestCase
{
    public function test_all_scripted_scenarios_pass(): void
    {
        $scenarios = Scenario::load(base_path('tests/Agent/Scenarios'));

        $this->assertNotEmpty($scenarios);

        foreach ($scenarios as $scenario) {
            $client = new ScriptedAiClient($scenario->script());
            $report = (new Evaluator($client, 'scripted'))->run($scenario);

            $this->assertTrue(
                $report['pass'],
                $scenario->name().': '.implode(' | ', $report['failures'] ?? [])
            );
        }
    }

    public function test_a_valid_price_is_sent_untouched(): void
    {
        $scenario = Scenario::fromFile(base_path('tests/Agent/Scenarios/04-valid-price.json'));
        $report = (new Evaluator(new ScriptedAiClient($scenario->script())))->run($scenario);

        $this->assertTrue($report['pass']);
        $this->assertFalse($report['repaired']);
        $this->assertSame('Fica R$ 600,00 à vista. Quer ver os horários disponíveis?', $report['sent'][0]);
    }

    public function test_cover_up_price_never_reaches_the_lead(): void
    {
        $scenario = Scenario::fromFile(base_path('tests/Agent/Scenarios/02-cover-up-never-quotes.json'));
        $report = (new Evaluator(new ScriptedAiClient($scenario->script())))->run($scenario);

        $this->assertTrue($report['pass']);
        $this->assertStringNotContainsString('R$', $report['sent'][0]);
        $this->assertStringContainsString('avaliar', $report['sent'][0]);
    }
}
