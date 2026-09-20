<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Clock;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;

/**
 * Unauthenticated liveness/readiness probe.
 *
 * Deliberately terse: it reports whether the service is up and how the queue
 * is doing, and nothing about users, tenants or infrastructure internals.
 */
final class HealthController extends Controller
{
    public function index(Request $request): Response
    {
        $db = Database::instance();
        $checks = [];

        try {
            $db->scalar('SELECT 1', []);
            $checks['database'] = 'ok';
        } catch (\Throwable) {
            $checks['database'] = 'unavailable';
        }

        $checks['storage'] = is_writable(Config::str('uploads.disk_path')) ? 'ok' : 'read-only';
        $checks['logs']    = is_writable(Config::str('logging.path')) ? 'ok' : 'read-only';

        $degraded = in_array('unavailable', $checks, true);

        $payload = [
            'ok'       => !$degraded,
            'service'  => Config::str('app.name'),
            'version'  => Config::str('app.version'),
            'time_utc' => Clock::nowString(),
            'checks'   => $checks,
        ];

        if ($request->bool('detailed')) {
            $payload['queue'] = [
                'scheduled' => (int) $db->scalar("SELECT COUNT(*) FROM jobs WHERE status = 'SCHEDULED'", []),
                'queued'    => (int) $db->scalar("SELECT COUNT(*) FROM jobs WHERE status = 'QUEUED'", []),
                'retrying'  => (int) $db->scalar("SELECT COUNT(*) FROM jobs WHERE status = 'RETRYING'", []),
                'in_flight' => (int) $db->scalar("SELECT COUNT(*) FROM jobs WHERE status IN ('CLAIMED','PROCESSING','UPLOADING','PUBLISHING','VERIFYING')", []),
            ];
            $payload['workers_online'] = (int) $db->scalar("SELECT COUNT(*) FROM browser_workers WHERE status IN ('ONLINE','BUSY')", []);
        }

        return $this->json($payload, $degraded ? 503 : 200);
    }
}
