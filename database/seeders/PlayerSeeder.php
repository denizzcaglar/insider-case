<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Player;
use App\Models\Team;
use Illuminate\Database\Seeder;
use RuntimeException;

class PlayerSeeder extends Seeder
{
    public const SEED_PATH = 'database/data/players.json';

    public function run(): void
    {
        $path = base_path(self::SEED_PATH);

        if (! is_file($path)) {
            throw new RuntimeException("Player seed file not found at {$path}");
        }

        $payload = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        foreach ($payload as $teamPayload) {
            $team = Team::where('external_ref', $teamPayload['external_ref'])->first();
            if ($team === null) {
                throw new RuntimeException("Team not found for external_ref={$teamPayload['external_ref']}. Run TeamSeeder first.");
            }

            Player::where('team_id', $team->id)->delete();

            foreach ($teamPayload['players'] as $p) {
                Player::create([
                    'team_id' => $team->id,
                    'name' => $p['name'],
                    'position' => $p['position'],
                    'pace' => $p['pace'],
                    'shooting' => $p['shooting'],
                    'passing' => $p['passing'],
                    'dribbling' => $p['dribbling'],
                    'defending' => $p['defending'],
                    'physical' => $p['physical'],
                    'overall' => $p['overall'],
                ]);
            }
        }
    }
}
