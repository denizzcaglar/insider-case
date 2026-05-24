<?php

declare(strict_types=1);

namespace App\Support;

use App\Domain\Contracts\Rng;
use Random\Engine\Mt19937;
use Random\IntervalBoundary;
use Random\Randomizer;

/**
 * Seedable PRNG implementing the domain {@see Rng} interface.
 *
 * A null seed uses the system entropy source (Mt19937 default constructor); a non-null
 * string seed is hashed to a deterministic 32-bit integer so identical seeds produce
 * identical draw sequences across processes.
 */
final class SeededRng implements Rng
{
    private readonly Randomizer $randomizer;

    public function __construct(public readonly ?string $seed = null)
    {
        $engine = $seed === null
            ? new Mt19937()
            : new Mt19937(self::hashSeed($seed));

        $this->randomizer = new Randomizer($engine);
    }

    public function nextFloat(): float
    {
        return $this->randomizer->getFloat(0.0, 1.0, IntervalBoundary::ClosedOpen);
    }

    public function nextInt(int $min, int $max): int
    {
        return $this->randomizer->getInt($min, $max);
    }

    /**
     * Knuth's Poisson sampler. Fast and accurate for the small lambda range
     * (roughly 0–5) produced by our match model.
     */
    public function poisson(float $lambda): int
    {
        if ($lambda < 0.0) {
            return 0;
        }

        $threshold = exp(-$lambda);
        $k = 0;
        $product = 1.0;

        do {
            $k++;
            $product *= $this->nextFloat();
        } while ($product > $threshold);

        return $k - 1;
    }

    private static function hashSeed(string $seed): int
    {
        // crc32 returns a 32-bit unsigned int; Mt19937 accepts a signed int seed.
        // Reinterpret unsigned -> signed so seeds with the high bit set work on 32-bit PHP.
        $unsigned = crc32($seed);

        return $unsigned > PHP_INT_MAX ? $unsigned - 4294967296 : $unsigned;
    }
}
