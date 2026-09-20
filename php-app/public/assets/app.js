/* ==========================================================================
   LinkEasy Publisher — progressive enhancement only.
   The dashboard works fully without JavaScript; this file adds conveniences:
   CSRF-token injection, async form posts, live queue polling and page picking.
   ========================================================================== */
(function () {
    'use strict';

    var csrfToken = document.querySelector('meta[name="csrf-token"]');
    csrfToken = csrfToken ? csrfToken.getAttribute('content') : '';

    /** POST a form (or a bare URL) asynchronously and return parsed JSON. */
    function post(url, data) {
        var body = new FormData();
        body.append('_csrf', csrfToken);
        Object.keys(data || {}).forEach(function (key) {
            var value = data[key];
            if (Array.isArray(value)) {
                value.forEach(function (item) { body.append(key + '[]', item); });
            } else {
                body.append(key, value);
            }
        });

        return fetch(url, {
            method: 'POST',
            body: body,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
        }).then(function (response) {
            return response.json().catch(function () { return { ok: response.ok }; })
                .then(function (payload) { return { status: response.status, payload: payload }; });
        });
    }

    function flash(kind, message) {
        var host = document.getElementById('flash-host');
        if (!host) { return; }
        var el = document.createElement('div');
        el.className = 'flash flash--' + kind;
        el.textContent = message;
        host.prepend(el);
        window.setTimeout(function () { el.remove(); }, 6000);
    }

    /* ---- 1. Async actions: [data-action] posts to its own URL ---- */
    document.addEventListener('click', function (event) {
        var el = event.target.closest('[data-action]');
        if (!el) { return; }

        var url = el.getAttribute('data-action');
        var confirmText = el.getAttribute('data-confirm');
        var method = (el.getAttribute('data-method') || 'POST').toUpperCase();

        if (confirmText && !window.confirm(confirmText)) {
            event.preventDefault();
            return;
        }
        if (method !== 'POST' || el.tagName === 'FORM') { return; }

        event.preventDefault();
        var data = {};
        if (el.dataset.jobId) { data.job_ids = [el.dataset.jobId]; }

        el.classList.add('is-disabled');
        post(url, data).then(function (result) {
            el.classList.remove('is-disabled');
            if (result.status < 400 && result.payload.ok !== false) {
                flash('success', el.getAttribute('data-success') || 'Done.');
                if (el.getAttribute('data-reload') === 'true') {
                    window.setTimeout(function () { window.location.reload(); }, 600);
                }
            } else {
                flash('error', (result.payload && result.payload.error) || 'That action could not be completed.');
                if (result.payload && result.payload.details && result.payload.details.confirm_required) {
                    if (window.confirm((result.payload.error || '') + '\n\nProceed anyway?')) {
                        data.force = '1';
                        post(url, data).then(function () { window.location.reload(); });
                    }
                }
            }
        }).catch(function () {
            el.classList.remove('is-disabled');
            flash('error', 'The request could not be sent. Check your connection.');
        });
    });

    /* ---- 2. Live queue polling ---- */
    var live = document.querySelector('[data-poll]');
    if (live) {
        var interval = parseInt(live.getAttribute('data-poll'), 10) || 15000;
        window.setInterval(function () {
            fetch(live.getAttribute('data-poll-source') || '/api/status', {
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' }
            }).then(function (r) { return r.ok ? r.json() : null; }).then(function (data) {
                if (!data || !data.jobs) { return; }
                Object.keys(data.jobs).forEach(function (key) {
                    var node = document.querySelector('[data-stat="' + key + '"]');
                    if (node && node.textContent.trim() !== String(data.jobs[key])) {
                        node.textContent = data.jobs[key];
                        node.classList.add('is-updated');
                    }
                });
            }).catch(function () { /* offline: the PHP app remains the source of truth */ });
        }, interval);
    }

    /* ---- 3. Select-all helpers ---- */
    document.querySelectorAll('[data-check-all]').forEach(function (master) {
        master.addEventListener('change', function () {
            var scope = document.querySelector(master.getAttribute('data-check-all'));
            if (!scope) { return; }
            scope.querySelectorAll('input[type=checkbox][name="job_ids[]"], input[type=checkbox][name="page_ids[]"]')
                .forEach(function (box) { box.checked = master.checked; });
        });
    });

    /* ---- 4. Character counter for the composer ---- */
    var caption = document.getElementById('caption');
    var counter = document.getElementById('caption-counter');
    if (caption && counter) {
        var update = function () {
            counter.textContent = caption.value.length.toLocaleString() + ' characters';
        };
        caption.addEventListener('input', update);
        update();
    }

    /* ---- 5. Composer: intent buttons + scheduling panel ---- */
    var schedulePanel = document.getElementById('schedule-panel');
    document.querySelectorAll('[data-intent]').forEach(function (button) {
        button.addEventListener('click', function () {
            if (schedulePanel) {
                schedulePanel.hidden = button.getAttribute('data-intent') !== 'schedule';
            }
        });
    });

    /* ---- 6. Media picker preview ---- */
    document.querySelectorAll('[data-media-pick]').forEach(function (card) {
        card.addEventListener('click', function () {
            var input = document.getElementById(card.getAttribute('data-media-pick'));
            if (!input) { return; }
            document.querySelectorAll('.media-card.is-selected').forEach(function (other) {
                other.classList.remove('is-selected');
            });
            document.querySelectorAll('input[name="media_id"]').forEach(function (field) {
                field.value = card.getAttribute('data-media-id');
            });
            card.classList.add('is-selected');
            var preview = document.getElementById('media-preview');
            if (preview) {
                preview.innerHTML = card.getAttribute('data-media-preview') || '';
            }
            if (input) { input.value = card.getAttribute('data-media-id'); }
        });
    });

    /* ---- 7. Confirm-guarded destructive forms ---- */
    document.querySelectorAll('form[data-confirm]').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            if (!window.confirm(form.getAttribute('data-confirm'))) {
                event.preventDefault();
            }
        });
    });

    /* ---- 8. Toggle password visibility ---- */
    document.querySelectorAll('[data-toggle-visibility]').forEach(function (button) {
        button.addEventListener('click', function () {
            var field = document.getElementById(button.getAttribute('data-toggle-visibility'));
            if (!field) { return; }
            field.type = field.type === 'password' ? 'text' : 'password';
        });
    });
})();
