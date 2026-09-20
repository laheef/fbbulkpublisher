<?php
declare(strict_types=1);

namespace App\Core;

use Throwable;

/**
 * Application kernel: boots config, dispatches routes, runs middleware,
 * renders errors. Deliberately small and dependency-free.
 */
final class Application
{
    private static ?Application $instance = null;
    private Router $router;
    private bool $booted = false;

    /** @var array<string,callable> */
    private array $middleware = [];

    private function __construct()
    {
        $this->router = new Router();
    }

    public static function instance(): Application
    {
        return self::$instance ??= new self();
    }

    public function boot(string $basePath): void
    {
        if ($this->booted) {
            return;
        }

        $basePath = rtrim($basePath, '/');
        Autoloader::init();
        Autoloader::register('App', $basePath . '/app');

        Config::load($basePath . '/config/config.php');
        date_default_timezone_set(Config::str('app.timezone', 'UTC'));
        mb_internal_encoding('UTF-8');

        Logger::boot(Config::get('logging', []));
        View::boot($basePath . '/app/Views');
        View::share('appName', Config::str('app.name', 'LinkEasy Publisher'));
        View::share('appVersion', Config::str('app.version', '1.0.0'));
        View::share('appUrl', Config::str('app.url', ''));

        if (PHP_SAPI !== 'cli') {
            Session::start(Config::get('session', []));
        }

        $this->registerMiddleware();
        $this->booted = true;
    }

    public function router(): Router
    {
        return $this->router;
    }

    /** @param callable(Request):?Response $handler */
    public function middleware(string $name, callable $handler): void
    {
        $this->middleware[$name] = $handler;
    }

    private function registerMiddleware(): void
    {
        $this->middleware('auth', function (Request $request): ?Response {
            if (!Auth::check()) {
                if ($request->expectsJson()) {
                    throw HttpException::unauthorized();
                }
                Session::flash('warning', 'Please sign in to continue.');
                return Response::redirect('/login');
            }
            return null;
        });

        $this->middleware('admin', function (Request $request): ?Response {
            if (!Auth::isAdmin()) {
                throw $request->expectsJson()
                    ? HttpException::forbidden('Administrator access is required.')
                    : HttpException::forbidden('Administrator access is required.');
            }
            return null;
        });

        $this->middleware('csrf', function (Request $request): ?Response {
            if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
                return null;
            }
            $token = $request->post('_csrf') ?? $request->header('X-CSRF-Token');
            if (!Session::verifyCsrf(is_string($token) ? $token : null)) {
                Logger::warn('CSRF token rejected', ['path' => $request->path(), 'ip' => $request->ip()]);
                throw new HttpException(419, 'Your session expired. Please reload the page and try again.');
            }
            return null;
        });

        $this->middleware('guest', function (Request $request): ?Response {
            if (Auth::check()) {
                return Response::redirect('/dashboard');
            }
            return null;
        });
    }

    public function handle(Request $request): Response
    {
        $started = microtime(true);

        try {
            $match = $this->router->match($request->method(), $request->path());

            if ($match === null) {
                throw HttpException::notFound($request->path() === '/' ? 'Nothing here.' : 'Page not found.');
            }

            foreach ($match['middleware'] as $name) {
                $handler = $this->middleware[$name] ?? null;
                if ($handler === null) {
                    continue;
                }
                $early = $handler($request);
                if ($early instanceof Response) {
                    return $early;
                }
            }

            [$class, $method] = $match['handler'];
            if (!class_exists($class)) {
                throw new \RuntimeException("Controller not found: {$class}");
            }
            $controller = new $class();
            $response = $controller->{$method}($request, $match['params']);

            if (!$response instanceof Response) {
                $response = Response::json($response);
            }
        } catch (HttpException $e) {
            $response = $this->renderHttpException($request, $e);
        } catch (Throwable $e) {
            Logger::error('Unhandled exception: ' . $e->getMessage(), [
                'path'  => $request->path(),
                'file'  => $e->getFile() . ':' . $e->getLine(),
                'trace' => Config::bool('app.debug') ? $e->getTraceAsString() : '[hidden]',
            ]);
            $response = $this->renderThrowable($request, $e);
        }

        $elapsed = round((microtime(true) - $started) * 1000);
        if ($elapsed > 1500) {
            Logger::warn('Slow request', ['path' => $request->path(), 'ms' => $elapsed]);
        }

        return $response;
    }

    private function renderHttpException(Request $request, HttpException $e): Response
    {
        if ($request->expectsJson()) {
            return Response::json([
                'ok'      => false,
                'error'   => $e->getMessage(),
                'details' => $e->details(),
            ], $e->statusCode());
        }

        $view = match ($e->statusCode()) {
            403 => 'errors/403',
            404 => 'errors/404',
            419 => 'errors/419',
            422 => 'errors/422',
            default => 'errors/generic',
        };

        try {
            $body = View::render($view, [
                'title'   => 'Error ' . $e->statusCode(),
                'message' => $e->getMessage(),
                'details' => $e->details(),
                'status'  => $e->statusCode(),
            ], Auth::check() ? 'layouts/app' : 'layouts/bare');
        } catch (Throwable) {
            $body = '<h1>Error ' . $e->statusCode() . '</h1><p>' . Support::e($e->getMessage()) . '</p>';
        }

        return Response::html($body, $e->statusCode());
    }

    private function renderThrowable(Request $request, Throwable $e): Response
    {
        $message = Config::bool('app.debug')
            ? $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')'
            : 'An unexpected error occurred. The incident has been logged.';

        if ($request->expectsJson()) {
            return Response::json(['ok' => false, 'error' => $message], 500);
        }

        try {
            $body = View::render('errors/generic', [
                'title'   => 'Server error',
                'message' => $message,
                'details' => [],
                'status'  => 500,
            ], Auth::check() ? 'layouts/app' : 'layouts/bare');
        } catch (Throwable) {
            $body = '<h1>Server error</h1><p>' . Support::e($message) . '</p>';
        }

        return Response::html($body, 500);
    }
}
