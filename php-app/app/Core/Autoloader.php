<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Minimal PSR-4 compatible autoloader (no Composer required).
 *
 * Maps  App\Foo\Bar  ->  <appPath>/Foo/Bar.php
 */
final class Autoloader
{
    /** @var array<string,string> */
    private static array $prefixes = [];
    private static bool $registered = false;

    /**
     * Map a namespace prefix to a directory. Registers the autoloader on first
     * use so calling code can never forget to initialise it.
     */
    public static function register(string $prefix, string $baseDir): void
    {
        $prefix = trim($prefix, '\\') . '\\';
        self::$prefixes[$prefix] = rtrim($baseDir, '/\\') . DIRECTORY_SEPARATOR;
        self::init();
    }

    public static function init(): void
    {
        if (self::$registered) {
            return;
        }
        spl_autoload_register([self::class, 'load']);
        self::$registered = true;
    }

    public static function load(string $class): void
    {
        foreach (self::$prefixes as $prefix => $baseDir) {
            if (!str_starts_with($class, $prefix)) {
                continue;
            }
            $relative = substr($class, strlen($prefix));
            $file = $baseDir . str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';
            if (is_file($file)) {
                require $file;
                return;
            }
        }
    }
}
