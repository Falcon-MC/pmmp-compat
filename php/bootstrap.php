<?php

declare(strict_types=1);

// run by the native plugin once PHP has started: loads and enables the PocketMine-MP plugins
require_once __DIR__ . '/src/pmmpcompat/ClassLoader.php';
require_once __DIR__ . '/src/pmmpcompat/Runtime.php';

\pmmpcompat\Runtime::start(__DIR__, falcon_plugin_folder());
