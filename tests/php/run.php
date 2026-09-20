<?php

declare(strict_types=1);

/**
 * Minimal test runner — no Composer, no PHPUnit, nothing to install.
 *
 *   php tests/php/run.php                 run every *Test.php file
 *   php tests/php/run.php JobLifecycle    run the ones whose name matches
 */

require __DIR__ . '/bootstrap.php';

$registry = ['tests' => [], 'failures' => []];

function test(string $name, callable $body): void
{
    global $registry;
    $registry['tests'][] = [$name, $body];
}

function assert_true(bool $condition, string $message = 'assertion failed'): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assert_same($expected, $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf(
            '%sexpected %s, got %s',
            $message !== '' ? $message . ': ' : '',
            var_export($expected, true),
            var_export($actual, true)
        ));
    }
}

function assert_contains(string $needle, string $haystack, string $message = ''): void
{
    if (!str_contains($haystack, $needle)) {
        throw new RuntimeException(sprintf(
            '%s%sexpected to find "%s" in "%s"',
            $message !== '' ? $message . ': ' : '',
            '',
            $needle,
            mb_substr($haystack, 0, 160)
        ));
    }
}

function assert_not_contains(string $needle, string $haystack, string $message = ''): void
{
    if (str_contains($haystack, $needle)) {
        throw new RuntimeException(sprintf(
            '%sforbidden string "%s" is present in "%s"',
            $message !== '' ? $message . ': ' : '',
            $needle,
            mb_substr($haystack, 0, 160)
        ));
    }
}

/** @param class-string<Throwable> $expected */
function assert_throws(string $expected, callable $body, string $message = ''): void
{
    try {
        $body();
    } catch (Throwable $error) {
        if ($error instanceof $expected) {
            return;
        }
        throw new RuntimeException(sprintf(
            '%sexpected %s but got %s: %s',
            $message !== '' ? $message . ': ' : '',
            $expected,
            get_class($error),
            $error->getMessage()
        ));
    }
    throw new RuntimeException(($message !== '' ? $message . ': ' : '') . "expected {$expected}, nothing was thrown");
}

// ---------------------------------------------------------------- discovery

$filter = $argv[1] ?? '';
$files = glob(__DIR__ . '/*Test.php') ?: [];
sort($files);

foreach ($files as $file) {
    if ($filter !== '' && !str_contains(basename($file), $filter)) {
        continue;
    }
    require $file;
}

// ---------------------------------------------------------------- execution

$passed = 0;
$failed = 0;
$started = microtime(true);

echo "LinkEasy Publisher — PHP test suite\n";
echo str_repeat('=', 60), "\n";

foreach ($registry['tests'] as [$name, $body]) {
    try {
        // Each test starts from a clean database so ordering never matters.
        db_reset();
        $body();
        $passed++;
        echo "  ✓ {$name}\n";
    } catch (Throwable $error) {
        $failed++;
        echo "  ✗ {$name}\n";
        echo "      " . get_class($error) . ': ' . $error->getMessage() . "\n";
        if (getenv('LINKEASY_TEST_TRACE') === '1') {
            foreach (array_slice(explode("\n", $error->getTraceAsString()), 0, 8) as $line) {
                echo '      ' . $line . "\n";
            }
        }
    }
}

$duration = (int) round((microtime(true) - $started) * 1000);

echo str_repeat('=', 60), "\n";
printf("%d passed, %d failed, %d ms\n", $passed, $failed, $duration);

// Clean up the throwaway root unless the caller asked to inspect it.
if (getenv('LINKEASY_TEST_KEEP') !== '1') {
    $root = (string) getenv('LINKEASY_TEST_ROOT');
    if ($root !== '' && is_dir($root)) {
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($root);
    }
}

exit($failed === 0 ? 0 : 1);
