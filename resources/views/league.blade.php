<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Insider League — 4-Team Simulation</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js" defer></script>
    <style>
        :root {
            --bg: #f4f6fb;
            --surface: #ffffff;
            --border: #e1e6ef;
            --text: #1c2333;
            --muted: #6b7280;
            --primary: #2563eb;
            --primary-hover: #1d4ed8;
            --success: #16a34a;
            --danger: #dc2626;
            --accent: #f59e0b;
            --shadow: 0 1px 3px rgba(0, 0, 0, 0.06), 0 1px 2px rgba(0, 0, 0, 0.04);
            --radius: 8px;
        }

        * { box-sizing: border-box; }
        [hidden] { display: none !important; }

        body {
            margin: 0;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background: var(--bg);
            color: var(--text);
            font-size: 14px;
            line-height: 1.5;
        }

        .wrap {
            max-width: 1180px;
            margin: 0 auto;
            padding: 24px;
        }

        header.top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
            flex-wrap: wrap;
            gap: 12px;
        }

        header.top h1 {
            margin: 0;
            font-size: 22px;
            font-weight: 600;
        }

        .season-meta {
            color: var(--muted);
            font-size: 13px;
        }

        .season-meta strong { color: var(--text); }

        .complete-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 999px;
            background: #d1fae5;
            color: var(--success);
            font-size: 12px;
            font-weight: 500;
            margin-left: 8px;
        }

        .toolbar {
            display: flex;
            gap: 8px;
            margin-bottom: 24px;
            flex-wrap: wrap;
            align-items: center;
        }

        button, .btn {
            border: 1px solid var(--border);
            background: var(--surface);
            color: var(--text);
            padding: 8px 14px;
            border-radius: var(--radius);
            cursor: pointer;
            font: inherit;
            font-weight: 500;
            transition: all 0.15s ease;
        }

        button:hover:not(:disabled) {
            border-color: var(--primary);
            color: var(--primary);
        }

        button:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        button.primary { background: var(--primary); color: white; border-color: var(--primary); }
        button.primary:hover:not(:disabled) { background: var(--primary-hover); border-color: var(--primary-hover); color: white; }
        button.danger:hover:not(:disabled) { border-color: var(--danger); color: var(--danger); }

        input[type="text"], input[type="number"] {
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 7px 10px;
            font: inherit;
            background: var(--surface);
        }

        input[type="number"] { width: 56px; text-align: center; }

        select {
            border: 1px solid var(--border);
            background: var(--surface);
            color: var(--text);
            padding: 8px 32px 8px 12px;
            border-radius: var(--radius);
            font: inherit;
            font-weight: 500;
            cursor: pointer;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%236b7280' stroke-width='2'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 10px center;
        }
        select:hover { border-color: var(--primary); }

        .grid {
            display: grid;
            grid-template-columns: 1fr 1.3fr;
            gap: 24px;
        }

        @media (max-width: 920px) {
            .grid { grid-template-columns: 1fr; }
        }

        .card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 18px;
            box-shadow: var(--shadow);
        }

        .card h2 {
            margin: 0 0 14px 0;
            font-size: 15px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: var(--muted);
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-variant-numeric: tabular-nums;
        }

        th, td {
            text-align: right;
            padding: 8px 6px;
            border-bottom: 1px solid var(--border);
        }

        th { font-weight: 600; color: var(--muted); font-size: 12px; text-transform: uppercase; letter-spacing: 0.04em; }

        th.team, td.team { text-align: left; }
        th.pos, td.pos { width: 28px; color: var(--muted); }
        td.pts { font-weight: 600; }
        tr:last-child td { border-bottom: none; }

        .week-block { margin-bottom: 16px; }
        .week-label {
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: var(--muted);
            margin-bottom: 6px;
        }

        .fixture {
            display: grid;
            grid-template-columns: 1fr 90px 1fr 60px;
            gap: 8px;
            align-items: center;
            padding: 8px 10px;
            border-radius: 6px;
            background: #fafbfd;
            margin-bottom: 4px;
            font-size: 13px;
        }

        .fixture.unplayed { color: var(--muted); }
        .fixture .home { text-align: right; }
        .fixture .score { text-align: center; font-weight: 600; font-variant-numeric: tabular-nums; }
        .fixture .score.placeholder { color: var(--muted); font-weight: 400; }
        .fixture .away { text-align: left; }
        .fixture .actions {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 2px;
            font-size: 11px;
        }
        .fixture .edit-link,
        .fixture .commentary-link {
            color: var(--primary);
            cursor: pointer;
            text-align: right;
            text-decoration: none;
        }
        .fixture .edit-link:hover,
        .fixture .commentary-link:hover { text-decoration: underline; }

        .fixture .editor {
            display: flex;
            gap: 6px;
            align-items: center;
            justify-content: center;
            grid-column: 1 / -1;
            padding-top: 6px;
        }

        .fixture .commentary {
            grid-column: 1 / -1;
            padding: 8px 10px;
            margin-top: 6px;
            background: #f8fafc;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            font-size: 13px;
            line-height: 1.55;
            color: var(--text);
        }
        .fixture .commentary.error { color: var(--danger); background: #fef2f2; border-color: #fecaca; }
        .fixture .commentary.loading { color: var(--muted); font-style: italic; }

        .prediction-row {
            display: grid;
            grid-template-columns: 110px 1fr 50px;
            gap: 10px;
            align-items: center;
            margin-bottom: 8px;
            font-size: 13px;
        }

        .prediction-bar {
            height: 14px;
            background: #eef1f6;
            border-radius: 4px;
            overflow: hidden;
        }

        .prediction-bar > span {
            display: block;
            height: 100%;
            background: var(--primary);
            transition: width 0.3s ease;
        }

        .prediction-pct {
            text-align: right;
            font-variant-numeric: tabular-nums;
            font-weight: 600;
        }

        .empty-state {
            color: var(--muted);
            text-align: center;
            padding: 16px 0;
            font-size: 13px;
        }

        #flash {
            position: fixed;
            bottom: 20px;
            right: 20px;
            padding: 10px 16px;
            border-radius: 6px;
            background: var(--text);
            color: white;
            box-shadow: var(--shadow);
            opacity: 0;
            transition: opacity 0.2s;
            pointer-events: none;
            z-index: 50;
        }
        #flash.show { opacity: 0.95; }
        #flash.error { background: var(--danger); }

        .historical-note {
            display: inline-block;
            margin-top: 4px;
            padding: 2px 8px;
            border-radius: 4px;
            background: #eef2ff;
            color: #4338ca;
            font-size: 12px;
            font-weight: 500;
        }

        .predictions-badge {
            display: inline-block;
            margin-bottom: 10px;
            padding: 3px 9px;
            border-radius: 999px;
            background: #ecfdf5;
            color: #047857;
            font-size: 11px;
            font-weight: 600;
            letter-spacing: 0.02em;
        }

        details.model-inputs {
            margin-top: 14px;
            border-top: 1px solid var(--border);
            padding-top: 10px;
        }
        details.model-inputs > summary {
            cursor: pointer;
            font-size: 12px;
            color: var(--muted);
            font-weight: 500;
            user-select: none;
        }
        details.model-inputs > summary:hover { color: var(--primary); }
        details.model-inputs[open] > summary { color: var(--text); margin-bottom: 10px; }
        .model-inputs table { font-size: 12px; }
        .model-inputs th, .model-inputs td { padding: 5px 4px; }
        .model-inputs td.team { font-weight: 500; }
        .model-inputs .col-sub {
            font-size: 10px;
            color: var(--muted);
            font-weight: 400;
        }
    </style>
</head>
<body>
<div class="wrap">
    <header class="top">
        <div>
            <h1>Insider League <span id="season-name" class="season-meta"></span></h1>
            <div class="season-meta">
                Week <strong id="current-week">—</strong>
                · Played <strong id="played-count">—</strong> / <strong id="total-count">—</strong>
                <span id="complete-badge" class="complete-badge" hidden>Complete</span>
            </div>
            <div id="historical-note" class="historical-note" hidden>
                Real Premier League data · read-only
            </div>
        </div>
        <div class="toolbar">
            <select id="season-picker" title="Switch season" aria-label="Active season"></select>
            <button id="btn-next" class="primary">Next Week</button>
            <button id="btn-play-all">Play All</button>
            <button id="btn-reset" class="danger" title="Clears all results and regenerates fixtures">Reset</button>
        </div>
    </header>

    <div class="grid">
        <div>
            <div class="card">
                <h2>League Table</h2>
                <table>
                    <thead>
                    <tr>
                        <th class="pos">#</th>
                        <th class="team">Team</th>
                        <th>P</th>
                        <th>W</th>
                        <th>D</th>
                        <th>L</th>
                        <th>GF</th>
                        <th>GA</th>
                        <th>GD</th>
                        <th>Pts</th>
                    </tr>
                    </thead>
                    <tbody id="standings-body"></tbody>
                </table>
            </div>

            <div id="predictions-card" class="card" style="margin-top: 16px;" hidden>
                <h2>Championship Predictions</h2>
                <div id="predictions-badge" class="predictions-badge" hidden></div>
                <div id="predictions-body"></div>
                <div class="season-meta" id="predictions-meta" style="margin-top: 10px;"></div>

                <details class="model-inputs" id="model-inputs">
                    <summary>Model inputs (seed · prior · form · effective)</summary>
                    <div id="model-inputs-body"></div>
                </details>
            </div>

            <div id="chart-card" class="card" style="margin-top: 16px;" hidden>
                <h2>Title Probability — Week by Week</h2>
                <div style="position: relative; height: 280px;">
                    <canvas id="probability-chart"></canvas>
                </div>
            </div>
        </div>

        <div class="card">
            <h2>Match Results</h2>
            <div id="fixtures-body">
                <div class="empty-state">Loading…</div>
            </div>
        </div>
    </div>
</div>

<div id="flash"></div>

<script>
(() => {
    const $ = (id) => document.getElementById(id);

    // Active season id; null until the seasons list has loaded once.
    let currentSeasonId = null;
    // Whether the active season is historical (read-only, fed-from-real-data).
    let currentSeasonIsHistorical = false;
    // Cached count of historical seasons for the predictions-panel badge text.
    let historicalSeasonCount = 0;

    const flash = (msg, isError = false) => {
        const el = $('flash');
        el.textContent = msg;
        el.className = 'show' + (isError ? ' error' : '');
        clearTimeout(flash._t);
        flash._t = setTimeout(() => el.className = '', 2500);
    };

    // Append ?season_id=X to GETs and {season_id: X} to POST/PATCH bodies.
    const withSeason = (path) => {
        if (currentSeasonId === null) return path;
        const sep = path.includes('?') ? '&' : '?';
        return `${path}${sep}season_id=${currentSeasonId}`;
    };

    const api = async (method, path, body = null) => {
        const init = { method, headers: { 'Accept': 'application/json' } };
        const targetPath = (method === 'GET') ? withSeason(path) : path;
        if (body !== null || method !== 'GET') {
            init.headers['Content-Type'] = 'application/json';
            const payload = body ?? {};
            if (currentSeasonId !== null && !('season_id' in payload)) {
                payload.season_id = currentSeasonId;
            }
            init.body = JSON.stringify(payload);
        }
        const res = await fetch(targetPath, init);
        if (!res.ok) {
            let detail = res.statusText;
            try { detail = (await res.json()).message || detail; } catch (_) {}
            throw new Error(`${res.status}: ${detail}`);
        }
        return res.json();
    };

    const loadSeasons = async () => {
        const data = await api('GET', '/api/seasons');
        if (currentSeasonId === null) currentSeasonId = data.current_season_id;
        historicalSeasonCount = data.seasons.filter(s => s.is_historical).length;
        const picker = $('season-picker');
        picker.innerHTML = data.seasons.map(s => {
            const label = s.is_historical ? `${s.name} — real data` : s.name;
            return `<option value="${s.id}"${s.id === currentSeasonId ? ' selected' : ''}>${escapeHtml(label)}</option>`;
        }).join('');
    };

    const renderSeason = (s) => {
        currentSeasonIsHistorical = !!s.is_historical;
        $('season-name').textContent = `· ${s.name}`;
        $('current-week').textContent = s.current_week ?? '—';
        $('played-count').textContent = s.fixtures_played;
        $('total-count').textContent = s.fixtures_total;
        $('complete-badge').hidden = !s.is_complete;
        $('historical-note').hidden = !currentSeasonIsHistorical;

        // Mutating buttons hidden entirely for historical seasons.
        $('btn-next').hidden = currentSeasonIsHistorical;
        $('btn-play-all').hidden = currentSeasonIsHistorical;
        $('btn-reset').hidden = currentSeasonIsHistorical;
        $('btn-next').disabled = s.is_complete;
        $('btn-play-all').disabled = s.is_complete;
    };

    const renderStandings = (rows) => {
        const tbody = $('standings-body');
        if (rows.length === 0) {
            tbody.innerHTML = '<tr><td colspan="10" class="empty-state">No standings yet.</td></tr>';
            return;
        }
        tbody.innerHTML = rows.map(r => `
            <tr>
                <td class="pos">${r.position}</td>
                <td class="team">${escapeHtml(r.team.name)}</td>
                <td>${r.played}</td>
                <td>${r.won}</td>
                <td>${r.drawn}</td>
                <td>${r.lost}</td>
                <td>${r.goals_for}</td>
                <td>${r.goals_against}</td>
                <td>${r.goal_difference > 0 ? '+' : ''}${r.goal_difference}</td>
                <td class="pts">${r.points}</td>
            </tr>
        `).join('');
    };

    const renderFixtures = (byWeek) => {
        const container = $('fixtures-body');
        const weeks = Object.keys(byWeek).map(Number).sort((a, b) => a - b);
        if (weeks.length === 0) {
            container.innerHTML = '<div class="empty-state">No fixtures yet. Click <strong>Next Week</strong> to generate the schedule and play the first matches.</div>';
            return;
        }
        container.innerHTML = weeks.map(w => `
            <div class="week-block">
                <div class="week-label">Week ${w}</div>
                ${byWeek[w].map(f => fixtureMarkup(f)).join('')}
            </div>
        `).join('');

        // Wire up edit links
        container.querySelectorAll('[data-edit-id]').forEach(link => {
            link.addEventListener('click', (e) => {
                e.preventDefault();
                openEditor(link.dataset.editId);
            });
        });

        // Wire up commentary links
        container.querySelectorAll('[data-commentary-id]').forEach(link => {
            link.addEventListener('click', (e) => {
                e.preventDefault();
                toggleCommentary(link.dataset.commentaryId, link);
            });
        });
    };

    const toggleCommentary = async (fixtureId, link) => {
        const row = document.getElementById(`fixture-${fixtureId}`);
        const existing = row.querySelector('.commentary');

        if (existing) {
            existing.remove();
            link.textContent = 'Commentary';
            return;
        }

        const block = document.createElement('div');
        block.className = 'commentary loading';
        block.textContent = 'Generating commentary…';
        row.appendChild(block);
        link.textContent = 'Hide commentary';

        try {
            // Don't add ?season_id= here — fixtures are uniquely keyed by id.
            const res = await fetch(`/api/fixtures/${fixtureId}/commentary`, {
                headers: { 'Accept': 'application/json' },
            });
            const body = await res.json().catch(() => ({}));

            if (!res.ok) {
                block.classList.remove('loading');
                block.classList.add('error');
                block.textContent = body.message || `Failed to load commentary (${res.status}).`;
                link.textContent = 'Commentary';
                return;
            }

            block.classList.remove('loading');
            block.textContent = body.commentary || '(empty response)';
        } catch (err) {
            block.classList.remove('loading');
            block.classList.add('error');
            block.textContent = 'Could not reach commentary service.';
            link.textContent = 'Commentary';
        }
    };

    const fixtureMarkup = (f) => {
        const played = f.played;
        const scoreCell = played
            ? `<div class="score">${f.home_goals}-${f.away_goals}</div>`
            : `<div class="score placeholder">vs</div>`;
        // Historical (read-only) fixtures get commentary but no edit link.
        const editLink = currentSeasonIsHistorical
            ? ''
            : `<a href="#" class="edit-link" data-edit-id="${f.id}">edit</a>`;
        const actions = played
            ? `<div class="actions">
                   ${editLink}
                   <a href="#" class="commentary-link" data-commentary-id="${f.id}">Commentary</a>
               </div>`
            : `<span></span>`;
        return `
            <div class="fixture${played ? '' : ' unplayed'}" id="fixture-${f.id}">
                <div class="home">${escapeHtml(f.home_team.name)}</div>
                ${scoreCell}
                <div class="away">${escapeHtml(f.away_team.name)}</div>
                ${actions}
            </div>
        `;
    };

    const openEditor = (fixtureId) => {
        const row = document.getElementById(`fixture-${fixtureId}`);
        const existing = row.querySelector('.editor');
        if (existing) { existing.remove(); return; }

        const editor = document.createElement('div');
        editor.className = 'editor';
        editor.innerHTML = `
            <input type="number" min="0" max="99" id="edit-h-${fixtureId}" placeholder="H">
            -
            <input type="number" min="0" max="99" id="edit-a-${fixtureId}" placeholder="A">
            <button data-save="${fixtureId}" class="primary" style="padding: 4px 10px; font-size: 12px;">Save</button>
            <button data-cancel="${fixtureId}" style="padding: 4px 10px; font-size: 12px;">Cancel</button>
        `;
        row.appendChild(editor);

        editor.querySelector('[data-save]').addEventListener('click', async () => {
            const home = parseInt(document.getElementById(`edit-h-${fixtureId}`).value, 10);
            const away = parseInt(document.getElementById(`edit-a-${fixtureId}`).value, 10);
            if (Number.isNaN(home) || Number.isNaN(away) || home < 0 || away < 0) {
                flash('Goals must be non-negative integers.', true);
                return;
            }
            try {
                await api('PATCH', `/api/fixtures/${fixtureId}`, { home_goals: home, away_goals: away });
                flash('Result updated.');
                await loadAll();
            } catch (e) {
                flash(e.message, true);
            }
        });
        editor.querySelector('[data-cancel]').addEventListener('click', () => editor.remove());
    };

    const renderPredictions = (data) => {
        const card = $('predictions-card');
        if (!data || data.predictions.length === 0) {
            card.hidden = true;
            return;
        }
        card.hidden = false;

        const badge = $('predictions-badge');
        if (historicalSeasonCount > 0) {
            badge.textContent = `Informed by ${historicalSeasonCount} historical Premier League season${historicalSeasonCount === 1 ? '' : 's'}`;
            badge.hidden = false;
        } else {
            badge.hidden = true;
        }

        const max = Math.max(...data.predictions.map(p => p.title_probability)) || 1;
        $('predictions-body').innerHTML = data.predictions.map(p => `
            <div class="prediction-row">
                <div>${escapeHtml(p.team.name)}</div>
                <div class="prediction-bar"><span style="width: ${(p.title_probability / max * 100).toFixed(1)}%"></span></div>
                <div class="prediction-pct">${p.title_probability.toFixed(1)}%</div>
            </div>
        `).join('');
        $('predictions-meta').textContent =
            `${data.iterations.toLocaleString()} simulations · seed: ${data.seed ?? '(random)'}`;

        renderModelInputs(data.model_inputs ?? []);
    };

    const renderModelInputs = (rows) => {
        const details = $('model-inputs');
        const body = $('model-inputs-body');
        if (rows.length === 0) {
            details.hidden = true;
            return;
        }
        details.hidden = false;

        body.innerHTML = `
            <table>
                <thead>
                    <tr>
                        <th class="team">Team</th>
                        <th>Seed <span class="col-sub">(A/D)</span></th>
                        <th>Prior <span class="col-sub">(A/D)</span></th>
                        <th>Form <span class="col-sub">(A/D)</span></th>
                        <th>Effective <span class="col-sub">(A/D)</span></th>
                    </tr>
                </thead>
                <tbody>
                ${rows.map(r => `
                    <tr>
                        <td class="team">${escapeHtml(r.team.short_name)}</td>
                        <td>${fmt(r.seed.attack)} / ${fmt(r.seed.defense)}</td>
                        <td>${fmt(r.prior.attack)} / ${fmt(r.prior.defense)}</td>
                        <td>${fmt(r.form.attack, 2)} / ${fmt(r.form.defense, 2)}</td>
                        <td>${fmt(r.effective.attack)} / ${fmt(r.effective.defense)}</td>
                    </tr>
                `).join('')}
                </tbody>
            </table>
        `;
    };

    const fmt = (n, dp = 1) => (n === undefined || n === null) ? '—' : Number(n).toFixed(dp);

    // Distinct, color-blind-friendly palette for up to 4 teams.
    const TEAM_COLORS = ['#2563eb', '#dc2626', '#16a34a', '#f59e0b'];
    let chartInstance = null;

    const renderChart = (data) => {
        const card = $('chart-card');
        if (!data || data.weeks.length === 0 || typeof Chart === 'undefined') {
            card.hidden = true;
            return;
        }
        card.hidden = false;

        const datasets = data.series.map((s, i) => ({
            label: s.team.short_name,
            data: s.probabilities,
            borderColor: TEAM_COLORS[i % TEAM_COLORS.length],
            backgroundColor: TEAM_COLORS[i % TEAM_COLORS.length] + '22',
            tension: 0.25,
            spanGaps: true,
            pointRadius: 3,
            borderWidth: 2,
        }));

        const config = {
            type: 'line',
            data: { labels: data.weeks.map(w => `W${w}`), datasets },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                scales: {
                    y: {
                        min: 0, max: 100,
                        ticks: { callback: v => v + '%' },
                        grid: { color: 'rgba(0,0,0,0.06)' },
                    },
                    x: { grid: { display: false } },
                },
                plugins: {
                    legend: { position: 'bottom', labels: { boxWidth: 12, usePointStyle: true } },
                    tooltip: {
                        callbacks: {
                            label: ctx => `${ctx.dataset.label}: ${ctx.parsed.y?.toFixed(1) ?? '—'}%`,
                        },
                    },
                },
            },
        };

        if (chartInstance) {
            chartInstance.data = config.data;
            chartInstance.update('none');
        } else {
            chartInstance = new Chart($('probability-chart'), config);
        }
    };

    const loadAll = async () => {
        try {
            const [standings, fixtures, snapshots] = await Promise.all([
                api('GET', '/api/standings'),
                api('GET', '/api/fixtures'),
                api('GET', '/api/predictions/snapshots').catch(() => null),
            ]);
            renderSeason(standings.season);
            renderStandings(standings.standings);
            renderFixtures(fixtures.fixtures_by_week);
            renderChart(snapshots);

            // Historical seasons are the data feeding the model, not a thing to predict;
            // hide the predictions card and the line chart when one is selected.
            if (standings.season.is_historical) {
                $('predictions-card').hidden = true;
                $('chart-card').hidden = true;
            } else if (standings.season.fixtures_played >= 8 && !standings.season.is_complete) {
                try {
                    const preds = await api('GET', '/api/predictions?iterations=10000');
                    renderPredictions(preds);
                } catch (_) {}
            } else if (standings.season.is_complete) {
                try {
                    const preds = await api('GET', '/api/predictions?iterations=1');
                    renderPredictions(preds);
                } catch (_) {}
            } else {
                $('predictions-card').hidden = true;
            }
        } catch (e) {
            flash(e.message, true);
        }
    };

    const escapeHtml = (s) => String(s ?? '').replace(/[&<>"']/g, c => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    }[c]));

    $('btn-next').addEventListener('click', async () => {
        try {
            $('btn-next').disabled = true;
            await api('POST', '/api/weeks/next');
            await loadAll();
        } catch (e) {
            flash(e.message, true);
        } finally {
            $('btn-next').disabled = false;
        }
    });

    $('btn-play-all').addEventListener('click', async () => {
        try {
            $('btn-play-all').disabled = true;
            await api('POST', '/api/weeks/play-all');
            await loadAll();
        } catch (e) {
            flash(e.message, true);
        } finally {
            $('btn-play-all').disabled = false;
        }
    });

    $('btn-reset').addEventListener('click', async () => {
        if (!confirm('Reset the season? All results will be cleared and the schedule regenerated.')) return;
        try {
            $('btn-reset').disabled = true;
            const seed = prompt('Optional RNG seed (leave blank for random):', '') || null;
            await api('POST', '/api/league/reset', seed ? { seed } : {});
            await loadAll();
            flash('Season reset.');
        } catch (e) {
            flash(e.message, true);
        } finally {
            $('btn-reset').disabled = false;
        }
    });

    $('season-picker').addEventListener('change', async (e) => {
        currentSeasonId = parseInt(e.target.value, 10);
        await loadAll();
    });

    (async () => {
        await loadSeasons();
        await loadAll();
    })();
})();
</script>
</body>
</html>
