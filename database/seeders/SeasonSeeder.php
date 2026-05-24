<?php

namespace Database\Seeders;

use App\Models\Season;
use Illuminate\Database\Seeder;
use RuntimeException;

class SeasonSeeder extends Seeder
{
    public function run(): void
    {
        $path = base_path(TeamSeeder::SEED_PATH);

        if (! is_file($path)) {
            throw new RuntimeException("Team seed file not found at {$path}");
        }

        $payload = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        $primaryName = $payload['season']['name'] ?? 'Insider League';
        Season::firstOrCreate(['name' => $primaryName], ['is_historical' => false]);
    }
}
