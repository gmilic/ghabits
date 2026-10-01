const VIEW_RANGES = [42, 90, 180, 365];

const state = {
  csrfToken: null,
  setupRequired: false,
  habits: [],
  entries: new Map(),
  dates: [],
  editingEntry: null,
  visibleDays: 42,
};

const $ = (selector) => document.querySelector(selector);
const $$ = (selector) => Array.from(document.querySelectorAll(selector));

const authScreen = $("#auth-screen");
const appShell = $("#app");
const authForm = $("#auth-form");
const authTitle = $("#auth-title");
const authCopy = $("#auth-copy");
const authSubmit = $("#auth-submit");
const authError = $("#auth-error");
const passwordInput = $("#password");
const habitList = $("#habit-list");
const emptyState = $("#empty-state");
const dateStrip = $("#date-strip");
const todayCount = $("#today-count");
const todayLabel = $("#today-label");
const viewCount = $("#view-count");
const rangeTitle = $("#range-title");
const rangeControls = $("#range-controls");

const habitDialog = $("#habit-dialog");
const habitForm = $("#habit-form");
const entryDialog = $("#entry-dialog");
const entryForm = $("#entry-form");

const api = async (action, options = {}) => {
  const headers = { "Content-Type": "application/json", ...(options.headers || {}) };
  if (state.csrfToken && options.method && options.method !== "GET") {
    headers["X-CSRF-Token"] = state.csrfToken;
  }
  const response = await fetch(`api.php?action=${action}`, { ...options, headers });
  const data = await response.json();
  if (!data.ok) throw new Error(data.error || "Request failed.");
  if (data.csrfToken) state.csrfToken = data.csrfToken;
  return data;
};

const isoDate = (date) => {
  const copy = new Date(date.getFullYear(), date.getMonth(), date.getDate());
  const offset = copy.getTimezoneOffset();
  copy.setMinutes(copy.getMinutes() - offset);
  return copy.toISOString().slice(0, 10);
};

const displayDate = (iso, format = "short") => {
  const [year, month, day] = iso.split("-").map(Number);
  const date = new Date(year, month - 1, day);
  if (format === "weekday") return date.toLocaleDateString("en", { weekday: "short" }).slice(0, 1);
  if (format === "monthName") return date.toLocaleDateString("en", { month: "short" });
  if (format === "month") return date.toLocaleDateString("en", { month: "short", day: "numeric" });
  return date.toLocaleDateString("en", { month: "short", day: "numeric" });
};

const dateKeyToDay = (iso) => {
  const [year, month, day] = iso.split("-").map(Number);
  const jsDay = new Date(year, month - 1, day).getDay();
  return jsDay === 0 ? 7 : jsDay;
};

const monthKey = (iso) => iso.slice(0, 7);

const loadVisibleDays = () => {
  const saved = Number(localStorage.getItem("ghabits-visible-days") || 42);
  state.visibleDays = VIEW_RANGES.includes(saved) ? saved : 42;
  document.documentElement.style.setProperty("--visible-days", String(state.visibleDays));
};

const buildDates = () => {
  const dates = [];
  const today = new Date();
  for (let index = 0; index < state.visibleDays; index += 1) {
    const date = new Date(today.getFullYear(), today.getMonth(), today.getDate());
    date.setDate(date.getDate() - index);
    dates.push(isoDate(date));
  }
  state.dates = dates;
};

const entryKey = (habitId, date) => `${habitId}:${date}`;
const getEntry = (habitId, date) => state.entries.get(entryKey(habitId, date));
const isSatisfied = (habit, date) => {
  const entry = getEntry(habit.id, date);
  if (!entry) return false;
  return habit.type === "measurable" ? Number(entry.value) >= Number(habit.target || 1) : Number(entry.value) > 0;
};

const isDue = (habit, date) => {
  if (habit.schedule_type === "daily") return true;
  if (habit.schedule_type === "weekdays") {
    const days = habit.schedule?.days || [1, 2, 3, 4, 5, 6, 7];
    return days.includes(dateKeyToDay(date));
  }
  return true;
};

const getWeekKey = (iso) => {
  const [year, month, day] = iso.split("-").map(Number);
  const date = new Date(Date.UTC(year, month - 1, day));
  const dayNum = date.getUTCDay() || 7;
  date.setUTCDate(date.getUTCDate() + 4 - dayNum);
  const yearStart = new Date(Date.UTC(date.getUTCFullYear(), 0, 1));
  const week = Math.ceil((((date - yearStart) / 86400000) + 1) / 7);
  return `${date.getUTCFullYear()}-${String(week).padStart(2, "0")}`;
};

const weeklySatisfied = (habit, weekKey) => {
  const target = habit.schedule?.times || 1;
  let total = 0;
  for (const date of state.dates) {
    if (getWeekKey(date) === weekKey && isSatisfied(habit, date)) total += 1;
  }
  return total >= target;
};

const scoreFor = (habit) => {
  if (habit.schedule_type === "times_per_week") {
    const weeks = [...new Set(state.dates.map(getWeekKey))].slice(0, 6);
    const done = weeks.filter((week) => weeklySatisfied(habit, week)).length;
    return Math.round((done / Math.max(weeks.length, 1)) * 100);
  }
  const dueDates = state.dates.slice(0, 30).filter((date) => isDue(habit, date));
  const done = dueDates.filter((date) => isSatisfied(habit, date)).length;
  return Math.round((done / Math.max(dueDates.length, 1)) * 100);
};

const streakFor = (habit) => {
  if (habit.schedule_type === "times_per_week") {
    const weeks = [...new Set(state.dates.map(getWeekKey))];
    let streak = 0;
    for (const week of weeks) {
      if (!weeklySatisfied(habit, week)) break;
      streak += 1;
    }
    return `${streak}w`;
  }
  let streak = 0;
  for (const date of state.dates) {
    if (!isDue(habit, date)) continue;
    if (!isSatisfied(habit, date)) break;
    streak += 1;
  }
  return String(streak);
};

const scheduleLabel = (habit) => {
  if (habit.schedule_type === "daily") return "Every day";
  if (habit.schedule_type === "times_per_week") return `${habit.schedule?.times || 1}x / week`;
  const names = ["Mon", "Tue", "Wed", "Thu", "Fri", "Sat", "Sun"];
  return (habit.schedule?.days || []).map((day) => names[day - 1]).join(", ");
};

const renderDateStrip = () => {
  dateStrip.innerHTML = "";
  document.documentElement.style.setProperty("--visible-days", String(state.visibleDays));
  state.dates.forEach((date, index) => {
    const previousDate = state.dates[index - 1];
    const startsVisibleMonth = index === 0 || monthKey(date) !== monthKey(previousDate);
    const showMonth = startsVisibleMonth || date.endsWith("-01");
    const cell = document.createElement("div");
    cell.className = `date-cell ${index === 0 ? "is-today" : ""} ${showMonth ? "has-month" : ""}`;
    cell.innerHTML = `
      <span class="month-label">${showMonth ? displayDate(date, "monthName") : ""}</span>
      <span>${displayDate(date, "weekday")}</span>
      <strong>${date.slice(-2)}</strong>
    `;
    dateStrip.append(cell);
  });
};

const renderSummary = () => {
  const today = state.dates[0];
  const dueHabits = state.habits.filter((habit) => isDue(habit, today));
  const done = dueHabits.filter((habit) => isSatisfied(habit, today)).length;
  todayLabel.textContent = `Today, ${displayDate(today)}`;
  todayCount.textContent = `${done} / ${dueHabits.length}`;
  viewCount.textContent = `${state.visibleDays} days`;
  rangeTitle.textContent = `${state.visibleDays} days`;
};

const render = () => {
  renderRangeControls();
  renderDateStrip();
  renderSummary();
  habitList.innerHTML = "";
  emptyState.hidden = state.habits.length > 0;

  for (const habit of state.habits) {
    const row = document.createElement("article");
    row.className = "habit-row";
    row.style.setProperty("--habit-color", habit.color);

    const meta = document.createElement("div");
    meta.className = "habit-meta";
    meta.innerHTML = `
      <button class="habit-edit" type="button" aria-label="Edit ${escapeHtml(habit.name)}">
        <span class="color-dot"></span>
        <span>
          <strong>${escapeHtml(habit.name)}</strong>
          <small>${escapeHtml(scheduleLabel(habit))}</small>
        </span>
      </button>
      <div class="habit-stats">
        <span><b>${streakFor(habit)}</b> streak</span>
        <span><b>${scoreFor(habit)}%</b> score</span>
      </div>
    `;
    meta.querySelector(".habit-edit").addEventListener("click", () => openHabitDialog(habit));

    const grid = document.createElement("div");
    grid.className = "habit-grid";
    for (const date of state.dates) {
      const button = document.createElement("button");
      const entry = getEntry(habit.id, date);
      const done = isSatisfied(habit, date);
      const due = isDue(habit, date);
      button.type = "button";
      button.className = `track-cell ${done ? "is-done" : ""} ${due ? "" : "is-off"}`;
      button.title = `${habit.name} - ${displayDate(date)}${entry ? `: ${entry.value}` : ""}`;
      button.setAttribute("aria-label", button.title);
      if (habit.type === "measurable" && entry) {
        button.textContent = compactValue(entry.value);
      }
      button.addEventListener("click", () => handleCellClick(habit, date));
      grid.append(button);
    }

    row.append(meta, grid);
    habitList.append(row);
  }
};

const compactValue = (value) => {
  const number = Number(value);
  if (number >= 1000) return `${Math.round(number / 1000)}k`;
  return Number.isInteger(number) ? String(number) : number.toFixed(1);
};

const escapeHtml = (value) => String(value)
  .replaceAll("&", "&amp;")
  .replaceAll("<", "&lt;")
  .replaceAll(">", "&gt;")
  .replaceAll('"', "&quot;");

const loadData = async () => {
  const data = await api(`list&days=${state.visibleDays}`);
  state.habits = data.habits;
  state.entries = new Map();
  for (const entry of data.entries) {
    state.entries.set(entryKey(entry.habit_id, entry.entry_date), entry);
  }
  buildDates();
  render();
};

const renderRangeControls = () => {
  rangeControls.innerHTML = "";
  for (const days of VIEW_RANGES) {
    const button = document.createElement("button");
    button.type = "button";
    button.className = `range-button ${days === state.visibleDays ? "is-active" : ""}`;
    button.textContent = String(days);
    button.setAttribute("aria-pressed", days === state.visibleDays ? "true" : "false");
    button.addEventListener("click", async () => {
      if (days === state.visibleDays) return;
      state.visibleDays = days;
      localStorage.setItem("ghabits-visible-days", String(days));
      buildDates();
      renderRangeControls();
      await loadData();
    });
    rangeControls.append(button);
  }
};

const handleCellClick = async (habit, date) => {
  if (habit.type === "boolean") {
    const value = isSatisfied(habit, date) ? 0 : 1;
    await api("set_entry", {
      method: "POST",
      body: JSON.stringify({ habit_id: habit.id, date, value }),
    });
    await loadData();
    return;
  }
  openEntryDialog(habit, date);
};

const openHabitDialog = (habit = null) => {
  $("#habit-error").textContent = "";
  $("#habit-dialog-title").textContent = habit ? "Edit habit" : "Add habit";
  $("#habit-id").value = habit?.id || "";
  $("#habit-name").value = habit?.name || "";
  $("#habit-question").value = habit?.question || "";
  $("#habit-type").value = habit?.type || "boolean";
  $("#habit-color").value = habit?.color || "#66bb6a";
  $("#habit-target").value = habit?.target || 1;
  $("#habit-unit").value = habit?.unit || "";
  $("#schedule-type").value = habit?.schedule_type || "daily";
  $("#times-per-week").value = habit?.schedule?.times || 3;
  $$("#weekday-picker input").forEach((input) => {
    input.checked = (habit?.schedule?.days || [1, 2, 3, 4, 5, 6, 7]).includes(Number(input.value));
  });
  $("#delete-habit-button").hidden = !habit;
  syncHabitForm();
  habitDialog.showModal();
  $("#habit-name").focus();
};

const syncHabitForm = () => {
  const type = $("#habit-type").value;
  const schedule = $("#schedule-type").value;
  $("#measure-fields").hidden = type !== "measurable";
  $("#weekday-picker").hidden = schedule !== "weekdays";
  $("#times-field").hidden = schedule !== "times_per_week";
};

const saveHabit = async () => {
  const scheduleType = $("#schedule-type").value;
  const schedule = {};
  if (scheduleType === "weekdays") {
    schedule.days = $$("#weekday-picker input:checked").map((input) => Number(input.value));
  }
  if (scheduleType === "times_per_week") {
    schedule.times = Number($("#times-per-week").value || 1);
  }

  await api("save_habit", {
    method: "POST",
    body: JSON.stringify({
      id: $("#habit-id").value ? Number($("#habit-id").value) : null,
      name: $("#habit-name").value,
      question: $("#habit-question").value,
      type: $("#habit-type").value,
      color: $("#habit-color").value,
      target: Number($("#habit-target").value || 1),
      unit: $("#habit-unit").value,
      schedule_type: scheduleType,
      schedule,
    }),
  });
};

const openEntryDialog = (habit, date) => {
  const entry = getEntry(habit.id, date);
  state.editingEntry = { habit, date };
  $("#entry-title").textContent = habit.name;
  $("#entry-date-label").textContent = `${displayDate(date)} - target ${habit.target} ${habit.unit || ""}`.trim();
  $("#entry-value").value = entry?.value || "";
  $("#entry-note").value = entry?.note || "";
  entryDialog.showModal();
  $("#entry-value").focus();
};

const saveEntry = async (value = null) => {
  const { habit, date } = state.editingEntry;
  await api("set_entry", {
    method: "POST",
    body: JSON.stringify({
      habit_id: habit.id,
      date,
      value: value ?? Number($("#entry-value").value || 0),
      note: $("#entry-note").value,
    }),
  });
};

const showAuth = (setupRequired) => {
  state.setupRequired = setupRequired;
  appShell.hidden = true;
  authScreen.hidden = false;
  authTitle.textContent = setupRequired ? "Create password" : "GHabits";
  authCopy.textContent = setupRequired
    ? "Choose the single password for this private tracker."
    : "Enter your password to open your habit tracker.";
  authSubmit.textContent = setupRequired ? "Create and open" : "Unlock";
  passwordInput.autocomplete = setupRequired ? "new-password" : "current-password";
  passwordInput.focus();
};

const showApp = async () => {
  authScreen.hidden = true;
  appShell.hidden = false;
  await loadData();
};

authForm.addEventListener("submit", async (event) => {
  event.preventDefault();
  authError.textContent = "";
  authSubmit.disabled = true;
  try {
    const action = state.setupRequired ? "setup" : "login";
    await api(action, { method: "POST", body: JSON.stringify({ password: passwordInput.value }) });
    passwordInput.value = "";
    await showApp();
  } catch (error) {
    authError.textContent = error.message;
  } finally {
    authSubmit.disabled = false;
  }
});

$("#add-button").addEventListener("click", () => openHabitDialog());
$("[data-open-habit]").addEventListener("click", () => openHabitDialog());
$("#habit-type").addEventListener("change", syncHabitForm);
$("#schedule-type").addEventListener("change", syncHabitForm);
$$("[data-close-dialog]").forEach((button) => button.addEventListener("click", () => habitDialog.close()));
$$("[data-close-entry]").forEach((button) => button.addEventListener("click", () => entryDialog.close()));

habitForm.addEventListener("submit", async (event) => {
  event.preventDefault();
  $("#habit-error").textContent = "";
  try {
    await saveHabit();
    habitDialog.close();
    await loadData();
  } catch (error) {
    $("#habit-error").textContent = error.message;
  }
});

$("#delete-habit-button").addEventListener("click", async () => {
  const id = Number($("#habit-id").value);
  if (!id || !confirm("Delete this habit and its history?")) return;
  await api("delete_habit", { method: "POST", body: JSON.stringify({ id }) });
  habitDialog.close();
  await loadData();
});

entryForm.addEventListener("submit", async (event) => {
  event.preventDefault();
  await saveEntry();
  entryDialog.close();
  await loadData();
});

$("#clear-entry-button").addEventListener("click", async () => {
  await saveEntry(0);
  entryDialog.close();
  await loadData();
});

$("#logout-button").addEventListener("click", async () => {
  await api("logout", { method: "POST", body: JSON.stringify({}) });
  state.csrfToken = null;
  showAuth(false);
});

$("#export-button").addEventListener("click", async () => {
  const data = await api("export");
  const blob = new Blob([JSON.stringify(data, null, 2)], { type: "application/json" });
  const link = document.createElement("a");
  link.href = URL.createObjectURL(blob);
  link.download = `ghabits-${isoDate(new Date())}.json`;
  link.click();
  URL.revokeObjectURL(link.href);
});

document.addEventListener("keydown", (event) => {
  if (event.key === "Escape") {
    if (habitDialog.open) habitDialog.close();
    if (entryDialog.open) entryDialog.close();
  }
});

(async () => {
  try {
    loadVisibleDays();
    const status = await api("status");
    buildDates();
    if (status.authenticated) {
      state.csrfToken = status.csrfToken;
      await showApp();
    } else {
      showAuth(status.setupRequired);
    }
  } catch (error) {
    showAuth(false);
    authError.textContent = "Could not start the app. Check PHP and SQLite support.";
  }
})();

