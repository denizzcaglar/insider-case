<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\SeededRng;
use PHPUnit\Framework\TestCase;

final class SeededRngTest extends TestCase
{
    public function test_same_seed_produces_identical_sequence(): void
    {
        $a = new SeededRng('insider-case');
        $b = new SeededRng('insider-case');

        for ($i = 0; $i < 100; $i++) {
            self::assertSame($a->nextFloat(), $b->nextFloat());
        }
    }

    public function test_different_seeds_diverge(): void
    {
        $a = new SeededRng('seed-a');
        $b = new SeededRng('seed-b');

        $diverged = false;
        for ($i = 0; $i < 50; $i++) {
            if ($a->nextFloat() !== $b->nextFloat()) {
                $diverged = true;
                break;
            }
        }

        self::assertTrue($diverged, 'Different seeds should produce different sequences within 50 draws.');
    }

    public function test_next_float_stays_in_unit_interval(): void
    {
        $rng = new SeededRng('range');

        for ($i = 0; $i < 1000; $i++) {
            $f = $rng->nextFloat();
            self::assertGreaterThanOrEqual(0.0, $f);
            self::assertLessThan(1.0, $f);
        }
    }

    public function test_next_int_respects_bounds(): void
    {
        $rng = new SeededRng('intbounds');

        for ($i = 0; $i < 1000; $i++) {
            $n = $rng->nextInt(5, 10);
            self::assertGreaterThanOrEqual(5, $n);
            self::assertLessThanOrEqual(10, $n);
        }
    }

    public function test_poisson_mean_approximates_lambda(): void
    {
        $rng = new SeededRng('poisson');
        $lambda = 1.5;
        $samples = 5000;
        $sum = 0;

        for ($i = 0; $i < $samples; $i++) {
            $sum += $rng->poisson($lambda);
        }

        $mean = $sum / $samples;
        // Allow a generous tolerance because of finite-sample noise.
        self::assertEqualsWithDelta($lambda, $mean, 0.10, "Empirical mean {$mean} should approximate lambda {$lambda}.");
    }

    public function test_poisson_returns_zero_for_zero_lambda(): void
    {
        $rng = new SeededRng('zero');

        for ($i = 0; $i < 50; $i++) {
            self::assertSame(0, $rng->poisson(0.0));
        }
    }

    public function test_poisson_returns_non_negative(): void
    {
        $rng = new SeededRng('nonneg');

        for ($i = 0; $i < 200; $i++) {
            self::assertGreaterThanOrEqual(0, $rng->poisson(2.0));
        }
    }
}
