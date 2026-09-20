<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Models\ActivityLog;

/**
 * Shared controller plumbing: rendering, redirects, ownership guards and
 * activity logging. Controllers stay thin; all rules live in the services.
 */
abstract class Controller
{
    /**
     * Render a template inside a layout.
     *
     * @param array<string,mixed> $data
     * @param string|null $layout Layout name, or null to render the template alone.
     */
    protected function view(string $template, array $data = [], ?string $layout = 'layouts/app', int $status = 200): Response
    {
        $user = Auth::user();

        $shared = [
            'user'          => $user,
            'timezone'      => Auth::timezone(),
            'csrfToken'     => Session::csrfToken(),
            'flash'         => Session::pullFlash(),
            'currentPath'   => $this->currentPath(),
            'unreadCount'   => $user === null ? 0 : \App\Models\Notification::unreadCount((int) $user['id']),
            'isAdmin'       => Auth::isAdmin(),
        ];

        return Response::html(View::render($template, array_merge($shared, $data), $layout), $status);
    }

    /** @param array<string,mixed> $payload */
    protected function json(array $payload, int $status = 200): Response
    {
        return Response::json(array_merge(['ok' => $status < 400], $payload), $status);
    }

    protected function redirect(string $to): Response
    {
        return Response::redirect($to);
    }

    protected function back(string $fallback = '/dashboard'): Response
    {
        $referer = $_SERVER['HTTP_REFERER'] ?? '';
        $path = $referer === '' ? $fallback : (parse_url($referer, PHP_URL_PATH) ?: $fallback);
        // Only ever redirect to a local path.
        if (!str_starts_with($path, '/')) {
            $path = $fallback;
        }
        return Response::redirect($path);
    }

    protected function currentPath(): string
    {
        return parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
    }

    /** Throw a 422 with field-level detail, rendered back into the form. */
    protected function validationFailed(string $message, array $errors): never
    {
        throw HttpException::validation($message, $errors);
    }

    protected function requireUserId(): int
    {
        $id = Auth::id();
        if ($id === null) {
            throw HttpException::unauthorized();
        }
        return $id;
    }

    /**
     * Fetch a row and refuse it unless the signed-in user owns it.
     *
     * @param array<string,mixed>|null $row
     * @return array<string,mixed>
     */
    protected function owned(?array $row, string $entity = 'resource'): array
    {
        return Auth::requireOwns($row, $entity);
    }

    /** @param array<string,mixed> $meta */
    protected function log(string $action, ?string $entityType = null, ?int $entityId = null, array $meta = []): void
    {
        ActivityLog::record(Auth::id(), $action, $entityType, $entityId, null, 'user', Auth::id(), $meta);
        Logger::info($action, ['entity' => $entityType, 'id' => $entityId] + $meta);
    }

    /** @param array<string,string> $params */
    protected function paramInt(array $params, string $key, string $entity = 'resource'): int
    {
        $value = $params[$key] ?? null;
        if ($value === null || !ctype_digit((string) $value)) {
            throw HttpException::notFound("That {$entity} does not exist.");
        }
        return (int) $value;
    }

    protected function input(Request $request, string $key, mixed $default = null): mixed
    {
        return $request->input($key, $default);
    }
}
