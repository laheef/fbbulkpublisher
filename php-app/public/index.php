<?php
declare(strict_types=1);

/**
 * LinkEasy Publisher — front controller.
 *
 * Point your web server's document root at public/. Everything else (config,
 * storage, app, routes) sits outside the web root and is unreachable over HTTP.
 */

use App\Core\Application;
use App\Core\Autoloader;
use App\Core\Request;

define('LINKEASY_START', microtime(true));
define('LINKEASY_BASE', dirname(__DIR__));

require LINKEASY_BASE . '/app/Core/Autoloader.php';
Autoloader::register('App', LINKEASY_BASE . '/app');

$app = Application::instance();
$app->boot(LINKEASY_BASE);

require LINKEASY_BASE . '/routes/web.php';

$response = $app->handle(Request::capture());
$response->send();
