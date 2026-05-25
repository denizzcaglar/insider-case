# Insider Case - Football League Simulation

**To try it live: https://insider-case-deniz.up.railway.app/
 To run it yourself, see below.

A backend that runs a small fourteam league, Premier League style. It plays the matches,
keeps the table under the real PL rules (points, goal difference, head to head), and predicts
who wins the title by simulating the rest of the season thousands of times. The team strengths
are learned from real Premier League results from the last three seasons.

There are two ways to play a match. Press **Next Week** and a fast engine works out the scores
instantly. Or press **Watch** on one match and a second, tick engine acts it out live
in your browser, event by event.

This README has three parts; how to run it, how it works, and how I built it.

---

## Part 1: How to Run?

### Requirements

- PHP 8.2 or newer
- Composer
- A MySQL 8 database

If you don't want to install MySQL yourself, the project also runs on Laravel Sail (Docker).

### Install

```bash
git clone <repo-url>
cd insider-case
composer install
cp .env.example .env
php artisan key:generate
```

Open `.env` and point the database settings (`DB_HOST`, `DB_PORT`, `DB_DATABASE`,
`DB_USERNAME`, `DB_PASSWORD`) at your local MySQL, then:

```bash
php artisan migrate --seed
```

### About the seeder

The seeder does a bit more than usual. It loads real Premier League results from the public
openfootball/england dataset, but only the matches between our four teams (Manchester City,
Liverpool, Arsenal, Chelsea), for the last three completed seasons. That is 36 real matches.

After seeding you have:

- **1 playable season**, "Insider League 2026/27". This is the one you simulate.
- **3 historical seasons** with real PL results. They are read only, and they feed the prediction model. A middleware blocks any attempt to edit them.
- **4 teams**, each starting at a neutral 80/80 attack/defense. Real strength is learned from
  the historical data, not assigned by hand (see Part 2).
- **44 players**, 11 per club, with attribute ratings used by the live match engine.


Also, for each played match you can ask for a short, AI-written summary. Get a free key from Google AI Studio and add it to `.env`:

```
GEMINI_API_KEY=your-key-here
GEMINI_MODEL=gemini-2.5-flash
```

Without a key, only the commentary endpoint fails. Everything else still works.

### Tests

```bash
php artisan test
```

### Use it

Start the local server with `php artisan serve` (`http://localhost:8000`), or with Sail
(Docker) via `./vendor/bin/sail up -d` (`http://localhost:8080`). To exercise the API by hand,
import the Postman collection from `postman/` and run the Happy Path Runner.

There is also interactive Swagger UI at `/docs` if you want to try the endpoints in the browser.

Main endpoints (all under `/api`):

| Method | Endpoint | Purpose |
| --- | --- | --- |
| `GET` | `/standings` | Current league table |
| `GET` | `/fixtures` | Fixture list with results |
| `GET` | `/predictions` | Monte Carlo title chances |
| `GET` | `/predictions/snapshots` | Week-by-week history for the chart |
| `POST` | `/weeks/next` | Play the next week |
| `POST` | `/weeks/play-all` | Play every remaining week |
| `PATCH` | `/fixtures/{id}` | Edit a result, everything recomputes |
| `POST` | `/league/reset` | Wipe results, rebuild the schedule |
| `GET` | `/fixtures/{id}/watch?speed=60` | Live SSE stream of one match |
| `GET` | `/fixtures/{id}/events` | Saved event list for a watched match |
| `GET` | `/fixtures/{id}/commentary` | AI commentary (needs a Gemini key) |

---

## Part 2: How it works

### Layering

The code is split by responsibility, and the core is kept free of the framework.

- **`app/Domain`** — interfaces and immutable value objects.
- **`app/Services`** — the logic, grouped: Simulation, Prediction, Standings, League, Fixtures, Commentary.
- **`app/Http`** — controllers, requests, resources, middleware.
- **`app/Models`** — models only load and save data

### The patterns I leaned on

- **Strategy** — both engines share the `MatchSimulator` interface, so the rest of the app
  asks for a match to be played and doesn't care which engine does it.
- **Factory** — the tick engine needs a few parts wired together, so `DefaultMatchSimulatorFactory`
  is the one place that builds it.
- **Decorator** — `CachedChampionshipPredictor` wraps the real predictor and adds caching, and
  since it has the same interface nothing else notices.
- **Comparator chain** — `StandingsCalculator` applies the tiebreakers in order, with
  head to head split out into `HeadToHeadResolver`.
- **Value objects** — scores, results, standings rows, events live as immutable types.

### The fast engine and the predictor

**The match model.** For each match the engine works out how many goals each team is expected
to score, from that team's attack, the opponent's defense, and a home bonus. Then it draws the
real scoreline from a **Poisson distribution**, the standard way to model goals in football.
It is random but repeatable: the same starting point always gives the same score, and a match
takes a fraction of a millisecond, which is what makes running the season thousands of times
practical.

**Where strength comes from.** Every team starts equal at 80/80. Real ability is learned in two
layers that multiply together:

- **Long-term ability** comes from the 36 real matches. A well-known football-stats method (the
  **Maher** model) works backwards from the scorelines to estimate each team's true attack and
  defense. Recent matches count more than old ones (**Dixon-Coles time decay**), and since the
  sample is small, each estimate is pulled gently back toward the baseline so one freak result
  can't dominate.
- **Recent form** is a rolling average of the current season that nudges a team up or down. A
  couple of safeguards keep the math sane on edge cases, so a 0-0 or a clean sheet can't make
  the numbers blow up.

**The predictor.** To estimate title chances it just plays the rest of the season out **10,000
times**. Each run simulates every remaining match, builds the final table with the real PL
tiebreakers, and notes who finished top. A team's title chance is how often it came first. The
played part of the table is computed once and reused, and the whole thing is seeded, so the
numbers are reproducible.

### The tick engine (watch a match live)

The fast engine gives a score but no story. The second engine plays one match out moment by
moment, so you can press **Watch** and follow a live feed.

It behaves like a real match. Whoever has the ball picks an action (pass, dribble, or shot),
and how likely each one is depends on where they are on the pitch and on the player's
attributes. Each duel (passer vs defender, striker vs keeper) is decided by comparing the
ratings. The clock moves forward by however long each action takes, so a full match plays out
in a couple of hundred steps and shows around 30 to 50 events: kickoff, shots, saves, goals,
half-time, full-time. To keep it quick, all player data is loaded once before kickoff instead
of being fetched during the match.

It is tuned to look like real football (roughly 2.8 to 3 goals, about 27 shots, around half on
target), and a test checks it hasn't drifted into unrealistic numbers.

Both engines plug into the same interface and save results the same way, so the league table
doesn't care which one produced a score. A watched match's events are saved the first time and
just replayed on a re-watch, streamed over SSE at an adjustable speed. A match already played
by the fast engine can't be watched, because re-running it would change a result that already
counts. And the 10,000-run predictor never uses this engine because it would be far too slow.

### One more note

The league table is never cached. It is always rebuilt from the matches that have been played,
so it can't fall out of sync. Historical and simulated seasons live in the same tables, told
apart by an `is_historical` flag.

---

## Part 3: How I built this

### Reading the case and doing the research

When I read the case first thing I realized that the case needs two fundamental algo:  fixture generation and championship prediction. Prediction is the interesting one, so I went
and read how it's done for real. I worked through Poisson goal models, expected goals (xG), Elo
ratings, the original **Maher (1982)** paper, **Dixon-Coles (1997)** with its low score
correction and time decay, the **Karlis-Ntzoufras** bivariate Poisson, and full ML ensembles.

The research resulted in bigger question; which of these actually fits the problem? This is four
teams playing twelve matches over six weeks, not a 380 match season. A ML model
would have more parameters than data points. Full Dixon-Coles would be fitting things the app
never even shows.

So I picked a deliberate middle ground and could defend every piece:

- **Maher iterative fit** to recover team strengths from scores. It's the foundation of the
  whole Poisson family and still used by serious analytics teams.
- **Dixon-Coles time decay** on top, so recent matches matter more.
- I **skipped the Dixon-Coles low score correction** on purpose. It nudges individual scoreline
  probabilities but doesn't change who wins the title at this scale.

### Arguing the architecture before writing code

Before I let the AI write anything, I spent a lot of time arguing design with it: single
responsibility, where the service boundaries sit, which seams deserve an interface. The decision
I'm happiest with is that **every team starts at a neutral 80/80 and the historical fit does all
the work.** The only value I picked by hand is each team's home advantage, because that's a property
of a stadium, not a signal of team quality. That choice is what made `EffectiveStrengthBuilder`
a clean single source of truth instead of a pile of magic numbers.

### Building in clean stages

1. **Data and the historical seeder.** I loaded the 36 real matches into the same tables as the
   simulated season, flagged them `is_historical`, and put the write guarding middleware in front
   so they can't be edited through the UI or API.
2. **One place that decides team strength.** The historical fit gives long term ability, the
   form tracker adjusts for the current season, and their product is the only strength figure
   the simulator ever sees. This is also where I had to fix two subtle bugs: a feedback loop
   where a team's form started inflating itself, and a 0-0 result that made the form math blow
   up.
3. **Polish.** Gemini match commentary, a "Model Inputs" panel that shows each team's
   seed, prior, form, and effective value so the model is inspectable, and a Chart.js chart of
   how the title chances move week by week.

### A second engine, for the story

The fast engine was well for prediction but does not tell the internal steps, so I added the tick engine behind
the same interface to power the live "watch" experience. The interesting engineering here was
performance. An earlier attempt was slow because it touched Eloquent inside the loop and
allocated objects on every micro action. The current version hoists player data into plain
arrays and only allocates an event object when something actually happens, then I calibrated it
against real PL averages with a fast realism test.

### How I used Claude Code

I used Claude as an assistant, not an author. Especially in UI part cause I am not confident in my frontend skills, I only have school project experiences unlike Backend. But I wanted a good first impression visually, so I used Claude mainly for frontend. Also, I used for backend but I asked/discussed real world approaches and the system design choices I had in my mind. 
