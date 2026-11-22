<?php

declare(strict_types=1);

namespace pocketmine\world\format\io;

use pocketmine\data\bedrock\block\BlockStateDeserializer;
use pocketmine\data\bedrock\block\BlockStateSerializer;
use pocketmine\data\bedrock\block\upgrade\BlockDataUpgrader;

/** Where plugins turn block states into the game's saved form and back */
final class GlobalBlockStateHandlers{
    private static ?BlockStateSerializer $serializer = null;
    private static ?BlockStateDeserializer $deserializer = null;
    private static ?BlockDataUpgrader $upgrader = null;

    public static function getSerializer() : BlockStateSerializer{
        return self::$serializer ??= new BlockStateSerializer();
    }

    public static function getDeserializer() : BlockStateDeserializer{
        return self::$deserializer ??= new BlockStateDeserializer();
    }

    public static function getUpgrader() : BlockDataUpgrader{
        return self::$upgrader ??= new BlockDataUpgrader();
    }
}
