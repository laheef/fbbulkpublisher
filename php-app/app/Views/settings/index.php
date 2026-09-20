<?php
declare(strict_types=1);
use App\Core\Support;
use App\Core\Ui;

/** @var array $settings @var list<string> $timezones @var list<object> $providers @var string $activeProvider @var string|null $ffmpeg */
$providerLabels = [];
foreach ($providers as $provider) {
    $providerLabels[$provider->key()] = $provider->label();
}
$checked = static fn (string $key): string => !empty($settings[$key]) ? 'checked' : '';
?>
<div class="page-head">
    <div class="page-head__text">
        <h1>Settings</h1>
        <p class="page-head__sub">
            These preferences are handed to your Windows machines on the next heartbeat, so changes apply without reinstalling anything.
        </p>
    </div>
    <div class="page-head__actions">
        <a class="btn" href="/settings/profile"><?= Ui::icon('accounts', 16) ?> Your profile</a>
    </div>
</div>

<form method="post" action="/settings">
    <input type="hidden" name="_csrf" value="<?= Support::e($csrfToken) ?>">

    <div class="grid grid--sidebar">
        <div class="stack">
            <section class="card">
                <div class="card__head"><h2>Automation</h2></div>
                <div class="card__body">
                    <div class="form-row">
                        <div class="field">
                            <label class="field__label" for="max_concurrent_jobs">Concurrent jobs per machine</label>
                            <input id="max_concurrent_jobs" name="max_concurrent_jobs" type="number" min="1" max="32"
                                   value="<?= (int) $settings['max_concurrent_jobs'] ?>">
                            <p class="field__hint">Total parallel publishing work on one PC.</p>
                        </div>
                        <div class="field">
                            <label class="field__label" for="max_concurrent_browsers">Concurrent browsers</label>
                            <input id="max_concurrent_browsers" name="max_concurrent_browsers" type="number" min="1" max="16"
                                   value="<?= (int) $settings['max_concurrent_browsers'] ?>">
                            <p class="field__hint">Each Chromium instance uses roughly 300–500 MB of RAM.</p>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="field">
                            <label class="field__label" for="max_jobs_per_worker">Jobs claimed per poll</label>
                            <input id="max_jobs_per_worker" name="max_jobs_per_worker" type="number" min="1" max="500"
                                   value="<?= (int) $settings['max_jobs_per_worker'] ?>">
                        </div>
                        <div class="field">
                            <label class="field__label" for="idle_browser_timeout_s">Close idle browsers after (s)</label>
                            <input id="idle_browser_timeout_s" name="idle_browser_timeout_s" type="number" min="30" max="7200"
                                   value="<?= (int) $settings['idle_browser_timeout_s'] ?>">
                        </div>
                    </div>

                    <div class="field">
                        <label class="field__label" for="trace_mode">Playwright traces</label>
                        <select id="trace_mode" name="trace_mode">
                            <?php foreach (['off' => 'Off', 'failures_only' => 'Failures only (recommended)', 'all' => 'All runs (developer use)'] as $value => $label): ?>
                                <option value="<?= Support::e($value) ?>"<?= $settings['trace_mode'] === $value ? ' selected' : '' ?>><?= Support::e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="field__hint">
                            Traces are stored on your machine only and cleaned up after <?= (int) $settings['retention_traces_d'] ?> days.
                            They never contain cookies or credentials, and they are never uploaded here.
                        </p>
                    </div>
                </div>
            </section>

            <section class="card">
                <div class="card__head"><h2>Media</h2></div>
                <div class="card__body">
                    <div class="field">
                        <label class="field__label" for="media_strategy">Media strategy</label>
                        <select id="media_strategy" name="media_strategy">
                            <?php foreach (['SERVER' => 'Server storage (default)', 'LOCAL' => 'Local only (machine holds the file)', 'EXTERNAL' => 'External URL', 'HYBRID' => 'Hybrid (server catalog, local copy)'] as $value => $label): ?>
                                <option value="<?= Support::e($value) ?>"<?= $settings['media_strategy'] === $value ? ' selected' : '' ?>><?= Support::e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="field__hint">
                            With server storage, your machine downloads a file only for the job that needs it and can
                            delete it afterwards — so a laptop never fills up with video.
                        </p>
                    </div>

                    <label class="check">
                        <input type="checkbox" name="cleanup_after_publish" value="1" <?= $checked('cleanup_after_publish') ?>>
                        <span class="check__text">Delete local copies after a successful publication
                            <span class="check__meta">Files still referenced by another scheduled job are always kept.</span></span>
                    </label>
                </div>
            </section>

            <section class="card">
                <div class="card__head"><h2>Windows application</h2></div>
                <div class="card__body">
                    <label class="check">
                        <input type="checkbox" name="start_with_windows" value="1" <?= $checked('start_with_windows') ?>>
                        <span class="check__text">Start LinkEasy Publisher with Windows</span>
                    </label>
                    <label class="check">
                        <input type="checkbox" name="minimize_to_tray" value="1" <?= $checked('minimize_to_tray') ?>>
                        <span class="check__text">Minimise to the system tray</span>
                    </label>
                    <label class="check">
                        <input type="checkbox" name="auto_update" value="1" <?= $checked('auto_update') ?>>
                        <span class="check__text">Install signed updates automatically
                            <span class="check__meta">Unsigned updates are never installed.</span></span>
                    </label>
                    <label class="check">
                        <input type="checkbox" name="capture_screenshots" value="1" <?= $checked('capture_screenshots') ?>>
                        <span class="check__text">Capture a screenshot when a job fails
                            <span class="check__meta">Useful for support; visible only to you.</span></span>
                    </label>
                </div>
            </section>

            <section class="card">
                <div class="card__head"><h2>Diagnostics</h2></div>
                <div class="card__body">
                    <label class="check">
                        <input type="checkbox" name="debug_mode" value="1" <?= $checked('debug_mode') ?>>
                        <span class="check__text">Developer mode
                            <span class="check__meta">Adds the worker console, browser logs and data-directory shortcuts to the desktop app.</span></span>
                    </label>
                    <label class="check">
                        <input type="checkbox" name="verbose_logs" value="1" <?= $checked('verbose_logs') ?>>
                        <span class="check__text">Verbose logging
                            <span class="check__meta">More detail on disk; no effect on what is sent to Facebook.</span></span>
                    </label>
                </div>
            </section>
        </div>

        <div class="stack">
            <section class="card">
                <div class="card__head"><h2>Save</h2></div>
                <div class="card__body">
                    <button class="btn btn--primary btn--block" type="submit"><?= Ui::icon('check', 16) ?> Save settings</button>
                    <p class="tiny muted mt-1">Machines pick changes up within one heartbeat (about 20 seconds).</p>
                </div>
            </section>

            <section class="card">
                <div class="card__head"><h2>Timezone</h2></div>
                <div class="card__body">
                    <div class="field">
                        <label class="field__label" for="timezone">Display timezone</label>
                        <select id="timezone" name="timezone">
                            <?php foreach ($timezones as $tz): ?>
                                <option value="<?= Support::e($tz) ?>"<?= $timezone === $tz ? ' selected' : '' ?>><?= Support::e($tz) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <p class="tiny muted mb-0">
                        Schedules are always stored in UTC. Changing this only changes how times are shown.
                    </p>
                </div>
            </section>

            <section class="card">
                <div class="card__head"><h2>Publishing provider</h2></div>
                <div class="card__body">
                    <p class="small mb-1">Active: <strong><?= Support::e($providerLabels[$activeProvider] ?? $activeProvider) ?></strong></p>
                    <ul class="small muted" style="margin:0;padding-left:18px">
                        <?php foreach ($providerLabels as $key => $label): ?>
                            <li><?= Support::e($label) ?><?= $key === $activeProvider ? ' — active' : '' ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <p class="tiny muted mt-1 mb-0">
                        The provider is chosen by configuration, so switching to an official API later does not change
                        your queue, schedules or history.
                    </p>
                </div>
            </section>

            <section class="card">
                <div class="card__head"><h2>Retention</h2></div>
                <div class="card__body">
                    <div class="form-row">
                        <div class="field">
                            <label class="field__label" for="retention_screenshots_d">Screenshots (days)</label>
                            <input id="retention_screenshots_d" name="retention_screenshots_d" type="number" min="1" max="365"
                                   value="<?= (int) $settings['retention_screenshots_d'] ?>">
                        </div>
                        <div class="field">
                            <label class="field__label" for="retention_traces_d">Traces (days)</label>
                            <input id="retention_traces_d" name="retention_traces_d" type="number" min="1" max="365"
                                   value="<?= (int) $settings['retention_traces_d'] ?>">
                        </div>
                    </div>
                    <?php if ($ffmpeg !== null): ?>
                        <p class="tiny muted mb-0">Server FFmpeg: <span class="mono"><?= Support::e(Support::truncate($ffmpeg, 48)) ?></span></p>
                    <?php endif; ?>
                </div>
            </section>

            <section class="card">
                <div class="card__head"><h2>Account security</h2></div>
                <div class="card__body">
                    <a class="btn btn--block" href="/settings/profile"><?= Ui::icon('shield', 16) ?> Password & profile</a>
                </div>
            </section>
        </div>
    </div>
</form>
