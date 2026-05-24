<?php

namespace Database\Seeders;

use App\Models\Fixture;
use App\Models\Season;
use App\Models\Team;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Loads real Premier League match results between our four teams into the
 * `seasons` and `fixtures` tables as read-only historical seasons.
 *
 * Source data: openfootball/england public dataset, filtered to matches where
 * both home and away are in {ARS, CHE, LIV, MCI}. Three seasons = 36 matches.
 */
class HistoricalSeasonSeeder extends Seeder
{
    public const SEED_PATH = 'database/data/historical_matches.json';

    public function run(): void
    {
        $path = base_path(self::SEED_PATH);

        if (! is_file($path)) {
            throw new RuntimeException("Historical seed file not found at {$path}");
        }

        $payload = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        $teamsByShort = Team::all()->keyBy('short_name');

        foreach ($payload['seasons'] ?? [] as $seasonRow) {
            $season = Season::firstOrCreate(
                ['name' => $seasonRow['name']],
                ['is_historical' => true, 'rng_seed' => null],
            );

            if (! $season->is_historical) {
                $season->update(['is_historical' => true]);
            }

            if (Fixture::where('season_id', $season->id)->exists()) {
                continue;
            }

            foreach ($seasonRow['matches'] as $match) {
                $home = $teamsByShort->get($match['home']);
                $away = $teamsByShort->get($match['away']);

                if (! $home || ! $away) {
                    throw new RuntimeException(
                        "Unknown team short code in historical data: {$match['home']} or {$match['away']}"
                    );
                }

                Fixture::create([
                    'season_id' => $season->id,
                    'week' => $match['week'],
                    'home_team_id' => $home->id,
                    'away_team_id' => $away->id,
                    'played' => true,
                    'home_goals' => $match['home_goals'],
                    'away_goals' => $match['away_goals'],
                    'simulated_at' => Carbon::parse($match['date']),
                ]);
            }
        }
    }
}
