-- Insider Case — Representative Queries
--
-- These illustrate how the same data the API serves can be retrieved
-- directly from SQL. The Laravel app computes standings in PHP because the
-- Monte Carlo predictor needs to recompute them across thousands of
-- simulated tables in-memory — but the equivalent SQL is here for reference.
--
-- All queries assume a single active season; replace :season_id (1 by default).

-- ---------------------------------------------------------------------------
-- 1. League standings with Premier-League tiebreakers
--    Aggregates W/D/L/GF/GA from played fixtures, sorts by:
--    points DESC, goal difference DESC, goals_for DESC, team name ASC.
--    The application layer applies the full PL tiebreaker chain including
--    head-to-head (points / GD / GF between the two tied teams). That part
--    is computed in PHP because it requires comparing pairs of rows, which
--    is awkward to express in a single ORDER BY.
-- ---------------------------------------------------------------------------
WITH played AS (
    SELECT
        t.id           AS team_id,
        t.name         AS team_name,
        t.short_name   AS short_name,
        SUM(home.appearances + away.appearances) AS played_matches,
        SUM(home.wins  + away.wins)              AS won,
        SUM(home.draws + away.draws)             AS drawn,
        SUM(home.losses + away.losses)           AS lost,
        SUM(home.goals_for + away.goals_for)     AS goals_for,
        SUM(home.goals_against + away.goals_against) AS goals_against
    FROM teams t
    LEFT JOIN LATERAL (
        SELECT
            COUNT(*) AS appearances,
            SUM(CASE WHEN f.home_goals > f.away_goals THEN 1 ELSE 0 END) AS wins,
            SUM(CASE WHEN f.home_goals = f.away_goals THEN 1 ELSE 0 END) AS draws,
            SUM(CASE WHEN f.home_goals < f.away_goals THEN 1 ELSE 0 END) AS losses,
            COALESCE(SUM(f.home_goals), 0) AS goals_for,
            COALESCE(SUM(f.away_goals), 0) AS goals_against
        FROM fixtures f
        WHERE f.season_id = :season_id
          AND f.played = 1
          AND f.home_team_id = t.id
    ) AS home ON TRUE
    LEFT JOIN LATERAL (
        SELECT
            COUNT(*) AS appearances,
            SUM(CASE WHEN f.away_goals > f.home_goals THEN 1 ELSE 0 END) AS wins,
            SUM(CASE WHEN f.away_goals = f.home_goals THEN 1 ELSE 0 END) AS draws,
            SUM(CASE WHEN f.away_goals < f.home_goals THEN 1 ELSE 0 END) AS losses,
            COALESCE(SUM(f.away_goals), 0) AS goals_for,
            COALESCE(SUM(f.home_goals), 0) AS goals_against
        FROM fixtures f
        WHERE f.season_id = :season_id
          AND f.played = 1
          AND f.away_team_id = t.id
    ) AS away ON TRUE
    GROUP BY t.id, t.name, t.short_name
)
SELECT
    team_name,
    short_name,
    played_matches AS P,
    won            AS W,
    drawn          AS D,
    lost           AS L,
    goals_for      AS GF,
    goals_against  AS GA,
    (goals_for - goals_against) AS GD,
    (won * 3 + drawn) AS points
FROM played
ORDER BY points DESC, GD DESC, GF DESC, team_name ASC;

-- ---------------------------------------------------------------------------
-- 2. Remaining fixtures for the Monte Carlo predictor
-- ---------------------------------------------------------------------------
SELECT
    f.id,
    f.week,
    home.name AS home_team,
    away.name AS away_team
FROM fixtures f
JOIN teams home ON home.id = f.home_team_id
JOIN teams away ON away.id = f.away_team_id
WHERE f.season_id = :season_id
  AND f.played = 0
ORDER BY f.week, f.id;

-- ---------------------------------------------------------------------------
-- 3. Played results grouped by week (drives the Match Results panel)
-- ---------------------------------------------------------------------------
SELECT
    f.week,
    f.id AS fixture_id,
    home.name AS home_team,
    f.home_goals,
    f.away_goals,
    away.name AS away_team,
    f.simulated_at
FROM fixtures f
JOIN teams home ON home.id = f.home_team_id
JOIN teams away ON away.id = f.away_team_id
WHERE f.season_id = :season_id
  AND f.played = 1
ORDER BY f.week, f.id;

-- ---------------------------------------------------------------------------
-- 4. Head-to-head between two teams (both legs of the season)
--    Uses :team_a and :team_b (team ids).
-- ---------------------------------------------------------------------------
SELECT
    f.week,
    home.name AS home_team,
    f.home_goals,
    f.away_goals,
    away.name AS away_team,
    f.played
FROM fixtures f
JOIN teams home ON home.id = f.home_team_id
JOIN teams away ON away.id = f.away_team_id
WHERE f.season_id = :season_id
  AND (
        (f.home_team_id = :team_a AND f.away_team_id = :team_b)
     OR (f.home_team_id = :team_b AND f.away_team_id = :team_a)
  )
ORDER BY f.week;

-- ---------------------------------------------------------------------------
-- 5. Highest-scoring fixture of the season (display flavour)
-- ---------------------------------------------------------------------------
SELECT
    f.week,
    home.name AS home_team,
    f.home_goals,
    f.away_goals,
    away.name AS away_team,
    (f.home_goals + f.away_goals) AS total_goals
FROM fixtures f
JOIN teams home ON home.id = f.home_team_id
JOIN teams away ON away.id = f.away_team_id
WHERE f.season_id = :season_id
  AND f.played = 1
ORDER BY total_goals DESC, f.week ASC
LIMIT 5;

-- ---------------------------------------------------------------------------
-- 6. Current week — the lowest week number that still has any unplayed match
--    (NULL when the season is complete; matches LeagueService::currentWeek).
-- ---------------------------------------------------------------------------
SELECT MIN(week) AS current_week
FROM fixtures
WHERE season_id = :season_id AND played = 0;
