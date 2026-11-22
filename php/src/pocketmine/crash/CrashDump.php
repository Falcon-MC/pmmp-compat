<?php

declare(strict_types=1);

namespace pocketmine\crash;

/**
 * PocketMine-MP's crash report; Falcon writes its own, so what is kept here is what threads and plugins read.
 */
final class CrashDump{
    /** the error types that end a script */
    public const FATAL_ERROR_MASK = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR;

    public const FORMAT_VERSION = 4;
    public const PLUGIN_INVOLVEMENT_NONE = "none";
    public const PLUGIN_INVOLVEMENT_DIRECT = "direct";
    public const PLUGIN_INVOLVEMENT_INDIRECT = "indirect";

    private function __construct(){
    }
}
