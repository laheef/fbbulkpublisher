<?php
declare(strict_types=1);

namespace App\Services\Publishing;

use App\Core\Config;

/**
 * Resolves a publishing provider by key. The scheduler and the dispatcher only
 * ever depend on ProviderInterface.
 */
final class ProviderFactory
{
    private static ?string $override = null;

    public static function setOverride(?string $key): void
    {
        self::$override = $key === null ? null : strtoupper($key);
    }

    public static function currentKey(): string
    {
        if (self::$override !== null) {
            return self::$override;
        }
        return strtoupper(Config::str('publishing.provider', 'browser'));
    }

    public static function make(?string $key = null): ProviderInterface
    {
        return match (strtoupper($key ?? self::currentKey())) {
            'SIMULATED'    => new SimulatedPublishingProvider(),
            'OFFICIAL_API' => new OfficialApiPublishingProvider(),
            default        => new BrowserPublishingProvider(),
        };
    }

    /** @return list<ProviderInterface> */
    public static function all(): array
    {
        return [
            new BrowserPublishingProvider(),
            new SimulatedPublishingProvider(),
            new OfficialApiPublishingProvider(),
        ];
    }
}
