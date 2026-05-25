<?php

namespace App\Models;

use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Season extends Model
{
    protected $fillable = [
        'name',
        'rng_seed',
        'is_historical',
        'tenant_id',
        'last_seen_at',
    ];

    protected $casts = [
        'is_historical' => 'boolean',
        'last_seen_at' => 'datetime',
    ];

    public function fixtures(): HasMany
    {
        return $this->hasMany(Fixture::class);
    }

    // Used when no tenant cookie is present (tests, CLI).
    public static function current(): self
    {
        return static::query()
            ->where('is_historical', false)
            ->orderBy('id')
            ->firstOrFail();
    }

    // Per-tenant simulated season. Cloned from the template on first visit.
    public static function forCurrentTenant(): self
    {
        $tenant = app(TenantContext::class);
        if (! $tenant->has()) {
            return self::current();
        }

        $existing = static::query()
            ->where('tenant_id', $tenant->tenantId)
            ->where('is_historical', false)
            ->first();
        if ($existing !== null) {
            if ($existing->last_seen_at === null || $existing->last_seen_at->diffInHours(now()) >= 1) {
                $existing->forceFill(['last_seen_at' => now()])->saveQuietly();
            }

            return $existing;
        }

        $template = static::query()
            ->whereNull('tenant_id')
            ->where('is_historical', false)
            ->orderBy('id')
            ->firstOrFail();

        return static::create([
            'name' => $template->name,
            'rng_seed' => null,
            'is_historical' => false,
            'tenant_id' => $tenant->tenantId,
            'last_seen_at' => now(),
        ]);
    }

    public function scopeSimulated(Builder $query): Builder
    {
        return $query->where('is_historical', false);
    }

    public function scopeHistorical(Builder $query): Builder
    {
        return $query->where('is_historical', true);
    }

    // Own simulated season + shared historicals.
    public function scopeVisibleToCurrentTenant(Builder $query): Builder
    {
        $tenant = app(TenantContext::class);
        if (! $tenant->has()) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($tenant): void {
            $q->where('tenant_id', $tenant->tenantId)
                ->orWhere('is_historical', true);
        });
    }
}
