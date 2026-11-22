<?php

declare(strict_types=1);

// what the runtime prototype proves: PHP 8 runs inside the plugin, reaches the server, and survives errors

falcon_log("PHP " . PHP_VERSION . " on " . PHP_OS_FAMILY . ", " . (PHP_ZTS ? "thread-safe" : "not thread-safe"));

$checks = [];

// the extensions PocketMine plugins need first
foreach (["phar", "zlib", "json", "ctype", "tokenizer", "filter", "spl", "pcre", "hash", "random"] as $extension) {
    $checks["extension $extension"] = extension_loaded($extension);
}

// PHP 8 features the PocketMine-MP 5 API is written with
enum Mode: string {
    case Survival = "survival";
    case Creative = "creative";
}

final class Settings {
    public function __construct(public readonly Mode $mode, public readonly int $radius = 8) {}
}

#[Attribute]
final class Handler {}

$settings = new Settings(Mode::from("creative"));
$checks["enum and readonly"] = $settings->mode === Mode::Creative && $settings->radius === 8;
$checks["attributes"] = count((new ReflectionClass(Handler::class))->getAttributes()) === 1;
$checks["match"] = match (true) { $settings->radius > 4 => "far", default => "near" } === "far";

// zlib and json round trips
$text = str_repeat("PocketMine ", 100);
$checks["zlib round trip"] = gzdecode(gzencode($text)) === $text;
$checks["json round trip"] = json_decode(json_encode(["a" => [1, 2, 3]]), true) === ["a" => [1, 2, 3]];

// an exception a plugin throws is caught as usual
try {
    throw new RuntimeException("boom");
} catch (RuntimeException $e) {
    $checks["exceptions"] = $e->getMessage() === "boom";
}

// a phar written and read back, as plugins ship
$pharPath = sys_get_temp_dir() . "/pmmp-compat-selftest-" . getmypid() . ".phar";
$phar = new Phar($pharPath);
$phar->addFromString("plugin.yml", "name: SelfTest\n");
$phar->setStub("<?php __HALT_COMPILER();");
unset($phar);
$checks["phar"] = file_get_contents("phar://$pharPath/plugin.yml") === "name: SelfTest\n";
Phar::unlinkArchive($pharPath);

// some memory churn
$blocks = [];
for ($i = 0; $i < 200000; ++$i) {
    $blocks[] = ["x" => $i, "name" => "block$i"];
}
$checks["allocations"] = count($blocks) === 200000;
unset($blocks);

$failed = array_keys(array_filter($checks, fn(bool $passed) => !$passed));
falcon_log(count($checks) - count($failed) . " of " . count($checks) . " checks passed" . ($failed === [] ? "" : ": failed " . implode(", ", $failed)));

// last: a fatal error must stop this script only, never the server
falcon_log("calling an undefined function, a fatal error the runtime contains");
undefined_function_for_the_self_test();
falcon_log("not reached");
