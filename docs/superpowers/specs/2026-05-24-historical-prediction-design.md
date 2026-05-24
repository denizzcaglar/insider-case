# Historical-Season-Aware Predictions

## Goal

Predictions for the current Insider League season should be informed by real, recent Premier League results between the same four teams (Manchester City, Liverpool, Arsenal, Chelsea), rather than relying only on static seed strengths and the current season's played fixtures.

The active simulated season is **Insider League 2026/27**. Historical data is the three most recent completed PL seasons (2023/24, 2024/25, 2025/26).

## Architectural invariant: one source of truth for team strength

Both the predictor and `LeagueService::playWeek` read team strengths from `EffectiveStrengthBuilder`. The simulator never reads attack/defense directly off the `Team` model anywhere in the prediction or play path. This guarantees the form tracker and the simulator agree on what "expected goals" means for any given match.

Without this rule, a positive feedback loop forms: the simulator uses the seed values to produce match results, the form tracker measures those results against the (different) prior expectation, the resulting form factor pushes effective strengths further from the seed, and so on until the clamps engage. The fix is structural, not numeric.

## Where the seed values come from

The static seed strengths in `database/data/teams.json` are **neutral**: attack = 80, defense = 80 for every team. All team-level asymmetry comes from the historical fit. The seed exists only as the shrinkage target for teams with sparse or no historical data; under shrinkage toward 80/80 the prior is "uninformative". `home_advantage` is the only per-team static field that retains a hand-picked value, because it tends to be more an intrinsic stadium property than a strength signal.

This is a deliberate choice (Option B in the design conversation). The alternative (hand-picked FIFA-style ratings) double-counts the same signal that the historical fit also measures.

## Approach

A two-layer pipeline that feeds into the existing Poisson match engine without modifying it.

```
real_historical_matches (3 seasons of PL data, JSON-seeded)
        |
        v
HistoricalStrengthFitter
   Maher (1982) iterative attack/defense recovery
 + Dixon-Coles (1997) exponential time decay
 + shrinkage toward static seed prior
        |
        v
prior_attack, prior_defense per team
        |
        v
CurrentFormTracker
   EWMA over the current season's played fixtures
        |
        v
form_attack, form_defense per team (multiplicative factors around 1.0)
        |
        v
EffectiveStrengthBuilder
   effective_attack = prior_attack * form_attack
   effective_defense = prior_defense * form_defense
        |
        v
MonteCarloChampionshipPredictor (unchanged)
   uses StatisticalMatchSimulator (unchanged Poisson math)
```

Every arrow is a single-purpose service. The simulator itself does not know that history exists.

## Rationale

Three approaches were considered:

1. Display-only side panel (no algorithm change). Rejected: does not meet the stated goal of historical influence.
2. Bayesian prior with conjugate updates. Rejected: overkill for a four-team league with three historical seasons; the additional math gives no measurable lift at this scale.
3. Two-layer pipeline (chosen). Maps cleanly onto the existing Poisson architecture, separates the long-term baseline from the short-term form, and adds no new abstractions at the simulator level.

Within the Poisson family, the realistic algorithm choices were:

- Maher (1982) iterative fit. Foundational but does not weight by recency.
- Maher with Dixon-Coles time decay (chosen). Adds the one Dixon-Coles improvement that meaningfully helps multi-season fitting.
- Full Dixon-Coles with tau correction. The tau term primarily improves exact-score predictions, which the app does not display. Skipped to keep the parameter count down.
- xG-based models. Require shot-level data that the free dataset does not include.

## Data source

Real Premier League match results from the [openfootball/england](https://github.com/openfootball/england) public dataset. Three seasons (2022/23, 2023/24, 2024/25) filtered to matches where both home and away teams are in `{ARS, CHE, LIV, MCI}`. That yields exactly 12 matches per season, matching the structural shape of one current season.

The extracted data is committed to `database/data/historical_matches.json` so the build is deterministic and no runtime network calls are needed.

## File inventory

### New files

| File | Purpose |
| :--- | :--- |
| `database/data/historical_matches.json` | 3 seasons of selected real PL match results |
| `database/migrations/2026_05_24_*_add_is_historical_to_seasons_table.php` | Adds `is_historical` boolean to `seasons` |
| `database/seeders/HistoricalSeasonSeeder.php` | Loads JSON into `seasons` and `fixtures` |
| `app/Services/Prediction/HistoricalStrengthFitter.php` | Maher iterative fit, time-decay weighted, shrunk to seed |
| `app/Services/Prediction/CurrentFormTracker.php` | EWMA form factors per team |
| `app/Services/Prediction/EffectiveStrengthBuilder.php` | Composes fitter and tracker, returns `TeamStrength[]` and a per-team model-inputs map |
| `app/Domain/ValueObjects/StrengthBreakdown.php` | Per-team prior and form numbers, surfaced via the API |
| `app/Http/Middleware/RejectHistoricalSeasonMutation.php` | Returns 422 on state-changing endpoints when the season is historical |
| `tests/Unit/Services/Prediction/HistoricalStrengthFitterTest.php` | Convergence, edge cases (kappa=0, kappa large, single historical match) |
| `tests/Unit/Services/Prediction/CurrentFormTrackerTest.php` | Empty current season returns 1.0; EWMA behaviour over multiple matches |
| `tests/Unit/Services/Prediction/EffectiveStrengthBuilderTest.php` | Composition correctness |
| `tests/Feature/Api/HistoricalSeasonReadOnlyTest.php` | All mutating endpoints return 422 on historical seasons |
| `tests/Feature/Api/PredictionModelInputsTest.php` | `GET /api/predictions` returns `model_inputs` per team |

### Modified files

| File | Change |
| :--- | :--- |
| `app/Services/Prediction/MonteCarloChampionshipPredictor.php` | Takes `EffectiveStrengthBuilder` instead of building `TeamStrength` from `Team` directly |
| `app/Services/League/LeagueService.php` | `playWeek` also routes through `EffectiveStrengthBuilder` so the simulator never sees raw `Team` attack/defense (one source of truth) |
| `database/data/teams.json` | All `attack`/`defense`/`overall` set to 80 (neutral seed). Season name updated to "Insider League 2026/27" |
| `app/Domain/ValueObjects/PredictionResult.php` | Gains `modelInputs` array (one `StrengthBreakdown` per team) |
| `app/Services/Prediction/PredictionCacheStore.php` | Cache key includes `historical_hash`; new `historicalSeasonsHash()` helper |
| `app/Providers/DomainServiceProvider.php` | Wires the new builder into the predictor |
| `app/Models/Season.php` | `is_historical` cast; `current()` skips historical rows; new `scopeSimulated()` / `scopeHistorical()` |
| `database/seeders/SeasonSeeder.php` | Drops the placeholder "Insider League 2024/25" |
| `database/seeders/DatabaseSeeder.php` | Invokes `HistoricalSeasonSeeder` after `TeamSeeder` and `SeasonSeeder` |
| `routes/api.php` | Attaches `RejectHistoricalSeasonMutation` middleware to mutating routes |
| `app/Http/Controllers/Api/PredictionController.php` | Returns the new `model_inputs` block in the JSON response |
| `app/Http/Controllers/Api/SeasonsController.php` | Includes `is_historical` in the season list |
| `resources/views/league.blade.php` | Badge on predictions panel; expandable Model Inputs panel; "(real data)" label on historical season options; hides mutating action buttons when a historical season is selected |
| `README.md` | New "Historical influence" section |

## Algorithm details

### HistoricalStrengthFitter

For each team `i` we want to recover `attack_i` and `defense_i` such that the historical match scores are consistent with a Poisson model:

```
expected_goals(home_i, away_j) = base_lambda
                               * (attack_i / avg_attack)
                               * (avg_defense / defense_j)
                               * home_advantage_i
```

Iterative recovery:

```
init attack_i = avg_attack, defense_i = avg_defense for each team i

for each match m with weight w_m = exp(-xi * days_since(m)):
  compute the time-decay-weighted sum of goals scored and conceded per team,
  normalised by the opponent's current strength and the home factor.

repeat for `convergenceIterations`:
  attack_i  = sum_m w_m * (goals_for_im   / norm_def_opp / hf_m) / sum_m w_m
  defense_i = sum_m w_m * (goals_against_im / norm_att_opp / hf_m) / sum_m w_m
```

Shrinkage toward the seed prior, after convergence:

```
n_eff_i = sum_m w_m over team_i's historical matches

attack_i_final  = (n_eff_i * attack_i  + kappa * seed_attack_i)  / (n_eff_i + kappa)
defense_i_final = (n_eff_i * defense_i + kappa * seed_defense_i) / (n_eff_i + kappa)
```

With no historical data, `n_eff = 0` and the result equals the seed prior exactly, which is the desired behaviour.

Constructor defaults:
- `shrinkageKappa = 12.0`
- `timeDecayXi = 0.0019` (per day, half-life ~1 year)
- `convergenceIterations = 8`

Because the seeds are neutral (80/80), shrinkage now pulls toward the league average. A team with no historical data gets exactly 80/80.

### CurrentFormTracker

For each team, multiplicative form factors around 1.0, updated after each played fixture in chronological order:

```
# Expected goals come from EFFECTIVE strengths (prior * current form),
# matching what the simulator just used to produce this match. Comparing
# against prior alone would create a positive feedback loop.
eff_attack_i  = prior_attack_i  * form_attack_i
eff_defense_i = prior_defense_i * form_defense_i

expected_GF_m = eff_attack_i / avg_attack
              * avg_defense / eff_defense_opp
              * home_advantage_i
              * base_lambda

# Additive Laplace smoothing keeps a clean sheet from blowing up the ratio.
ratio_attack_m  = (observed_GF_m + 1) / (expected_GF_m + 1)
ratio_defense_m = (expected_GA_m + 1) / (observed_GA_m + 1)

form_attack_i  = clamp(alpha * ratio_attack_m  + (1 - alpha) * form_attack_i,  [0.5, 1.8])
form_defense_i = clamp(alpha * ratio_defense_m + (1 - alpha) * form_defense_i, [0.5, 1.8])
```

Starts at 1.0 for both. Returns 1.0 for any team with no played matches in the current season. The clamp is applied per update (not just at the end) so unclamped intermediate values never leak into the next iteration's expected-goals calculation.

Constructor default: `ewmaAlpha = 0.25`. Class constant: `LAPLACE_LAMBDA = 1.0`.

### EffectiveStrengthBuilder

```
prior = HistoricalStrengthFitter.fit(historical_matches, seed_strengths)
form  = CurrentFormTracker.track(current_played_fixtures, prior)

for each team i:
  effective_attack_i  = prior_attack_i  * form_attack_i
  effective_defense_i = prior_defense_i * form_defense_i

  TeamStrength { attack: round(effective_attack_i),
                 defense: round(effective_defense_i),
                 home_advantage: seed_home_advantage_i }

  StrengthBreakdown {
    team_id, name, short_name,
    seed_attack, seed_defense,
    prior_attack, prior_defense,
    form_attack, form_defense,
    effective_attack, effective_defense,
  }
```

The integer rounding for `TeamStrength` preserves the existing value-object shape (attack/defense are typed `int` today and are consumed by `StatisticalMatchSimulator` as integers). Detailed floats are kept on `StrengthBreakdown` for the model-inputs view.

## Cache invalidation

The prediction cache key gains one component:

```
predictions:{season_id}:{played_fixtures_hash}:{historical_hash}:{iterations}:{seed}
```

`historical_hash` is `md5` of `(season_id, fixture_id, home_goals, away_goals)` across all historical seasons, ordered. Because historical seasons are read-only at runtime, this hash is effectively constant during normal operation. Its inclusion is defensive: it prevents cross-seeding contamination if the cache store survives a `migrate:fresh --seed` (e.g. the redis driver in a long-lived dev container).

No bust cascade is required, because historical seasons cannot be mutated via the API.

## Read-only enforcement

A small middleware (`RejectHistoricalSeasonMutation`) resolves the target season the same way `ResolvesSeason` does, then returns a 422 JSON error if the season is historical. Attached to:

- `POST /api/weeks/next`
- `POST /api/weeks/play-all`
- `POST /api/league/reset`
- `PATCH /api/fixtures/{fixture}` (special case: resolves the season via the fixture's `season_id`)

Read endpoints (`GET /standings`, `GET /fixtures`, `GET /predictions`, `GET /fixtures/{id}/commentary`) remain unrestricted, so reviewers can browse historical data freely.

## API contract changes

`GET /api/predictions` response gains a `model_inputs` array, one entry per team:

```json
{
  "season": { ... },
  "iterations": 10000,
  "seed": "...",
  "predictions": [ ... ],
  "model_inputs": [
    {
      "team": { "id": 1, "name": "Manchester City", "short_name": "MCI" },
      "seed":      { "attack": 86, "defense": 82 },
      "prior":     { "attack": 84.3, "defense": 80.6 },
      "form":      { "attack": 1.05, "defense": 0.97 },
      "effective": { "attack": 88.5, "defense": 78.2 }
    },
    ...
  ]
}
```

`GET /api/seasons` adds an `is_historical` boolean to each entry.

## UI changes

`resources/views/league.blade.php`:

1. Season picker: historical seasons rendered as `<option>Premier League 2024/25 — real data</option>`. A small caption shows under the title when a historical season is selected: "Real Premier League data. Read-only."
2. Action buttons (Next Week, Play All, Reset, Edit Result) are hidden via JS when the selected season's `is_historical` is true.
3. Predictions panel gains a small badge `Informed by 3 historical Premier League seasons` when a non-historical season has predictions, plus an expandable "Model Inputs" panel that renders the `model_inputs` array as a table:

   | Team | Seed (A/D) | Prior (A/D) | Form (A/D) | Effective (A/D) |
   | :--- | :--- | :--- | :--- | :--- |
   | MCI | 86 / 82 | 84.3 / 80.6 | 1.05 / 0.97 | 88.5 / 78.2 |

## Tunable parameters

All three live as constructor defaults on the relevant service, following the pattern already used by `StatisticalMatchSimulator`. No config file, no environment variable. Tests inject overrides via the constructor.

```
HistoricalStrengthFitter::__construct(
    float $shrinkageKappa = 12.0,
    float $timeDecayXi = 0.0019,
    int $convergenceIterations = 8,
)

CurrentFormTracker::__construct(
    float $ewmaAlpha = 0.35,
)
```

## Determinism

Predictions remain deterministic for a fixed `?seed=`. The fitting layer is deterministic by construction (no RNG). The form tracker is deterministic by construction. The simulator's existing `SeededRng` continues to drive randomness.

## Backwards compatibility

- `GET /api/predictions` response shape only grows; existing fields are unchanged.
- `GET /api/standings`, `GET /api/fixtures` are unchanged.
- `PredictionResult` value object grows one field (`modelInputs`).
- The placeholder "Insider League 2024/25" season is removed from the seeder. On `migrate:fresh --seed` the user gets one simulated season plus three historical seasons.
- Existing tests that target the placeholder season will be deleted or rewritten.

## Out of scope

- Dixon-Coles tau correction for low-scoring match correlation.
- xG-based attack/defense estimation.
- Runtime configuration of the three model parameters.
- A migration path that preserves the placeholder "Insider League 2024/25" while introducing historical seasons. The seeder is rewritten in place.
- An admin UI for managing historical seasons. They are seed-only data.
