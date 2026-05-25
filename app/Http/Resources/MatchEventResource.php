<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\MatchEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read MatchEvent $resource
 */
final class MatchEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var MatchEvent $e */
        $e = $this->resource;

        return [
            'second' => (int) $e->second,
            'clock' => $e->clock(),
            'minute' => $e->minute,
            'type' => $e->type,
            'team_id' => $e->team_id !== null ? (int) $e->team_id : null,
            'player_id' => $e->player_id !== null ? (int) $e->player_id : null,
            'player_name' => $e->relationLoaded('player') && $e->player !== null ? $e->player->name : null,
            'detail' => $e->detail ?? [],
        ];
    }
}
