<?php

declare(strict_types=1);

if ($argc !== 3) {
    fwrite(STDERR, "Usage: php -d phar.readonly=0 scripts/patch-bedrockeconomy.php <original.phar> <patched.phar>\n");
    exit(1);
}

$source = realpath($argv[1]);
$output = $argv[2];
$expectedHash = '9ef8ecaaa9e2c47cf8a21a02ac2d311b8b4747e3c22540682884eb148d5012da';
if ($source === false || !is_file($source) || hash_file('sha256', $source) !== $expectedHash) {
    fwrite(STDERR, "Expected the original BedrockEconomy 4.0.4 PHAR from Poggit.\n");
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

$temporary = dirname($output) . '/.bedrockeconomy-' . bin2hex(random_bytes(8)) . '.phar';
$status = 0;
try {
    if (!copy($source, $temporary)) {
        throw new RuntimeException('Could not copy the original archive');
    }
    $phar = new Phar($temporary);
    $path = 'src/cooldogedev/BedrockEconomy/BedrockEconomy.php';
    $original = '        BedrockEconomyAPI::init();';
    $replacement = <<<'CODE'
        BedrockEconomyAPI::init();
        // Plugins with the old libPiggyEconomy type still use the same legacy methods.
        if (!class_exists(api\version\LegacyBEAPI::class, false)) {
            class_alias(api\type\LegacyAPI::class, api\version\LegacyBEAPI::class);
        }
CODE;
    $updated = str_replace($original, $replacement, $phar[$path]->getContent(), $replacements);
    if ($replacements !== 1) {
        throw new RuntimeException('Could not identify the legacy API initialization');
    }
    $phar[$path] = $updated;
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
