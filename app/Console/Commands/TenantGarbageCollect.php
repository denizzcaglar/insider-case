<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Season;
use Illuminate\Console\Command;

final class TenantGarbageCollect extends Command
{
    protected $signature = 'tenant:gc {--days=7 : Inactivity threshold in days}';

    protected $description = 'Delete tenant seasons that have not been touched within the inactivity window.';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $threshold = now()->subDays($days);

        $query = Season::query()
            ->whereNotNull('tenant_id')
            ->where('is_historical', false)
            ->where(function ($q) use ($threshold): void {
                $q->where('last_seen_at', '<', $threshold)
                    ->orWhereNull('last_seen_at');
            });

        $deleted = (clone $query)->count();
        $query->delete();

        $this->info("Removed {$deleted} stale tenant seasons (inactive > {$days} days).");

        return self::SUCCESS;
    }
}
