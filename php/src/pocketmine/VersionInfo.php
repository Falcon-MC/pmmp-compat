<?php

declare(strict_types=1);

namespace pocketmine;

use pocketmine\utils\VersionString;

/**
 * The PocketMine-MP version this runtime answers as, for plugins that check it.
 *
 * NAME stays PocketMine-MP: plugins compare it, and Server::getName() is where Falcon shows.
 */
final class VersionInfo{
    public const NAME = "PocketMine-MP";
    public const BASE_VERSION = "5.44.3";
    public const IS_DEVELOPMENT_BUILD = false;
    public const BUILD_CHANNEL = "stable";
    public const GITHUB_URL = "https://github.com/pmmp/PocketMine-MP";

    public const WORLD_DATA_VERSION = 1;
    public const TAG_WORLD_DATA_VERSION = "PMMPDataVersion";

    private static ?VersionString $fullVersion = null;

    private function __construct(){
    }

    /** no PocketMine-MP checkout: forty zeros, as PocketMine-MP gives without one */
    public static function GIT_HASH() : string{
        return str_repeat("00", 20);
    }

    public static function BUILD_NUMBER() : int{
        return 0;
    }

    public static function VERSION() : VersionString{
        return self::$fullVersion ??= new VersionString(self::BASE_VERSION, self::IS_DEVELOPMENT_BUILD, self::BUILD_NUMBER());
    }
}
