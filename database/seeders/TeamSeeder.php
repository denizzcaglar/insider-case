<?php

namespace Database\Seeders;

use App\Models\Team;
use Illuminate\Database\Seeder;
use RuntimeException;

class TeamSeeder extends Seeder
{
    public const SEED_PATH = 'database/data/teams.json';

    public function run(): void
    {
        $path = base_path(self::SEED_PATH);

        if (! is_file($path)) {
            throw new RuntimeException("Team seed file not found at {$path}");
        }

        $payload = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        foreach ($payload['teams'] ?? [] as $team) {
            Team::updateOrCreate(
                ['short_name' => $team['short_name']],
                [
                    'name' => $team['name'],
                    'attack' => $team['attack'],
                    'defense' => $team['defense'],
                    'overall' => $team['overall'],
                    'home_advantage' => $team['home_advantage'],
                    'external_ref' => $team['external_ref'] ?? null,
                ],
            );
        }
    }
}
