<?php

declare(strict_types=1);

namespace App\Domain\Contracts;

interface Rng
{
    /** A pseudo-random float in [0, 1). */
    public function nextFloat(): float;

    /** A pseudo-random integer in [$min, $max] inclusive. */
    public function nextInt(int $min, int $max): int;

    /** A draw from a Poisson distribution with mean $lambda. */
    public function poisson(float $lambda): int;
}
