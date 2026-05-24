<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PredictionModelInputsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_predictions_endpoint_returns_model_inputs_for_each_team(): void
    {
        $response = $this->getJson('/api/predictions?iterations=200&seed=model-inputs-test');

        $response->assertOk()
            ->assertJsonCount(4, 'model_inputs')
            ->assertJsonStructure([
                'model_inputs' => [
                    '*' => [
                        'team' => ['id', 'name', 'short_name'],
                        'seed' => ['attack', 'defense'],
                        'prior' => ['attack', 'defense'],
                        'form' => ['attack', 'defense'],
                        'effective' => ['attack', 'defense'],
                    ],
                ],
            ]);
    }

    public function test_priors_differ_from_seed_values_when_historical_data_is_present(): void
    {
        $response = $this->getJson('/api/predictions?iterations=50&seed=priors-shift');

        $rows = $response->json('model_inputs');

        // At least one team's prior should have moved meaningfully from the seed,
        // because the 3 historical seasons of real PL data are not perfectly
        // aligned with the seed strengths.
        $moved = 0;
        foreach ($rows as $r) {
            $deltaA = abs($r['prior']['attack'] - $r['seed']['attack']);
            $deltaD = abs($r['prior']['defense'] - $r['seed']['defense']);
            if ($deltaA > 0.5 || $deltaD > 0.5) {
                $moved++;
            }
        }
        self::assertGreaterThanOrEqual(1, $moved, 'At least one prior should have moved from its seed value.');
    }

    public function test_form_factors_default_to_neutral_when_no_current_matches_played(): void
    {
        $response = $this->getJson('/api/predictions?iterations=50&seed=neutral-form');

        foreach ($response->json('model_inputs') as $r) {
            self::assertEqualsWithDelta(1.0, $r['form']['attack'], 0.001);
            self::assertEqualsWithDelta(1.0, $r['form']['defense'], 0.001);
        }
    }
}
