'use strict';

/**
 * Desktop dashboard renderer.
 *
 * Talks only to the preload bridge (window.linkeasy). No network calls, no
 * credentials on screen, no Facebook data beyond what the operator typed in.
 */

const $ = (id) => document.getElementById(id);
const bridge = window.linkeasy;

const state = {
    status: null,
    runtime: null,
    settings: null,
    notifications: [],
    activity: [],
    logsOpen: false,
    attention: null,
};

// ---------------------------------------------------------------- navigation

function showView(name) {
    document.querySelectorAll('.view').forEach((view) => view.classList.add('hidden'));
    const target = $(`view-${name}`);
    if (target) {
        target.classList.remove('hidden');
    }
    document.querySelectorAll('.nav__item').forEach((item) => {
        item.classList.toggle('is-active', item.dataset.view === name);
        // The welcome screen has no nav entry; keep the previous one highlighted.
        if (name === 'welcome') {
            item.classList.remove('is-active');
        }
    });

    if (name === 'logs') {
        state.logsOpen = true;
        loadLogs();
    } else {
        state.logsOpen = false;
    }

    if (name === 'runtime') {
        refreshRuntime();
    }
    if (name === 'accounts') {
        loadProfiles();
        renderLimits();
    }
    if (name === 'settings') {
        loadSettings();
        loadAbout();
    }
    if (name === 'overview') {
        renderNotifications();
    }
}

document.querySelectorAll('.nav__item').forEach((item) => {
    item.addEventListener('click', () => showView(item.dataset.view));
});

// ---------------------------------------------------------------- rendering

function renderStatus(status) {
    if (!status) {
        return;
    }
    state.status = status;

    const dot = $('side-dot');
    if (dot) {
        dot.dataset.state = status.state || 'OFFLINE';
    }
    setText('side-state', labelForState(status.state, status.paused));
    setText('stat-state', labelForState(status.state, status.paused));
    setText('stat-active', String((status.activeJobs || []).length));
    setText('stat-browsers', String((status.browsers || []).filter((b) => b.connected).length));
    setText('stat-published', String(status.stats?.published ?? 0));
    setText('stat-failed', String(status.stats?.failed ?? 0));
    setText('stat-uptime', humanDuration(status.stats?.uptimeSeconds ?? 0));

    const jobs = status.activeJobs || [];
    const list = $('active-jobs');
    if (list) {
        list.innerHTML = jobs.length === 0
            ? '<li class="muted">Nothing running.</li>'
            : jobs.map((job) => `<li>${escapeHtml(job.page || 'Account')} — ${escapeHtml(job.type)} · ${job.seconds}s</li>`).join('');
    }

    const pauseBtn = $('btn-pause');
    if (pauseBtn) {
        pauseBtn.textContent = status.paused ? 'Resume' : 'Pause';
        pauseBtn.dataset.intent = status.paused ? 'resume' : 'pause';
    }

    if (status.pausedReason && status.pausedReason.code !== 'OPERATOR_PAUSED') {
        showAttention(status.pausedReason);
    }
}

function labelForState(value, paused) {
    if (paused) {
        return 'Paused — waiting for you';
    }
    return {
        ONLINE: 'Connected and ready',
        BUSY: 'Publishing',
        PAUSED: 'Paused',
        DEGRADED: 'Offline — retrying',
        STARTING: 'Starting',
        OFFLINE: 'Stopped',
        UPDATING: 'Updating',
        ERROR: 'Needs attention',
    }[value] || value || '—';
}

function showAttention(info) {
    state.attention = info;
    const card = $('attention-card');
    if (!card) {
        return;
    }
    card.hidden = false;
    $('attention-body').textContent = info.message
        || 'Facebook is asking for something only you can do — finish it in the browser window, then press Resume.';
}

function renderRuntime(report) {
    state.runtime = report;

    const list = $('runtime-list');
    if (!list) {
        return;
    }

    if (!report) {
        list.innerHTML = '<li class="muted">Checking…</li>';
        return;
    }

    list.innerHTML = report.components.map((component) => {
        const tone = component.status === 'ok' ? 'ok' : (component.status === 'version-mismatch' ? 'warn' : 'bad');
        const mark = component.status === 'ok' ? '✓' : (component.status === 'version-mismatch' ? '!' : '×');
        const version = component.version ? ` <span class="muted small">${escapeHtml(component.version)}</span>` : '';
        const detail = component.status === 'ok' ? '' : `<div class="muted small">${escapeHtml(component.detail)}</div>`;
        return `<li><span class="mark ${tone}">${mark}</span><span>${escapeHtml(component.label)}${version}${detail}</span></li>`;
    }).join('');

    const welcome = $('welcome-runtime');
    if (welcome) {
        welcome.innerHTML = list.innerHTML;
    }
}

function renderNotifications() {
    const list = $('notifications');
    if (!list) {
        return;
    }
    if (state.notifications.length === 0) {
        list.innerHTML = '<li class="muted">Nothing yet.</li>';
        return;
    }
    list.innerHTML = state.notifications.slice(0, 8).map((entry) => `
        <li>
            <strong>${escapeHtml(entry.title)}</strong>
            <div class="muted small">${escapeHtml(entry.body)}</div>
        </li>`).join('');
}

function renderActivity() {
    const list = $('job-log');
    if (!list) {
        return;
    }
    if (state.activity.length === 0) {
        list.innerHTML = '<li class="muted">Nothing yet.</li>';
        return;
    }
    list.innerHTML = state.activity.slice(0, 60).map((entry) => `
        <li>${escapeHtml(entry.at)} · ${escapeHtml(entry.text)}</li>`).join('');
}

function renderLimits() {
    const list = $('limits');
    if (!list || !state.status) {
        return;
    }
    const limits = state.status.limits || {};
    list.innerHTML = `
        <li>Jobs at once: <strong>${limits.maxConcurrentJobs ?? '—'}</strong></li>
        <li>Browsers at once: <strong>${limits.maxConcurrentBrowsers ?? '—'}</strong></li>
        <li>Jobs per claim: <strong>${limits.maxJobsPerWorker ?? '—'}</strong></li>`;
}

// ---------------------------------------------------------------- actions

$('enrol-submit')?.addEventListener('click', async () => {
    const button = $('enrol-submit');
    const error = $('enrol-error');
    error.classList.add('hidden');
    button.disabled = true;
    button.textContent = 'Connecting…';

    const result = await bridge.enrol({
        serverUrl: $('enrol-server').value.trim(),
        email: $('enrol-email').value.trim(),
        password: $('enrol-password').value,
        name: $('enrol-name').value.trim(),
    });

    button.disabled = false;
    button.textContent = 'Connect this PC';

    if (!result || result.ok === false) {
        error.textContent = (result && result.error) || 'The workspace could not be reached.';
        error.classList.remove('hidden');
        return;
    }

    // Clear the password field as soon as it has been used.
    $('enrol-password').value = '';
    showView('overview');
    addActivity('This PC was connected to the workspace.');
});

$('btn-pause')?.addEventListener('click', async (event) => {
    const intent = event.currentTarget.dataset.intent === 'resume' ? 'resume' : 'pause';
    const result = intent === 'resume' ? await bridge.resume() : await bridge.pause();
    if (result?.data) {
        renderStatus(result.data);
        if (intent === 'resume') {
            state.attention = null;
            $('attention-card').hidden = true;
        }
    }
});

$('btn-resume')?.addEventListener('click', async () => {
    const result = await bridge.resume();
    if (result?.data) {
        renderStatus(result.data);
    }
    state.attention = null;
    $('attention-card').hidden = true;
});

$('btn-heartbeat')?.addEventListener('click', async () => {
    const result = await bridge.heartbeatNow();
    if (result?.data) {
        renderStatus(result.data);
    }
    addActivity('Heartbeat sent.');
});

$('btn-open-dashboard')?.addEventListener('click', () => bridge.openWorkspaceDashboard());
$('btn-focus-browser')?.addEventListener('click', () => bridge.openBrowserWindow());
$('btn-open-logs')?.addEventListener('click', () => bridge.openLogsFolder());
$('btn-open-data')?.addEventListener('click', () => bridge.openDataFolder());
$('btn-verify')?.addEventListener('click', () => refreshRuntime(true));
$('btn-repair')?.addEventListener('click', async () => {
    $('btn-repair').disabled = true;
    const result = await bridge.repairRuntime();
    renderRuntime(result?.after || result);
    $('btn-repair').disabled = false;
    addActivity('Component repair finished.');
});
$('btn-refresh-accounts')?.addEventListener('click', () => loadProfiles());

$('btn-connect-account')?.addEventListener('click', async () => {
    const accountId = ($('connect-account-id').value || '').trim();
    if (!accountId) {
        addActivity('Enter the account id shown in your web dashboard first.');
        return;
    }
    addActivity(`Opening Facebook sign-in for account ${accountId}…`);
    const result = await bridge.connectFacebookAccount(accountId);
    addActivity(result?.ok
        ? `Signed in. ${result.pages} Page(s) found.`
        : `Sign-in not completed: ${result?.error || 'unknown reason'}`);
});

$('btn-check-updates')?.addEventListener('click', async () => {
    const result = await bridge.checkForUpdates();
    renderUpdate(result);
});

$('btn-install-update')?.addEventListener('click', async () => {
    const result = await bridge.installUpdate();
    if (result && result.installed === false && result.reason === 'busy') {
        $('update-state').textContent = 'A post is being published. The update will install as soon as it finishes.';
    }
});

$('btn-signout')?.addEventListener('click', async () => {
    await bridge.signOut();
    addActivity('This PC was disconnected from the workspace.');
    showView('welcome');
});

$('btn-quit')?.addEventListener('click', () => bridge.quit());

const toggles = {
    'set-startup': 'worker.startWithWindows',
    'set-tray': 'worker.minimizeToTray',
    'set-cleanup': 'media.cleanupAfterPublish',
    'set-screenshots': 'diagnostics.captureScreenshots',
    'set-verbose': 'diagnostics.verboseLogs',
    'set-updates': 'updater.enabled',
};

Object.entries(toggles).forEach(([id, key]) => {
    $(id)?.addEventListener('change', async (event) => {
        await bridge.saveSettings({ [key]: event.target.checked });
        addActivity(`Setting updated: ${key}`);
    });
});

$('log-channel')?.addEventListener('change', () => loadLogs());

// ---------------------------------------------------------------- data loads

async function refreshRuntime(verbose = false) {
    const report = await bridge.getRuntime();
    renderRuntime(report);
    if (verbose) {
        addActivity(report.ok ? 'All components are healthy.' : 'Some components need repair.');
    }
}

async function loadProfiles() {
    const profiles = await bridge.getProfiles();
    const list = $('profiles');
    if (!list) {
        return;
    }
    if (!profiles || profiles.length === 0) {
        list.innerHTML = '<li class="muted">No browser profile on this PC yet — sign in to Facebook to create one.</li>';
        return;
    }
    list.innerHTML = profiles.map((profile) => `
        <li>${escapeHtml(profile.profile)} <span class="muted small">(updated ${escapeHtml(shortDate(profile.modifiedAt))})</span></li>`).join('');
}

async function loadSettings() {
    const settings = await bridge.getSettings();
    state.settings = settings;
    Object.entries(toggles).forEach(([id, key]) => {
        const element = $(id);
        if (!element) {
            return;
        }
        element.checked = readPath(settings, key) === true;
    });
}

async function loadAbout() {
    const info = await bridge.version();
    const list = $('about');
    if (!list) {
        return;
    }
    list.innerHTML = `
        <li>Application <strong>${escapeHtml(info.app)}</strong></li>
        <li>Worker <strong>${escapeHtml(info.worker)}</strong></li>
        <li>Node ${escapeHtml(info.node)} · Chromium ${escapeHtml(info.chrome)}</li>
        <li>${escapeHtml(info.platform)}</li>
        <li>Data folder <span class="muted small">${escapeHtml(info.dataRoot)}</span></li>
        <li>${info.portable ? 'Portable mode' : 'Installed mode'}</li>`;
    setText('side-version', `v${info.app}`);
}

async function loadLogs() {
    const channel = $('log-channel')?.value || 'app';
    const result = await bridge.getLogs(channel, 300);
    const box = $('logbox');
    if (!box) {
        return;
    }
    const lines = (result && result.lines) || [];
    box.textContent = lines.length === 0
        ? 'No entries in this channel yet.'
        : lines.map((line) => `${line.ts || ''} ${String(line.level || '').toUpperCase()} ${line.message || ''}`.trim()).join('\n');
    box.scrollTop = box.scrollHeight;
}

async function loadNotifications() {
    state.notifications = (await bridge.getNotifications(false)) || [];
    renderNotifications();
}

function renderUpdate(update) {
    if (!update) {
        return;
    }
    const map = {
        checking: 'Checking for updates…',
        'up-to-date': 'You are running the latest version.',
        available: `Version ${update.version} is available and downloading.`,
        downloading: `Downloading… ${update.percent || 0}%`,
        ready: `Version ${update.version} is ready to install.`,
        rejected: update.message || 'The update was refused because it could not be verified.',
        error: update.message || 'The update check failed. Nothing was changed.',
    };
    setText('update-state', map[update.status] || update.status);
    const install = $('btn-install-update');
    if (install) {
        install.disabled = update.status !== 'ready';
    }
}

// ---------------------------------------------------------------- events

bridge.on.status((status) => renderStatus(status));
bridge.on.progress((info) => addActivity(`Job ${info.jobId}: ${info.message || info.stage} (${info.progress}%)`));
bridge.on.job((info) => {
    addActivity(info.kind === 'start'
        ? `Started ${info.job?.job_type || 'job'} for ${info.job?.page?.name || 'a Page'}`
        : (info.ok ? `Job ${info.jobId} finished` : `Job ${info.jobId} failed: ${info.code || 'unknown'}`));
});
bridge.on.challenge((info) => showAttention({
    message: info.message || 'Finish the security check in the browser window, then press Resume.',
    code: info.code,
}));
bridge.on.notification(() => loadNotifications());
bridge.on.update((update) => renderUpdate(update));
bridge.on.runtime((report) => renderRuntime(report));
bridge.on.navigate((route) => showView(route));

// ---------------------------------------------------------------- helpers

function addActivity(text) {
    state.activity.unshift({ at: new Date().toLocaleTimeString(), text });
    if (state.activity.length > 200) {
        state.activity.length = 200;
    }
    renderActivity();
}

function setText(id, value) {
    const element = $(id);
    if (element) {
        element.textContent = value;
    }
}

function readPath(object, path) {
    return path.split('.').reduce((cursor, segment) => (cursor == null ? undefined : cursor[segment]), object);
}

function humanDuration(seconds) {
    if (!seconds) {
        return '—';
    }
    const hours = Math.floor(seconds / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);
    return hours > 0 ? `${hours}h ${minutes}m` : `${minutes}m`;
}

function shortDate(iso) {
    try {
        return new Date(iso).toLocaleString();
    } catch {
        return iso;
    }
}

function escapeHtml(value) {
    return String(value == null ? '' : value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

// ---------------------------------------------------------------- start-up

(async function start() {
    const info = await bridge.version();
    setText('side-version', `v${info.app}`);

    await refreshRuntime();
    await loadNotifications();
    await loadAbout();

    const status = await bridge.getStatus();
    renderStatus(status);
    showView(status && status.state !== 'OFFLINE' ? 'overview' : 'welcome');

    setInterval(async () => {
        const fresh = await bridge.getStatus();
        renderStatus(fresh);
        if (state.logsOpen) {
            loadLogs();
        }
    }, 5000);
}());
