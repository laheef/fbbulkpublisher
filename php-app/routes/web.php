<?php
declare(strict_types=1);

/**
 * LinkEasy Publisher — route table.
 *
 * Middleware names: auth, admin, csrf, guest.
 * All state-changing web routes carry `csrf`; all tenant routes carry `auth`.
 */

use App\Controllers\AccountController;
use App\Controllers\AdminController;
use App\Controllers\AnalyticsController;
use App\Controllers\AuthController;
use App\Controllers\CalendarController;
use App\Controllers\DashboardController;
use App\Controllers\HealthController;
use App\Controllers\MediaController;
use App\Controllers\NotificationController;
use App\Controllers\PageController;
use App\Controllers\PostController;
use App\Controllers\QueueController;
use App\Controllers\SettingsController;
use App\Controllers\WorkerController;
use App\Controllers\WorkerDashboardController;
use App\Core\Application;

/** @var Application $app */
$router = $app->router();

// ---------------------------------------------------------------------------
// Public
// ---------------------------------------------------------------------------
$router->add('GET', '/health', [HealthController::class, 'index']);

$router->group(['middleware' => ['guest']], function ($router): void {
    $router->add('GET', '/login', [AuthController::class, 'showLogin']);
    $router->add('POST', '/login', [AuthController::class, 'login'], ['csrf']);
    $router->add('GET', '/register', [AuthController::class, 'showRegister']);
    $router->add('POST', '/register', [AuthController::class, 'register'], ['csrf']);
});

$router->add('POST', '/logout', [AuthController::class, 'logout'], ['auth', 'csrf']);

// ---------------------------------------------------------------------------
// Worker API  (token-authenticated, never session-authenticated)
// ---------------------------------------------------------------------------
$router->add('POST', '/worker/register', [WorkerController::class, 'register']);
$router->add('POST', '/worker/heartbeat', [WorkerController::class, 'heartbeat']);
$router->add('GET', '/worker/config', [WorkerController::class, 'config']);
$router->add('GET', '/worker/jobs', [WorkerController::class, 'claimJobs']);
$router->add('POST', '/worker/jobs/{id}/progress', [WorkerController::class, 'jobProgress']);
$router->add('POST', '/worker/jobs/{id}/complete', [WorkerController::class, 'jobComplete']);
$router->add('POST', '/worker/jobs/{id}/fail', [WorkerController::class, 'jobFail']);
$router->add('POST', '/worker/jobs/{id}/verify', [WorkerController::class, 'verifyJob']);
$router->add('GET', '/worker/jobs/{id}/media', [WorkerController::class, 'jobMedia']);
$router->add('POST', '/worker/jobs/{id}/screenshot', [WorkerController::class, 'jobScreenshot']);
$router->add('POST', '/worker/challenge', [WorkerController::class, 'challenge']);
$router->add('POST', '/worker/logs', [WorkerController::class, 'logs']);
$router->add('POST', '/worker/accounts', [WorkerController::class, 'createAccount']);
$router->add('POST', '/worker/accounts/{id}/pages', [WorkerController::class, 'syncPages']);
$router->add('POST', '/worker/accounts/{id}/status', [WorkerController::class, 'accountStatus']);
$router->add('POST', '/worker/media/{id}/probe', [WorkerController::class, 'mediaProbe']);
$router->add('POST', '/worker/media/{id}/thumbnail', [WorkerController::class, 'mediaThumbnail']);

// ---------------------------------------------------------------------------
// Authenticated workspace
// ---------------------------------------------------------------------------
$router->group(['middleware' => ['auth']], function ($router): void {
    $router->add('GET', '/', [DashboardController::class, 'index']);
    $router->add('GET', '/dashboard', [DashboardController::class, 'index']);
    $router->add('GET', '/api/status', [DashboardController::class, 'apiStatus']);
    $router->add('GET', '/api/analytics', [AnalyticsController::class, 'api']);

    // Accounts
    $router->add('GET', '/accounts', [AccountController::class, 'index']);
    $router->add('POST', '/accounts', [AccountController::class, 'store'], ['csrf']);
    $router->add('GET', '/accounts/{id}', [AccountController::class, 'show']);
    $router->add('POST', '/accounts/{id}', [AccountController::class, 'update'], ['csrf']);
    $router->add('POST', '/accounts/{id}/connect', [AccountController::class, 'connect'], ['csrf']);
    $router->add('POST', '/accounts/{id}/verify', [AccountController::class, 'verify'], ['csrf']);
    $router->add('POST', '/accounts/{id}/toggle', [AccountController::class, 'toggle'], ['csrf']);
    $router->add('POST', '/accounts/{id}/reset-profile', [AccountController::class, 'resetProfile'], ['csrf']);
    $router->add('POST', '/accounts/{id}/pages', [AccountController::class, 'selectPages'], ['csrf']);
    $router->add('POST', '/accounts/{id}/remove', [AccountController::class, 'remove'], ['csrf']);

    // Pages
    $router->add('GET', '/pages', [PageController::class, 'index']);
    $router->add('GET', '/pages/{id}', [PageController::class, 'show']);
    $router->add('POST', '/pages/{id}/toggle', [PageController::class, 'toggle'], ['csrf']);
    $router->add('POST', '/pages/{id}/verify', [PageController::class, 'verify'], ['csrf']);
    $router->add('POST', '/pages/{id}/refresh', [PageController::class, 'refresh'], ['csrf']);

    // Media
    $router->add('GET', '/media', [MediaController::class, 'index']);
    $router->add('POST', '/media/upload', [MediaController::class, 'upload'], ['csrf']);
    $router->add('POST', '/media/chunk', [MediaController::class, 'uploadChunk'], ['csrf']);
    $router->add('GET', '/media/{id}', [MediaController::class, 'show']);
    $router->add('GET', '/media/{id}/file', [MediaController::class, 'file']);
    $router->add('GET', '/media/{id}/thumbnail', [MediaController::class, 'thumbnail']);
    $router->add('POST', '/media/{id}/remove', [MediaController::class, 'remove'], ['csrf']);

    // Posts / composer
    $router->add('GET', '/posts', [PostController::class, 'index']);
    $router->add('GET', '/posts/new', [PostController::class, 'create']);
    $router->add('POST', '/posts', [PostController::class, 'store'], ['csrf']);
    $router->add('GET', '/posts/{id}', [PostController::class, 'show']);
    $router->add('POST', '/posts/{id}', [PostController::class, 'update'], ['csrf']);
    $router->add('POST', '/posts/{id}/republish', [PostController::class, 'republish'], ['csrf']);
    $router->add('POST', '/posts/{id}/cancel', [PostController::class, 'cancel'], ['csrf']);
    $router->add('POST', '/posts/{id}/remove', [PostController::class, 'destroy'], ['csrf']);

    // Calendar
    $router->add('GET', '/calendar', [CalendarController::class, 'index']);

    // Queue
    $router->add('GET', '/queue', [QueueController::class, 'index']);
    $router->add('POST', '/queue/bulk', [QueueController::class, 'bulk'], ['csrf']);
    $router->add('POST', '/queue/{id}/retry', [QueueController::class, 'retry'], ['csrf']);
    $router->add('POST', '/queue/{id}/cancel', [QueueController::class, 'cancel'], ['csrf']);
    $router->add('POST', '/queue/{id}/pause', [QueueController::class, 'pause'], ['csrf']);
    $router->add('POST', '/queue/{id}/resume', [QueueController::class, 'resume'], ['csrf']);
    $router->add('POST', '/queue/{id}/resolve', [QueueController::class, 'resolveAction'], ['csrf']);
    $router->add('GET', '/queue/{id}/screenshot', [QueueController::class, 'screenshot']);

    // Workers (fleet view)
    $router->add('GET', '/workers', [WorkerDashboardController::class, 'index']);
    $router->add('GET', '/workers/{id}', [WorkerDashboardController::class, 'show']);
    $router->add('POST', '/workers/{id}/toggle', [WorkerDashboardController::class, 'toggle'], ['csrf']);
    $router->add('POST', '/workers/{id}/pause', [WorkerDashboardController::class, 'pause'], ['csrf']);
    $router->add('POST', '/workers/{id}/resume', [WorkerDashboardController::class, 'resume'], ['csrf']);
    $router->add('POST', '/workers/{id}/rotate', [WorkerDashboardController::class, 'rotate'], ['csrf']);
    $router->add('POST', '/workers/{id}/settings', [WorkerDashboardController::class, 'settings'], ['csrf']);
    $router->add('POST', '/workers/{id}/remove', [WorkerDashboardController::class, 'remove'], ['csrf']);

    // Analytics
    $router->add('GET', '/analytics', [AnalyticsController::class, 'index']);

    // Notifications
    $router->add('GET', '/notifications', [NotificationController::class, 'index']);
    $router->add('GET', '/notifications/feed', [NotificationController::class, 'feed']);
    $router->add('POST', '/notifications/read', [NotificationController::class, 'markRead'], ['csrf']);
    $router->add('POST', '/notifications/clear', [NotificationController::class, 'clear'], ['csrf']);

    // Settings
    $router->add('GET', '/settings', [SettingsController::class, 'index']);
    $router->add('POST', '/settings', [SettingsController::class, 'update'], ['csrf']);
    $router->add('GET', '/settings/profile', [SettingsController::class, 'profile']);
    $router->add('POST', '/settings/profile', [SettingsController::class, 'updateProfile'], ['csrf']);
    $router->add('POST', '/settings/password', [SettingsController::class, 'password'], ['csrf']);
});

// ---------------------------------------------------------------------------
// Administration
// ---------------------------------------------------------------------------
$router->group(['middleware' => ['auth', 'admin']], function ($router): void {
    $router->add('GET', '/admin', [AdminController::class, 'index']);
    $router->add('GET', '/admin/users', [AdminController::class, 'users']);
    $router->add('POST', '/admin/users/{id}', [AdminController::class, 'updateUser'], ['csrf']);
    $router->add('GET', '/admin/workers', [AdminController::class, 'workers']);
    $router->add('GET', '/admin/activity', [AdminController::class, 'activity']);
    $router->add('GET', '/admin/errors', [AdminController::class, 'errors']);
    $router->add('GET', '/admin/diagnostics', [AdminController::class, 'diagnostics']);
    $router->add('POST', '/admin/maintenance', [AdminController::class, 'maintenance'], ['csrf']);
});
