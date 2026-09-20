<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Clock;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;
use App\Models\Setting;
use App\Models\User;

final class SettingsController extends Controller
{
    public function index(Request $request): Response
    {
        $userId = $this->requireUserId();

        return $this->view('settings/index', [
            'title'     => 'Settings',
            'settings'  => Setting::effectiveForWorker($userId),
            'timezones' => Clock::commonTimezones(),
            'providers' => \App\Services\Publishing\ProviderFactory::all(),
            'activeProvider' => \App\Services\Publishing\ProviderFactory::currentKey(),
            'ffmpeg'    => \App\Services\MediaProbe::version('ffmpeg'),
        ]);
    }

    public function update(Request $request): Response
    {
        $userId = $this->requireUserId();

        $validator = Validator::make($request->all(), ['timezone' => 'Timezone'])
            ->timezone('timezone')
            ->integer('max_concurrent_browsers', 1, 16)
            ->integer('max_concurrent_jobs', 1, 32)
            ->integer('max_jobs_per_worker', 1, 500)
            ->integer('idle_browser_timeout_s', 30, 7200)
            ->integer('retention_screenshots_d', 1, 365)
            ->integer('retention_traces_d', 1, 365)
            ->in('trace_mode', ['off', 'failures_only', 'all'])
            ->in('media_strategy', ['SERVER', 'LOCAL', 'EXTERNAL', 'HYBRID']);

        if ($validator->fails()) {
            throw HttpException::validation('Please correct the highlighted settings.', $validator->errors());
        }

        $validated = $validator->validated();

        if (isset($validated['timezone'])) {
            User::setTimezone($userId, (string) $validated['timezone']);
            Session::put('user_tz', (string) $validated['timezone']);
        }

        $numericKeys = ['max_concurrent_browsers', 'max_concurrent_jobs', 'max_jobs_per_worker',
                        'idle_browser_timeout_s', 'retention_screenshots_d', 'retention_traces_d'];
        foreach ($numericKeys as $key) {
            if (isset($validated[$key])) {
                Setting::setValue($key, (string) $validated[$key], 'user', $userId);
            }
        }

        foreach (['trace_mode', 'media_strategy'] as $key) {
            if (isset($validated[$key])) {
                Setting::setValue($key, (string) $validated[$key], 'user', $userId);
            }
        }

        $booleanKeys = ['start_with_windows', 'minimize_to_tray', 'auto_update', 'debug_mode',
                        'verbose_logs', 'capture_screenshots', 'cleanup_after_publish'];
        foreach ($booleanKeys as $key) {
            if ($request->has($key)) {
                Setting::setValue($key, $request->bool($key) ? '1' : '0', 'user', $userId);
            }
        }

        $this->log('settings.updated', 'settings', $userId);

        if ($request->expectsJson()) {
            return $this->json(['settings' => Setting::effectiveForWorker($userId)]);
        }

        Session::flash('success', 'Settings saved.');
        return $this->redirect('/settings');
    }

    public function profile(Request $request): Response
    {
        $userId = $this->requireUserId();

        return $this->view('settings/profile', [
            'title'     => 'Your profile',
            'timezones' => Clock::commonTimezones(),
            'me'        => Auth::user(),
        ]);
    }

    public function updateProfile(Request $request): Response
    {
        $userId = $this->requireUserId();

        $validator = Validator::make($request->all(), ['name' => 'Name', 'timezone' => 'Timezone'])
            ->required('name')->max('name', 120)->timezone('timezone');

        if ($validator->fails()) {
            throw HttpException::validation('Please correct your profile details.', $validator->errors());
        }

        $data = $validator->validated();
        User::modify($userId, ['name' => (string) $data['name'], 'timezone' => (string) $data['timezone']]);
        Session::put('user_tz', (string) $data['timezone']);

        $this->log('profile.updated', 'user', $userId);
        Session::flash('success', 'Profile updated.');

        return $this->redirect('/settings/profile');
    }

    public function password(Request $request): Response
    {
        $userId = $this->requireUserId();
        $user = Auth::user();

        $current = (string) $request->post('current_password', '');
        $new = (string) $request->post('new_password', '');
        $confirm = (string) $request->post('new_password_confirmation', '');

        if ($user === null || !password_verify($current, (string) $user['password_hash'])) {
            throw HttpException::validation('That current password is not correct.', [
                'current_password' => 'The current password does not match.',
            ]);
        }

        if (mb_strlen($new) < 12) {
            throw HttpException::validation('Choose a longer password.', [
                'new_password' => 'Use at least 12 characters.',
            ]);
        }

        if ($new !== $confirm) {
            throw HttpException::validation('The new passwords do not match.', [
                'new_password_confirmation' => 'The confirmation does not match.',
            ]);
        }

        if (preg_match('/[A-Z]/', $new) !== 1 || preg_match('/[a-z]/', $new) !== 1 || preg_match('/\d/', $new) !== 1) {
            throw HttpException::validation('That password is too simple.', [
                'new_password' => 'Include upper case, lower case and a number.',
            ]);
        }

        User::changePassword($userId, $new);
        Session::regenerate();
        $this->log('password.changed', 'user', $userId);

        Session::flash('success', 'Your password was changed.');
        return $this->redirect('/settings/profile');
    }
}
