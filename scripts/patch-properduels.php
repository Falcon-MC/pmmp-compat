<?php

declare(strict_types=1);

if ($argc !== 3) {
    fwrite(STDERR, "Usage: php -d phar.readonly=0 scripts/patch-properduels.php <original.phar> <patched.phar>\n");
    exit(1);
}

$source = realpath($argv[1]);
$output = $argv[2];
$expectedHash = '470166ea7aa19c4d2c52e3012d6d283e25eda5c27e53195de4ae2963525769a4';
if ($source === false || !is_file($source) || hash_file('sha256', $source) !== $expectedHash) {
    fwrite(STDERR, "Expected the original ProperDuels 3.0.0 PHAR from Poggit.\n");
    exit(1);
}
if (!str_ends_with($output, '.phar') || !is_dir(dirname($output)) || file_exists($output) || is_link($output)) {
    fwrite(STDERR, "Choose a new .phar path in an existing directory.\n");
    exit(1);
}
if (ini_get('phar.readonly')) {
    fwrite(STDERR, "Run PHP with -d phar.readonly=0.\n");
    exit(1);
}

$temporary = dirname($output) . '/.properduels-' . bin2hex(random_bytes(8)) . '.phar';
$status = 0;
try {
    if (!copy($source, $temporary)) {
        throw new RuntimeException('Could not copy the original archive');
    }
    $phar = new Phar($temporary);
    $path = 'resources/sqlite/stmt.sql';
    $original = "    EXISTS(\n        SELECT 1\n        FROM pragma_table_info('Kits')\n        WHERE name='Enabled'\n    ) AS migrationNeeded;";
    $patched = <<<'SQL'
    EXISTS(SELECT 1 FROM pragma_table_info('Kits'))
    AND NOT EXISTS(
        SELECT 1
        FROM pragma_table_info('Kits')
        WHERE name = 'Enabled' COLLATE NOCASE
    ) AS migrationNeeded;
SQL;
    // The plugin submits this check alongside table creation, so an absent table needs no migration.
    $sql = str_replace($original, $patched, $phar[$path]->getContent(), $replacements);
    if ($replacements !== 1) {
        throw new RuntimeException('Could not identify the SQLite kit migration check');
    }
    $phar[$path] = $sql;
    unset($phar);
    if (!link($temporary, $output)) {
        throw new RuntimeException('Could not create the patched archive at the requested path');
    }
    fwrite(STDOUT, "Created $output\n");
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    $status = 1;
} finally {
    if (is_file($temporary)) {
        unlink($temporary);
    }
}
exit($status);
