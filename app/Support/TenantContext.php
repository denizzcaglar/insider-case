<?php

declare(strict_types=1);

namespace App\Support;

final class TenantContext
{
    public function __construct(public readonly ?string $tenantId = null) {}

    public function has(): bool
    {
        return $this->tenantId !== null && $this->tenantId !== '';
    }
}
