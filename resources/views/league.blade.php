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
        .fixture .watch-link {
            font-size: 12px;
            color: var(--primary);
            text-decoration: none;
            cursor: pointer;
        }
        .fixture .watch-link:hover { text-decoration: underline; }

        /* Watch-match modal */
        .watch-overlay {
            position: fixed; inset: 0;
            background: rgba(0, 0, 0, 0.65);
            display: none;
            align-items: center; justify-content: center;
            z-index: 1000;
        }
        .watch-overlay.open { display: flex; }
        .watch-modal {
            background: var(--bg);
            border-radius: var(--radius);
            box-shadow: 0 24px 72px rgba(0, 0, 0, 0.35);
            width: min(94vw, 820px);
            max-height: 92vh;
            display: flex; flex-direction: column;
            overflow: hidden;
        }
        .watch-header {
            display: grid;
            grid-template-columns: 1fr auto 1fr auto;
            gap: 16px;
            align-items: center;
            padding: 16px 22px;
            border-bottom: 1px solid var(--border);
            background: var(--card);
        }
        .watch-header .team {
            display: flex; align-items: center; gap: 10px;
            font-size: 16px; font-weight: 600;
        }
        .watch-header .team.home { justify-content: flex-end; }
        .watch-header .team.away { justify-content: flex-start; }
        .watch-header .team img { width: 28px; height: 28px; object-fit: contain; }
        .watch-header .score {
            font-size: 28px; font-weight: 700;
            font-variant-numeric: tabular-nums;
            padding: 0 18px;
        }
        .watch-header .close-btn {
            background: transparent; border: none; cursor: pointer;
            font-size: 22px; line-height: 1; color: var(--muted);
            padding: 4px 8px;
        }
        .watch-header .close-btn:hover { color: var(--text); }

        /* The pitch (top-down view). */
        .watch-pitch-wrap {
            padding: 18px 22px;
            background: #0f1a0f;
        }
        .watch-pitch {
            position: relative;
            width: 100%;
            aspect-ratio: 16 / 9;
            border: 2px solid rgba(255, 255, 255, 0.85);
            border-radius: 4px;
            overflow: hidden;
            background:
                repeating-linear-gradient(
                    90deg,
                    #1e4d1e 0px, #1e4d1e calc(100% / 14),
                    #245a24 calc(100% / 14), #245a24 calc(100% / 7)
                );
            box-shadow:
                inset 0 0 40px rgba(0, 0, 0, 0.45),
                0 4px 18px rgba(0, 0, 0, 0.5);
        }
        .watch-pitch .line { position: absolute; background: rgba(255, 255, 255, 0.85); }
        .watch-pitch .halfway { width: 2px; height: 100%; left: 50%; top: 0; transform: translateX(-50%); }
        .watch-pitch .center-circle {
            width: 19%; aspect-ratio: 1;
            border: 2px solid rgba(255, 255, 255, 0.85);
            background: transparent;
            border-radius: 50%;
            left: 50%; top: 50%;
            transform: translate(-50%, -50%);
        }
        .watch-pitch .center-spot {
            width: 6px; height: 6px;
            background: rgba(255, 255, 255, 0.85);
            border-radius: 50%;
            left: 50%; top: 50%;
            transform: translate(-50%, -50%);
        }
        .watch-pitch .box {
            position: absolute;
            width: 18%; height: 64%;
            top: 18%;
            border: 2px solid rgba(255, 255, 255, 0.85);
            background: transparent;
        }
        .watch-pitch .box.left  { left: 0;  border-left: none; }
        .watch-pitch .box.right { right: 0; border-right: none; }
        .watch-pitch .six-yard {
            position: absolute;
            width: 7%; height: 30%;
            top: 35%;
            border: 2px solid rgba(255, 255, 255, 0.85);
            background: transparent;
        }
        .watch-pitch .six-yard.left  { left: 0;  border-left: none; }
        .watch-pitch .six-yard.right { right: 0; border-right: none; }
        .watch-pitch .penalty-spot {
            width: 5px; height: 5px;
            background: rgba(255, 255, 255, 0.85);
            border-radius: 50%;
            top: 50%;
            transform: translate(-50%, -50%);
        }
        .watch-pitch .penalty-spot.left  { left: 12%; }
        .watch-pitch .penalty-spot.right { right: 12%; transform: translate(50%, -50%); }
        .watch-pitch .goal-net {
            position: absolute;
            width: 6px; height: 16%;
            top: 42%;
            background: rgba(255, 255, 255, 0.55);
            border-radius: 1px;
        }
        .watch-pitch .goal-net.left  { left: -6px; }
        .watch-pitch .goal-net.right { right: -6px; }
        .watch-pitch .pitch-crest {
            position: absolute;
            width: 56px; height: 56px;
            top: 50%; transform: translateY(-50%);
            opacity: 0.22;
            pointer-events: none;
            object-fit: contain;
        }
        .watch-pitch .pitch-crest.home { left: 4%; }
        .watch-pitch .pitch-crest.away { right: 4%; }

        .watch-pitch .ball {
            position: absolute;
            width: 14px; height: 14px;
            background: radial-gradient(circle at 35% 35%, #ffffff, #cccccc);
            border-radius: 50%;
            box-shadow:
                0 0 10px rgba(255, 255, 255, 0.55),
                0 2px 5px rgba(0, 0, 0, 0.6);
            transform: translate(-50%, -50%);
            transition: left 0.75s cubic-bezier(0.4, 0, 0.2, 1),
                        top 0.75s cubic-bezier(0.4, 0, 0.2, 1);
            left: 50%; top: 50%;
            z-index: 5;
        }
        .watch-pitch .action-overlay {
            position: absolute;
            transform: translate(-50%, -50%);
            background: rgba(0, 0, 0, 0.78);
            color: white;
            padding: 7px 16px;
            border-radius: 4px;
            font-size: 13px;
            font-weight: 700;
            letter-spacing: 1.5px;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.25s;
            white-space: nowrap;
            z-index: 6;
            border: 1px solid rgba(255, 255, 255, 0.15);
        }
        .watch-pitch .action-overlay.visible { opacity: 1; }
        .watch-pitch .action-overlay.goal    { background: #f59e0b; color: #1f1f1f; }
        .watch-pitch .action-overlay.save    { background: #2563eb; color: #ffffff; }
        .watch-pitch .action-overlay.period  { background: rgba(0,0,0,0.85); color: #ffffff; letter-spacing: 2px; }

        .watch-feed {
            flex: 1;
            min-height: 120px;
            max-height: 200px;
            overflow-y: auto;
            padding: 12px 22px;
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
            font-size: 12.5px; line-height: 1.7;
            background: var(--bg);
            border-top: 1px solid var(--border);
        }
        .watch-feed .row {
            display: grid;
            grid-template-columns: 52px 48px 1fr;
            gap: 10px; align-items: baseline;
            padding: 1px 0;
        }
        .watch-feed .row .clock { color: var(--muted); font-variant-numeric: tabular-nums; }
        .watch-feed .row .badge {
            display: inline-block; text-align: center;
            padding: 1px 6px; border-radius: 4px;
            font-size: 10.5px; font-weight: 700;
            background: #e5e7eb; color: #374151;
        }
        .watch-feed .row.goal .badge { background: #fef3c7; color: #92400e; }
        .watch-feed .row.save .badge { background: #dbeafe; color: #1e40af; }
        .watch-feed .row.kick .badge,
        .watch-feed .row.ht .badge,
        .watch-feed .row.ft .badge { background: #f3f4f6; color: #6b7280; }
        .watch-feed .row.pass .badge { background: #ecfdf5; color: #047857; }
        .watch-feed .row.dribble .badge { background: #eef2ff; color: #4338ca; }
        .watch-feed .row.turnover .badge { background: #fef2f2; color: #b91c1c; }
        .watch-footer {
            display: flex; justify-content: space-between; align-items: center;
            padding: 12px 22px;
            border-top: 1px solid var(--border);
            background: var(--card);
        }
        .watch-footer .info { color: var(--muted); font-size: 12px; }
        .watch-footer .controls { display: flex; gap: 8px; }
        .watch-footer .speed-pills {
            display: flex; align-items: center; gap: 6px; flex-wrap: wrap;
        }
        .watch-footer .speed-pill {
            font-size: 12px;
            font-weight: 600;
            padding: 5px 11px;
            border: 1px solid var(--border);
            border-radius: 999px;
            background: var(--bg);
            color: var(--muted);
            cursor: pointer;
            line-height: 1;
            transition: background 0.15s, color 0.15s, border-color 0.15s;
        }
        .watch-footer .speed-pill:hover { color: var(--text); border-color: #cbd5e1; }
        .watch-footer .speed-pill.is-active {
            background: var(--primary);
            color: #ffffff;
            border-color: var(--primary);
        }
        .watch-footer .speed-pill.is-active:hover { color: #ffffff; }
        .watch-banner {
            padding: 10px 22px;
            background: #fef2f2; color: var(--danger);
            font-size: 13px;
            border-bottom: 1px solid #fecaca;
        }

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

<div id="watch-overlay" class="watch-overlay" aria-hidden="true">
    <div class="watch-modal" role="dialog" aria-modal="true" aria-labelledby="watch-title">
        <div class="watch-header">
            <div class="team home" id="watch-home"><img id="watch-home-crest" alt=""><span id="watch-home-name">Home</span></div>
            <div class="score" id="watch-score">0 – 0</div>
            <div class="team away" id="watch-away"><span id="watch-away-name">Away</span><img id="watch-away-crest" alt=""></div>
            <button class="close-btn" id="watch-close" aria-label="Close">&times;</button>
        </div>
        <div id="watch-error" class="watch-banner" style="display:none"></div>
        <div class="watch-pitch-wrap">
            <div class="watch-pitch" id="watch-pitch">
                <img class="pitch-crest home" id="pitch-crest-home" alt="">
                <img class="pitch-crest away" id="pitch-crest-away" alt="">
                <div class="line halfway"></div>
                <div class="line center-circle"></div>
                <div class="line center-spot"></div>
                <div class="line box left"></div>
                <div class="line box right"></div>
                <div class="line six-yard left"></div>
                <div class="line six-yard right"></div>
                <div class="line penalty-spot left"></div>
                <div class="line penalty-spot right"></div>
                <div class="goal-net left"></div>
                <div class="goal-net right"></div>
                <div class="ball" id="watch-ball"></div>
                <div class="action-overlay" id="watch-action-overlay"></div>
            </div>
        </div>
        <div class="watch-feed" id="watch-feed"></div>
        <div class="watch-footer">
            <div class="speed-pills" id="watch-speed" role="group" aria-label="Playback speed">
                <button type="button" class="speed-pill" data-speed="10">10x</button>
                <button type="button" class="speed-pill is-active" data-speed="20">20x</button>
                <button type="button" class="speed-pill" data-speed="30">30x</button>
                <button type="button" class="speed-pill" data-speed="60">60x</button>
                <span id="watch-status" class="info"></span>
            </div>
            <div class="controls">
                <button id="watch-skip">Skip to end</button>
                <button id="watch-close-2">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
(() => {
    const $ = (id) => document.getElementById(id);

    let currentSeasonId = null;
    let currentSeasonIsHistorical = false;
    let currentSeasonCurrentWeek = null;
    let historicalSeasonCount = 0;

    const flash = (msg, isError = false) => {
        const el = $('flash');
        el.textContent = msg;
        el.className = 'show' + (isError ? ' error' : '');
        clearTimeout(flash._t);
        flash._t = setTimeout(() => el.className = '', 2500);
    };

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
        currentSeasonCurrentWeek = s.current_week ?? null;
        $('played-count').textContent = s.fixtures_played;
        $('total-count').textContent = s.fixtures_total;
        $('complete-badge').hidden = !s.is_complete;
        $('historical-note').hidden = !currentSeasonIsHistorical;

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
        fixturesCache.byId.clear();
        weeks.forEach(w => byWeek[w].forEach(f => {
            fixturesCache.byId.set(f.id, f);
            cacheTeam(f.home_team);
            cacheTeam(f.away_team);
        }));
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

        container.querySelectorAll('[data-edit-id]').forEach(link => {
            link.addEventListener('click', (e) => {
                e.preventDefault();
                openEditor(link.dataset.editId);
            });
        });

        container.querySelectorAll('[data-commentary-id]').forEach(link => {
            link.addEventListener('click', (e) => {
                e.preventDefault();
                toggleCommentary(link.dataset.commentaryId, link);
            });
        });

        container.querySelectorAll('[data-watch-id]').forEach(link => {
            link.addEventListener('click', (e) => {
                e.preventDefault();
                const f = findFixture(parseInt(link.dataset.watchId, 10));
                if (f) openWatchModal(f);
            });
        });
    };

    let watchSource = null;
    let watchFixture = null;
    let watchTriggeredSimulation = false;
    let watchSpeed = 20;
    let watchLastSecond = -1;
    let watchPaceTimer = null;
    let watchFinalScore = null;
    // De-dupes rows when speed change swaps SSE -> client-side pacing.
    const watchSeenKeys = new Set();
    const TYPE_BADGE = {
        kickoff: 'KICK', halftime: 'HT', fulltime: 'FT',
        shot: 'SHOT', save: 'SAVE', goal: 'GOAL',
        pass: 'PASS', dribble: 'DRIB', turnover: 'LOSS',
    };
    const TEAM_LOOKUP = {};

    const cacheTeam = (t) => { if (t && t.id != null) TEAM_LOOKUP[t.id] = t; };

    const fixturesCache = { byId: new Map() };

    const findFixture = (id) => fixturesCache.byId.get(id) || null;

    const openWatchModal = (fixture) => {
        watchFixture = fixture;
        watchTriggeredSimulation = !fixture.played;
        cacheTeam(fixture.home_team);
        cacheTeam(fixture.away_team);

        const homeCrest = `/img/teams/${fixture.home_team.short_name.toLowerCase()}.png`;
        const awayCrest = `/img/teams/${fixture.away_team.short_name.toLowerCase()}.png`;

        $('watch-home-name').textContent = fixture.home_team.name;
        $('watch-away-name').textContent = fixture.away_team.name;
        $('watch-home-crest').src = homeCrest;
        $('watch-away-crest').src = awayCrest;
        $('pitch-crest-home').src = homeCrest;
        $('pitch-crest-away').src = awayCrest;

        $('watch-score').textContent = '0 – 0';
        $('watch-feed').innerHTML = '';
        $('watch-error').style.display = 'none';
        setActiveSpeedPill(watchSpeed);
        $('watch-status').textContent = '';
        $('watch-skip').style.display = '';
        $('watch-skip').disabled = false;
        watchSeenKeys.clear();
        watchLastSecond = -1;
        watchFinalScore = null;
        cancelClientPace();
        $('watch-overlay').classList.add('open');
        $('watch-overlay').setAttribute('aria-hidden', 'false');

        $('watch-ball').style.left = '50%';
        $('watch-ball').style.top = '50%';
        $('watch-action-overlay').className = 'action-overlay';

        openSseStream(fixture.id);
    };

    const closeWatchModal = async () => {
        if (watchSource) {
            try { watchSource.close(); } catch (_) {}
            watchSource = null;
        }
        cancelClientPace();
        clearTimeout(scheduleIdleDrift._t);
        clearTimeout(showActionOverlay._t);
        $('watch-overlay').classList.remove('open');
        $('watch-overlay').setAttribute('aria-hidden', 'true');
        const triggered = watchTriggeredSimulation;
        watchFixture = null;
        watchTriggeredSimulation = false;
        if (triggered) {
            await loadAll();
        }
    };

    const openSseStream = (fixtureId) => {
        if (watchSource) {
            try { watchSource.close(); } catch (_) {}
            watchSource = null;
        }
        try {
            watchSource = new EventSource(`/api/fixtures/${fixtureId}/watch?speed=${watchSpeed}`);
        } catch (err) {
            showWatchError('Could not start stream.');
            return;
        }

        watchSource.addEventListener('match-event', (e) => {
            try {
                const ev = JSON.parse(e.data);
                appendFeedRow(ev);
            } catch (_) {}
        });

        watchSource.addEventListener('complete', (e) => {
            try {
                const data = JSON.parse(e.data);
                watchFinalScore = { home: data.score_home, away: data.score_away };
                $('watch-score').textContent = `${data.score_home} – ${data.score_away}`;
            } catch (_) {}
            $('watch-skip').style.display = 'none';
            $('watch-status').textContent = 'Full time';
            if (watchSource) {
                try { watchSource.close(); } catch (_) {}
                watchSource = null;
            }
        });

        watchSource.onerror = () => {
            if (!watchSource || watchSource.readyState !== EventSource.CLOSED) {
                showWatchError('Connection lost.');
            }
        };
    };

    const eventKey = (ev) => `${ev.second}|${ev.type}|${ev.team_id ?? ''}|${ev.player_id ?? ''}`;

    const appendFeedRow = (ev) => {
        const key = eventKey(ev);
        if (watchSeenKeys.has(key)) return;
        watchSeenKeys.add(key);
        if (ev.second > watchLastSecond) watchLastSecond = ev.second;

        const feed = $('watch-feed');
        const badge = TYPE_BADGE[ev.type] || ev.type.toUpperCase();
        const cssClass = (ev.type === 'kickoff') ? 'kick' : ev.type;
        const sentence = sentenceFor(ev);
        const row = document.createElement('div');
        row.className = `row ${cssClass}`;
        row.innerHTML = `
            <span class="clock">${ev.clock}</span>
            <span class="badge">${badge}</span>
            <span class="sentence">${sentence}</span>
        `;
        feed.appendChild(row);
        feed.scrollTop = feed.scrollHeight;

        moveBallForEvent(ev);

        if (ev.type === 'goal') {
            updateScoreFromGoal(ev);
        }
    };

    const PITCH = {
        CENTRE: { x: 50, y: 50 },
        HOME_BOX_X: 88,
        AWAY_BOX_X: 12,
        HOME_RANGE_X: 64,
        AWAY_RANGE_X: 36,
        OFF_TARGET_HIGH_Y: [18, 36],
        OFF_TARGET_LOW_Y:  [64, 82],
        IDLE_X: [44, 56],
        IDLE_Y: [40, 60],
    };

    const randIn = ([lo, hi]) => lo + Math.random() * (hi - lo);
    const jitter = (amp) => (Math.random() - 0.5) * 2 * amp;

    const positionForEvent = (ev) => {
        if (!watchFixture) return PITCH.CENTRE;
        if (ev.type === 'kickoff' || ev.type === 'halftime' || ev.type === 'fulltime') {
            return PITCH.CENTRE;
        }

        const isHomeAttacking = ev.team_id === watchFixture.home_team.id;
        const zone = (ev.detail && ev.detail.zone) || 'MIDFIELD';

        if (ev.type === 'pass' || ev.type === 'dribble' || ev.type === 'turnover') {
            let baseX;
            if (zone === 'OWN_THIRD') {
                baseX = isHomeAttacking ? 18 : 82;
            } else if (zone === 'ATTACKING_THIRD') {
                baseX = isHomeAttacking ? 76 : 24;
            } else {
                baseX = 50;
            }
            return { x: baseX + jitter(6), y: 50 + jitter(22) };
        }

        const longRange = zone === 'MIDFIELD';
        const baseX = longRange
            ? (isHomeAttacking ? PITCH.HOME_RANGE_X : PITCH.AWAY_RANGE_X)
            : (isHomeAttacking ? PITCH.HOME_BOX_X : PITCH.AWAY_BOX_X);

        if (ev.type === 'goal' || ev.type === 'save') {
            return { x: baseX + jitter(2), y: 50 + jitter(7) };
        }

        if (ev.type === 'shot') {
            const goesHigh = Math.random() < 0.5;
            const offY = goesHigh ? randIn(PITCH.OFF_TARGET_HIGH_Y) : randIn(PITCH.OFF_TARGET_LOW_Y);
            const pushX = isHomeAttacking ? 1.5 : -1.5;
            return { x: baseX + pushX + jitter(1.5), y: offY };
        }

        return PITCH.CENTRE;
    };

    const overlayDescriptorFor = (ev) => {
        switch (ev.type) {
            case 'kickoff':  return { text: 'KICK OFF',   cls: 'period' };
            case 'halftime': return { text: 'HALF TIME',  cls: 'period' };
            case 'fulltime': return { text: 'FULL TIME',  cls: 'period' };
            case 'goal':     return { text: 'GOAL!',      cls: 'goal' };
            case 'save':     return { text: 'SAVED',      cls: 'save' };
            case 'shot':     return { text: 'OFF TARGET', cls: '' };
            default:         return null;
        }
    };

    const moveBallForEvent = (ev) => {
        if (!watchFixture) return;
        const ball = $('watch-ball');
        const pos = positionForEvent(ev);

        ball.style.left = pos.x + '%';
        ball.style.top  = pos.y + '%';

        const desc = overlayDescriptorFor(ev);
        if (desc) showActionOverlay(desc.text, desc.cls, pos);

        if (ev.type === 'goal' || ev.type === 'save' || ev.type === 'shot') {
            scheduleIdleDrift();
        }
    };

    const showActionOverlay = (text, cls, anchor) => {
        const overlay = $('watch-action-overlay');
        overlay.className = 'action-overlay';
        overlay.textContent = text;

        const overlayY = anchor.y >= 50
            ? Math.max(8, anchor.y - 12)
            : Math.min(88, anchor.y + 12);
        const overlayX = Math.max(14, Math.min(86, anchor.x));
        overlay.style.left = overlayX + '%';
        overlay.style.top  = overlayY + '%';
        if (cls) overlay.classList.add(cls);

        requestAnimationFrame(() => overlay.classList.add('visible'));
        clearTimeout(showActionOverlay._t);
        showActionOverlay._t = setTimeout(() => overlay.classList.remove('visible'), 1500);
    };

    const scheduleIdleDrift = () => {
        clearTimeout(scheduleIdleDrift._t);
        scheduleIdleDrift._t = setTimeout(() => {
            const ball = $('watch-ball');
            if (!ball || !$('watch-overlay').classList.contains('open')) return;
            ball.style.left = randIn(PITCH.IDLE_X) + '%';
            ball.style.top  = randIn(PITCH.IDLE_Y) + '%';
        }, 1400);
    };

    const sentenceFor = (ev) => {
        const teamName = TEAM_LOOKUP[ev.team_id]?.short_name || '';
        const player = ev.player_name ? escapeHtml(ev.player_name) : '';
        switch (ev.type) {
            case 'kickoff':  return 'Kick off.';
            case 'halftime': return `Half time. ${ev.detail?.score_home ?? 0} – ${ev.detail?.score_away ?? 0}.`;
            case 'fulltime': return `Full time. ${ev.detail?.score_home ?? 0} – ${ev.detail?.score_away ?? 0}.`;
            case 'goal':     return `<strong>GOAL!</strong> ${player} (${teamName}).`;
            case 'save':     return `${player} (${teamName}) shoots, saved.`;
            case 'shot':     return `${player} (${teamName}) shoots, off target.`;
            case 'pass':     return `${player} (${teamName}) finds a teammate.`;
            case 'dribble':  return `${player} (${teamName}) drives forward.`;
            case 'turnover': return `${player} (${teamName}) wins the ball.`;
            default:         return ev.type;
        }
    };

    const updateScoreFromGoal = (ev) => {
        if (!watchFixture) return;
        const [h, a] = $('watch-score').textContent.split('–').map(s => parseInt(s.trim(), 10) || 0);
        if (ev.team_id === watchFixture.home_team.id) {
            $('watch-score').textContent = `${h + 1} – ${a}`;
        } else if (ev.team_id === watchFixture.away_team.id) {
            $('watch-score').textContent = `${h} – ${a + 1}`;
        }
    };

    const showWatchError = (msg) => {
        const b = $('watch-error');
        b.textContent = msg;
        b.style.display = '';
    };

    const cancelClientPace = () => {
        if (watchPaceTimer !== null) {
            clearTimeout(watchPaceTimer);
            watchPaceTimer = null;
        }
    };

    // Speed-change handover: SSE -> setTimeout chain at the new pace.
    const switchToClientPace = async () => {
        if (!watchFixture) return;
        try {
            const res = await fetch(`/api/fixtures/${watchFixture.id}/events`);
            if (!res.ok) return;
            const data = await res.json();
            watchFinalScore = data.score;
            const remaining = data.events.filter((e) => !watchSeenKeys.has(eventKey(e)));
            runClientPace(remaining);
        } catch (_) {}
    };

    const runClientPace = (remaining) => {
        cancelClientPace();
        if (remaining.length === 0) {
            if (watchFinalScore) {
                $('watch-score').textContent = `${watchFinalScore.home} – ${watchFinalScore.away}`;
            }
            $('watch-skip').style.display = 'none';
            $('watch-status').textContent = 'Full time';
            return;
        }
        const next = remaining[0];
        const reference = Math.max(watchLastSecond, 0);
        const delayMs = Math.max(0, (next.second - reference) / watchSpeed * 1000);
        watchPaceTimer = setTimeout(() => {
            appendFeedRow(next);
            runClientPace(remaining.slice(1));
        }, delayMs);
    };

    const setActiveSpeedPill = (speed) => {
        document.querySelectorAll('#watch-speed .speed-pill').forEach((btn) => {
            btn.classList.toggle('is-active', parseInt(btn.dataset.speed, 10) === speed);
        });
    };

    const onSpeedPillClick = async (e) => {
        const btn = e.target.closest('.speed-pill');
        if (!btn) return;
        const speed = parseInt(btn.dataset.speed, 10) || 20;
        if (speed === watchSpeed) return;
        watchSpeed = speed;
        setActiveSpeedPill(speed);
        if (!watchFixture) return;
        if (watchSource) {
            try { watchSource.close(); } catch (_) {}
            watchSource = null;
        }
        await switchToClientPace();
    };

    const skipToEnd = async () => {
        if (!watchFixture) return;
        $('watch-skip').disabled = true;
        if (watchSource) {
            try { watchSource.close(); } catch (_) {}
            watchSource = null;
        }
        try {
            const res = await fetch(`/api/fixtures/${watchFixture.id}/events`);
            if (!res.ok) {
                showWatchError('Could not load full event list.');
                return;
            }
            const data = await res.json();
            $('watch-feed').innerHTML = '';
            data.events.forEach(appendFeedRow);
            $('watch-score').textContent = `${data.score.home} – ${data.score.away}`;
            $('watch-skip').style.display = 'none';
            $('watch-status').textContent = 'Full time';
        } catch (_) {
            showWatchError('Could not load full event list.');
        }
    };

    $('watch-close').addEventListener('click', closeWatchModal);
    $('watch-close-2').addEventListener('click', closeWatchModal);
    $('watch-overlay').addEventListener('click', (e) => {
        if (e.target.id === 'watch-overlay') closeWatchModal();
    });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && $('watch-overlay').classList.contains('open')) {
            closeWatchModal();
        }
    });
    $('watch-skip').addEventListener('click', skipToEnd);
    $('watch-speed').addEventListener('click', onSpeedPillClick);

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
        const hasEvents = (f.events_count ?? 0) > 0;
        const scoreCell = played
            ? `<div class="score">${f.home_goals}-${f.away_goals}</div>`
            : `<div class="score placeholder">vs</div>`;
        const editLink = currentSeasonIsHistorical
            ? ''
            : `<a href="#" class="edit-link" data-edit-id="${f.id}">edit</a>`;
        let watchLink = '';
        if (!currentSeasonIsHistorical) {
            if (!played && f.week === currentSeasonCurrentWeek) {
                watchLink = `<a href="#" class="watch-link" data-watch-id="${f.id}">Watch</a>`;
            } else if (played && hasEvents) {
                watchLink = `<a href="#" class="watch-link" data-watch-id="${f.id}">Replay</a>`;
            }
        }
        const actions = played
            ? `<div class="actions">
                   ${editLink}
                   <a href="#" class="commentary-link" data-commentary-id="${f.id}">Commentary</a>
                   ${watchLink}
               </div>`
            : (watchLink ? `<div class="actions">${watchLink}</div>` : `<span></span>`);
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

    const TEAM_COLORS = ['#2563eb', '#dc2626', '#16a34a', '#f59e0b'];
    let chartInstance = null;

    const TEAM_CRESTS = {};
    const loadCrest = (shortName) => {
        const key = shortName.toLowerCase();
        if (TEAM_CRESTS[shortName]) return TEAM_CRESTS[shortName];
        const img = new Image(22, 22);
        img.src = `/img/teams/${key}.png`;
        TEAM_CRESTS[shortName] = img;
        return img;
    };

    const renderChart = (data) => {
        const card = $('chart-card');
        if (!data || data.weeks.length === 0 || typeof Chart === 'undefined') {
            card.hidden = true;
            return;
        }
        card.hidden = false;

        const datasets = data.series.map((s, i) => {
            const crest = loadCrest(s.team.short_name);
            return {
                label: s.team.short_name,
                data: s.probabilities,
                borderColor: TEAM_COLORS[i % TEAM_COLORS.length],
                backgroundColor: TEAM_COLORS[i % TEAM_COLORS.length] + '22',
                tension: 0.25,
                spanGaps: true,
                pointStyle: crest,
                pointRadius: 11,
                pointHoverRadius: 13,
                borderWidth: 2,
            };
        });

        const config = {
            type: 'line',
            data: { labels: data.weeks.map(w => `W${w}`), datasets },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                layout: { padding: { top: 4, bottom: 4, left: 6, right: 14 } },
                scales: {
                    y: {
                        // Stretched past 0/100 so crests aren't bisected.
                        min: -8, max: 108,
                        ticks: {
                            callback: v => v + '%',
                            stepSize: 10,
                            autoSkip: false,
                        },
                        afterBuildTicks: (axis) => {
                            axis.ticks = axis.ticks.filter(t => t.value >= 0 && t.value <= 100);
                        },
                        grid: { color: 'rgba(0,0,0,0.06)' },
                    },
                    x: { grid: { display: false } },
                },
                plugins: {
                    legend: { position: 'bottom', labels: { boxWidth: 22, boxHeight: 22, usePointStyle: true, padding: 14 } },
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

    const loadPredictions = (season) => {
        if (season.is_historical) {
            $('predictions-card').hidden = true;
            $('chart-card').hidden = true;
            return;
        }
        let iterations = null;
        if (season.fixtures_played >= 8 && !season.is_complete) {
            iterations = 10000;
        } else if (season.is_complete) {
            iterations = 1;
        }
        if (iterations === null) {
            $('predictions-card').hidden = true;
            return;
        }
        api('GET', `/api/predictions?iterations=${iterations}`)
            .then(renderPredictions)
            .catch(() => {});
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
            loadPredictions(standings.season);
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
