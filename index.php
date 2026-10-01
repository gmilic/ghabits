<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
db();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>GHabits</title>
    <meta name="robots" content="noindex, nofollow">
    <link rel="stylesheet" href="assets/app.css">
</head>
<body>
    <main id="auth-screen" class="auth-screen" hidden>
        <section class="auth-card">
            <div class="mark">G</div>
            <h1 id="auth-title">GHabits</h1>
            <p id="auth-copy">Enter your password to open your habit tracker.</p>
            <form id="auth-form">
                <label for="password">Password</label>
                <input id="password" name="password" type="password" autocomplete="current-password" minlength="8" required>
                <button id="auth-submit" type="submit">Unlock</button>
                <p id="auth-error" class="error-text" role="alert"></p>
            </form>
        </section>
    </main>

    <main id="app" class="app-shell" hidden>
        <header class="topbar">
            <div>
                <p class="eyebrow">Private tracker</p>
                <h1>GHabits</h1>
            </div>
            <div class="top-actions">
                <button id="export-button" class="icon-button" type="button" title="Export JSON" aria-label="Export JSON">EX</button>
                <button id="logout-button" class="icon-button" type="button" title="Log out" aria-label="Log out">LO</button>
                <button id="add-button" class="primary-button" type="button">Add habit</button>
            </div>
        </header>

        <section class="summary-band" aria-label="Today summary">
            <div>
                <span id="today-label" class="summary-label">Today</span>
                <strong id="today-count">0 / 0</strong>
            </div>
            <div>
                <span class="summary-label">Current view</span>
                <strong id="view-count">42 days</strong>
            </div>
            <div>
                <span class="summary-label">Storage</span>
                <strong>SQLite</strong>
            </div>
        </section>

        <section class="tracker-panel">
            <div class="tracker-header">
                <div>
                    <h2>Habits</h2>
                    <p id="habit-subtitle">Track yes/no and measurable habits.</p>
                </div>
                <div id="date-strip" class="date-strip" aria-hidden="true"></div>
            </div>
            <div id="habit-list" class="habit-list"></div>
            <div id="empty-state" class="empty-state" hidden>
                <h2>No habits yet</h2>
                <p>Add your first habit and start filling the daily grid.</p>
                <button class="primary-button" type="button" data-open-habit>Add habit</button>
            </div>
        </section>

        <section class="range-section" aria-label="Visible history range">
            <h2 id="range-title">42 days</h2>
            <div id="range-controls" class="range-controls" aria-label="Visible history range"></div>
        </section>
    </main>

    <dialog id="habit-dialog">
        <form id="habit-form" method="dialog" class="dialog-card">
            <div class="dialog-head">
                <h2 id="habit-dialog-title">Add habit</h2>
                <button class="ghost-button" type="button" data-close-dialog>Close</button>
            </div>
            <input type="hidden" id="habit-id">
            <label>
                Name
                <input id="habit-name" type="text" maxlength="80" required>
            </label>
            <label>
                Question
                <input id="habit-question" type="text" maxlength="160" placeholder="Did you do it today?">
            </label>
            <div class="form-grid">
                <label>
                    Type
                    <select id="habit-type">
                        <option value="boolean">Yes / no</option>
                        <option value="measurable">Measurable</option>
                    </select>
                </label>
                <label>
                    Color
                    <input id="habit-color" type="color" value="#66bb6a">
                </label>
            </div>
            <div id="measure-fields" class="form-grid">
                <label>
                    Target
                    <input id="habit-target" type="number" min="0.01" step="0.01" value="1">
                </label>
                <label>
                    Unit
                    <input id="habit-unit" type="text" maxlength="24" placeholder="minutes">
                </label>
            </div>
            <label>
                Schedule
                <select id="schedule-type">
                    <option value="daily">Every day</option>
                    <option value="weekdays">Selected days</option>
                    <option value="times_per_week">Times per week</option>
                </select>
            </label>
            <div id="weekday-picker" class="weekday-picker" hidden>
                <label><input type="checkbox" value="1"> Mon</label>
                <label><input type="checkbox" value="2"> Tue</label>
                <label><input type="checkbox" value="3"> Wed</label>
                <label><input type="checkbox" value="4"> Thu</label>
                <label><input type="checkbox" value="5"> Fri</label>
                <label><input type="checkbox" value="6"> Sat</label>
                <label><input type="checkbox" value="7"> Sun</label>
            </div>
            <label id="times-field" hidden>
                Times per week
                <input id="times-per-week" type="number" min="1" max="7" step="1" value="3">
            </label>
            <p id="habit-error" class="error-text" role="alert"></p>
            <div class="dialog-actions">
                <button id="delete-habit-button" class="danger-button" type="button" hidden>Delete</button>
                <button class="primary-button" type="submit">Save</button>
            </div>
        </form>
    </dialog>

    <dialog id="entry-dialog">
        <form id="entry-form" method="dialog" class="dialog-card compact-dialog">
            <div class="dialog-head">
                <h2 id="entry-title">Value</h2>
                <button class="ghost-button" type="button" data-close-entry>Close</button>
            </div>
            <p id="entry-date-label" class="muted"></p>
            <label>
                Value
                <input id="entry-value" type="number" min="0" step="0.01" inputmode="decimal">
            </label>
            <label>
                Note
                <input id="entry-note" type="text" maxlength="240">
            </label>
            <div class="dialog-actions">
                <button id="clear-entry-button" class="danger-button" type="button">Clear</button>
                <button class="primary-button" type="submit">Save</button>
            </div>
        </form>
    </dialog>

    <script src="assets/app.js" defer></script>
</body>
</html>

