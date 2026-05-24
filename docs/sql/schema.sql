-- Insider Case — Database Schema
-- MySQL 8.x. Generated to match the Laravel migrations in database/migrations/.
-- Run via: ./vendor/bin/sail artisan migrate (recommended) or apply this file manually.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS=0;

-- ---------------------------------------------------------------------------
-- Seasons: one row per league instance. Every season-scoped table carries
-- a season_id FK so multiple concurrent seasons coexist without conflict.
-- rng_seed is the league-wide RNG seed used to derive per-week sub-seeds for
-- the statistical match engine; it is rotated on every reset.
-- is_historical flags seasons that hold real Premier League match data fed
-- to the prediction model. Historical seasons are read-only and the
-- mutating API endpoints reject any request targeting them.
-- ---------------------------------------------------------------------------
CREATE TABLE `seasons` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `rng_seed` varchar(255) DEFAULT NULL,
  `is_historical` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `seasons_is_historical_index` (`is_historical`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Teams: team-strength catalog. The simulator reads attack / defense /
-- home_advantage; `overall` is a derived display field. `short_name` is the
-- natural key (stable, human-readable, used for upserts during seeding).
-- `external_ref` is a slot for an external identifier if one is ever needed.
-- ---------------------------------------------------------------------------
CREATE TABLE `teams` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `short_name` varchar(8) NOT NULL,
  `attack` smallint unsigned NOT NULL,
  `defense` smallint unsigned NOT NULL,
  `overall` smallint unsigned NOT NULL,
  `home_advantage` decimal(4,3) NOT NULL DEFAULT '1.100',
  `external_ref` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `teams_short_name_unique` (`short_name`),
  KEY `teams_external_ref_index` (`external_ref`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Fixtures: one row per match in the schedule.
--   played       — 0 until simulated/edited, then 1.
--   home_goals / away_goals — NULL until played.
--   simulated_at — when the result was first written.
-- Standings are derived from these rows — they are never cached as columns.
-- ---------------------------------------------------------------------------
CREATE TABLE `fixtures` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `season_id` bigint unsigned NOT NULL,
  `week` tinyint unsigned NOT NULL,
  `home_team_id` bigint unsigned NOT NULL,
  `away_team_id` bigint unsigned NOT NULL,
  `played` tinyint(1) NOT NULL DEFAULT '0',
  `home_goals` smallint unsigned DEFAULT NULL,
  `away_goals` smallint unsigned DEFAULT NULL,
  `simulated_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  -- Each ordered (home, away) pair is unique per season; the second leg of the
  -- double round-robin uses the flipped pair and so does not collide.
  UNIQUE KEY `fixtures_season_id_home_team_id_away_team_id_unique`
    (`season_id`, `home_team_id`, `away_team_id`),
  KEY `fixtures_home_team_id_foreign` (`home_team_id`),
  KEY `fixtures_away_team_id_foreign` (`away_team_id`),
  -- Supports "get all fixtures in week N" used by the next-week and play-all flows.
  KEY `fixtures_season_id_week_index` (`season_id`,`week`),
  -- Supports "find next unplayed week" and "is the season in progress?" queries.
  KEY `fixtures_season_id_played_index` (`season_id`,`played`),
  CONSTRAINT `fixtures_season_id_foreign`     FOREIGN KEY (`season_id`)     REFERENCES `seasons` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fixtures_home_team_id_foreign` FOREIGN KEY (`home_team_id`) REFERENCES `teams`   (`id`) ON DELETE CASCADE,
  CONSTRAINT `fixtures_away_team_id_foreign` FOREIGN KEY (`away_team_id`) REFERENCES `teams`   (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Prediction snapshots: one row per (season, week, team) tuple. Records the
-- team's championship-win probability at the end of each played week — the
-- data feed for the front-end probability-evolution line chart. Re-recorded
-- when results are edited and cleared on season reset.
-- ---------------------------------------------------------------------------
CREATE TABLE `prediction_snapshots` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `season_id` bigint unsigned NOT NULL,
  `week_number` tinyint unsigned NOT NULL,
  `team_id` bigint unsigned NOT NULL,
  `probability` decimal(5,2) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `prediction_snapshots_unique` (`season_id`, `week_number`, `team_id`),
  KEY `prediction_snapshots_season_id_week_number_index` (`season_id`, `week_number`),
  CONSTRAINT `prediction_snapshots_season_id_foreign` FOREIGN KEY (`season_id`) REFERENCES `seasons` (`id`) ON DELETE CASCADE,
  CONSTRAINT `prediction_snapshots_team_id_foreign`   FOREIGN KEY (`team_id`)   REFERENCES `teams`   (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Match commentaries: cached LLM-generated 2-sentence narratives, one per
-- played fixture. Created lazily on the first GET /api/fixtures/{id}/commentary
-- and persisted so subsequent views are instant. The (home_goals, away_goals)
-- columns mirror the fixture's score at generation time; on edit, LeagueService
-- deletes the row so the next view regenerates. ON DELETE CASCADE handles
-- cleanup when fixtures are deleted on reset.
-- ---------------------------------------------------------------------------
CREATE TABLE `match_commentaries` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `fixture_id` bigint unsigned NOT NULL,
  `home_goals` smallint unsigned NOT NULL,
  `away_goals` smallint unsigned NOT NULL,
  `content` text NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `match_commentaries_fixture_unique` (`fixture_id`),
  CONSTRAINT `match_commentaries_fixture_id_foreign`
    FOREIGN KEY (`fixture_id`) REFERENCES `fixtures` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Note on `users`, `cache`, `jobs`, `sessions`, `migrations` tables:
--   These ship with the Laravel scaffold and are unused by this application.
--   They are created by Laravel's default migrations (`0001_01_01_*`) and
--   intentionally not duplicated here — see database/migrations/ for the
--   full set.
-- ---------------------------------------------------------------------------

SET FOREIGN_KEY_CHECKS=1;
