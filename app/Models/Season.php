<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Season extends Model
{
    protected $fillable = [
        'name',
        'rng_seed',
        'is_historical',
    ];

    protected $casts = [
        'is_historical' => 'boolean',
    ];

    public function fixtures(): HasMany
    {
        return $this->hasMany(Fixture::class);
    }

    /**
     * The default season used when a request does not include `season_id`.
     * Returns the lowest-id simulated row, ignoring historical seasons so the
     * default landing experience never starts on a read-only season.
     */
    public static function current(): self
    {
        return static::query()
            ->where('is_historical', false)
            ->orderBy('id')
            ->firstOrFail();
    }

    public function scopeSimulated(Builder $query): Builder
    {
        return $query->where('is_historical', false);
    }

    public function scopeHistorical(Builder $query): Builder
    {
        return $query->where('is_historical', true);
    }
}
