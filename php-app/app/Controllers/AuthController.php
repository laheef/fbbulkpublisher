<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Clock;
use App\Core\HttpException;
use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;
use App\Models\User;

final class AuthController extends Controller
{
    public function showLogin(Request $request): Response
    {
        return $this->view('auth/login', ['title' => 'Sign in'], 'layouts/bare');
    }

    public function login(Request $request): Response
    {
        $validator = Validator::make($request->all(), ['email' => 'Email', 'password' => 'Password'])
            ->required('email')->email('email')->required('password')->max('password', 200);

        if ($validator->fails()) {
            throw HttpException::validation('Please check the form and try again.', $validator->errors());
        }

        $data = $validator->validated();
        $result = Auth::attempt((string) $data['email'], (string) $request->post('password', ''), $request);

        if (!$result['ok']) {
            $message = match ($result['reason']) {
                'throttled'  => 'Too many failed attempts. Try again in a few minutes.',
                'suspended'  => 'This account has been suspended. Contact your administrator.',
                default      => 'Those credentials do not match our records.',
            };

            NotificationLogger($message);
            throw HttpException::validation($message, ['email' => $message]);
        }

        return $this->redirect('/dashboard');
    }

    public function showRegister(Request $request): Response
    {
        $allowOpenRegistration = \App\Core\Config::bool('app.allow_registration', true);
        if (!$allowOpenRegistration) {
            throw HttpException::forbidden('Self-registration is disabled on this installation.');
        }
        return $this->view('auth/register', ['title' => 'Create your workspace'], 'layouts/bare');
    }

    public function register(Request $request): Response
    {
        $allowOpenRegistration = \App\Core\Config::bool('app.allow_registration', true);
        if (!$allowOpenRegistration) {
            throw HttpException::forbidden('Self-registration is disabled on this installation.');
        }

        $validator = Validator::make($request->all(), [
            'name' => 'Name', 'email' => 'Email address', 'password' => 'Password', 'timezone' => 'Timezone',
        ])
            ->required('name')->max('name', 120)
            ->required('email')->email('email')
            ->required('password')->min('password', 12)->max('password', 200)
            ->timezone('timezone');

        if ($validator->fails()) {
            throw HttpException::validation('Please check the form and try again.', $validator->errors());
        }

        $data = $validator->validated();
        $password = (string) $request->post('password', '');

        if (preg_match('/[A-Z]/', $password) !== 1 || preg_match('/[a-z]/', $password) !== 1 || preg_match('/\d/', $password) !== 1) {
            throw HttpException::validation('That password is too simple.', [
                'password' => 'Use at least 12 characters including upper case, lower case and a number.',
            ]);
        }

        $result = User::register(
            (string) $data['name'],
            (string) $data['email'],
            $password,
            (string) ($data['timezone'] ?: 'UTC')
        );

        if (!$result['ok']) {
            throw HttpException::validation('That account could not be created.', $result['errors'] ?? []);
        }

        Auth::login($result['user'], $request);
        Session::flash('success', 'Welcome to LinkEasy Publisher. Your workspace is ready.');

        return $this->redirect('/dashboard');
    }

    public function logout(Request $request): Response
    {
        $userId = Auth::id();
        Auth::logout();
        if ($userId !== null) {
            Logger::info('User signed out', ['user_id' => $userId]);
        }
        return $this->redirect('/login');
    }
}

/**
 * Small helper kept local to this controller: authentication failures are
 * logged at warn level without echoing the submitted password anywhere.
 */
function NotificationLogger(string $message): void
{
    Logger::warn('Authentication failed', ['reason' => $message]);
}
