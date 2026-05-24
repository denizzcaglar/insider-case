# Insider Case - Football League Simulation

The app plays matches week by week with a Poisson based statistical engine, keeps a Premier League style standings table, and predicts the title winner using a Monte Carlo simulation that also uses real Premier League data from the last three seasons.

The README has two parts: setup for the reviewer, and the story of how I built it.

---

## Part 1: Setup and Running the Project

### Requirements

- PHP 8.2 or newer
- Composer
- A MySQL 8 database
- A working Laravel environment

If you don't want to install MySQL yourself, the project also works with Laravel Sail (Docker).

### Step-by-step Installation

```bash
git clone <repo-url>
cd insider-case
composer install
cp .env.example .env
php artisan key:generate
```

Open the new `.env` file and fill in your database settings (`DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`) so they point to your local MySQL.

### Database Setup and Seeders

```bash
php artisan migrate --seed
```

The seeder is a bit different from a normal Laravel seed. Along with the empty tables, it also loads real Premier League match results from the public openfootball/england dataset. Only the matches between our four teams (Manchester City, Liverpool, Arsenal, Chelsea) are loaded, for the last three completed seasons (2023/24, 2024/25, 2025/26). That is 36 real matches in total.

After seeding you will have:

- 1 simulated season called "Insider League 2026/27". This is the one you actually play.
- 3 historical seasons with real PL match results. Read only, and they feed the prediction model.
- 4 teams with neutral baseline strength values (every team starts at 80/80 on purpose, see Part 2).

### Gemini API Key (For AI Match Commentary)

For each played match you can click "Commentary" and get a short 2 sentence summary written by Google Gemini. To use it, get a free API key from Google AI Studio and add it to your `.env`:

```
GEMINI_API_KEY=your-key-here
GEMINI_MODEL=gemini-2.5-flash
```

The default `gemini-2.5-flash` works on the free tier. The Pro model (`gemini-2.5-pro`) gives slightly better text but it only works with a paid Google Cloud project.

If you don't set a key, only the commentary endpoint will fail. Everything else still works.

### Running the Tests

```bash
php artisan test
```

Tests cover the simulation engine, the prediction algorithm, the historical fitter, the form tracker, the API endpoints, the middleware that protects historical seasons, and the Gemini commentary layer.

### Accessing the App

Start the local server with `php artisan serve` (`http://localhost:8000`), or with Docker via `./vendor/bin/sail up -d` (`http://localhost:8080`).

For testing the API by hand, import the Postman collection from the `postman/` folder and run the "Happy Path Runner". Each step has assertions, so a clean run also works as a smoke test.

---

## Part 2: How I Built This

### 1. Looking at the Case and Doing Research

When I first read the case, I noticed that the case have 2 important algorithms: fixture generation and championship prediction. So I started researching what are real world algorithms and approaches.
I spent some time reading. I tried to understand how real betting companies and sports analytics teams actually predict football match outcomes. I went through Poisson goal models, expected goals (xG), Elo ratings. 

### 2. The Algorithmic Choice (Avoiding Overkill)

The research opened a bigger question. There are very serious models out there. The original Maher 1982 paper, Dixon and Coles 1997 with low score corrections and time decay, Karlis-Ntzoufras bivariate Poisson, full machine learning ensembles...

I had to pick something that fits the size of the problem. The case is 4 teams playing 12 matches over 6 weeks, as it is not a real Premier League season with 380 matches. If I used a deep machine learning model, I would have more parameters than data points. If I used full Dixon-Coles, I would be fitting things the app doesn't even display.

So I picked a middle ground:

- The Maher iterative model as the base for recovering team strengths from match scores. It is the foundation of the whole Poisson football model family and is still used by serious analytics teams today.
- Dixon-Coles style time decay on top, so recent matches matter more than older ones.
- I skipped the Dixon-Coles tau correction. It changes individual scoreline probabilities but not the title race outcome at our scale.

Then, before I let the AI write any code, I spent a lot of time arguing with it about the architecture. I proposed and disscussed my ideas about system design, single responsibility, and where the boundaries between services should live.

One of the biggest argument was about team strength values.
I decided that every team starts at 80/80 and the historical fit does all the work.

Team specific strength comes from real PL match results processed through Dixon-Coles time decay. The only hand picked value left is the per team home advantage, which is a stadium property and not a team quality signal.

### 3. Building It in Clean Stages

**Stage 1: data and the historical seeder.** I wrote the historical seed file with the 36 real PL matches and built the seeder that loads them into the database. Historical seasons share the same `seasons` and `fixtures` tables as the simulated one but they have an `is_historical` flag. A small middleware blocks any write request against them, so a reviewer cannot accidentally edit a real Premier League result through the UI or the API.

**Stage 2: the EffectiveStrengthBuilder as a Single Source of Truth.** 
The builder does three things in order:

1. It runs the historical Maher fit with time decay to get a prior attack and defense per team.
2. It runs a form tracker on the current season's played matches. The form tracker uses exponentially weighted moving average (EWMA) to smooth recent performance into a multiplicative factor around 1.0. I also added Laplace smoothing to the goal counts, because otherwise a 0-0 match would blow up the ratio. A clean sheet would create a 20x outlier and crash the form factor against the clamp limits.
3. It multiplies the prior by the form factor to get the final effective strength.

After this stage, the builder is the only place in the whole app that decides "how strong is each team right now." Nothing reads team attack and defense directly from the database after this point.

**Stage 3: the polish layer.** With the math working, I added the Gemini commentary feature so a played match can show a short narrated summary. I also exposed a "Model Inputs" panel in the UI so a reviewer can click it open and see, for each team:

- The seed value (80/80 for everyone).
- The prior from the historical fit.
- The form factor from the current season.
- The final effective number that the simulator actually used.

The week by week probability chart (Chart.js) is also there, showing how each team's title chance moves as more matches get played.
