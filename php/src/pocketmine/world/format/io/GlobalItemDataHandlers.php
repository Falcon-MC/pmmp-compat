<?php

declare(strict_types=1);

namespace pocketmine\world\format\io;

use pocketmine\data\bedrock\item\ItemDeserializer;
use pocketmine\data\bedrock\item\ItemSerializer;
use pocketmine\data\bedrock\item\upgrade\ItemDataUpgrader;

/** Where plugins turn items into the game's saved form and back */
final class GlobalItemDataHandlers{
    private static ?ItemSerializer $serializer = null;
    private static ?ItemDeserializer $deserializer = null;
    private static ?ItemDataUpgrader $upgrader = null;

    public static function getSerializer() : ItemSerializer{
        return self::$serializer ??= new ItemSerializer();
    }

    public static function getDeserializer() : ItemDeserializer{
        return self::$deserializer ??= new ItemDeserializer();
    }

    public static function getUpgrader() : ItemDataUpgrader{
        return self::$upgrader ??= new ItemDataUpgrader();
    }
}
