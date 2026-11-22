<?php

declare(strict_types=1);

namespace pmmpcompat;

use pocketmine\item as items;
use pocketmine\item\Item;
use pocketmine\item\ToolTier;

/**
 * What Falcon knows of each item and block type, read once, and the PocketMine-MP item class each item gets from it.
 */
final class ItemTypes{
    /** @var array<string, array<string, mixed>|null> by identifier */
    private static array $items = [];
    /** @var array<string, array<string, mixed>|null> by identifier */
    private static array $blocks = [];

    private const TIERS = [1 => "WOOD", 2 => "GOLD", 3 => "STONE", 4 => "COPPER", 5 => "IRON", 6 => "DIAMOND", 7 => "NETHERITE"];

    /** the items PocketMine-MP has a class of its own for, by identifier */
    private const CLASSES = [
        "minecraft:bow" => items\Bow::class,
        "minecraft:crossbow" => items\Bow::class,
        "minecraft:potion" => items\Potion::class,
        "minecraft:splash_potion" => items\SplashPotion::class,
        "minecraft:lingering_potion" => items\SplashPotion::class,
        "minecraft:snowball" => items\Snowball::class,
        "minecraft:egg" => items\Egg::class,
        "minecraft:ender_pearl" => items\EnderPearl::class,
        "minecraft:experience_bottle" => items\ExperienceBottle::class,
        "minecraft:totem_of_undying" => items\Totem::class,
        "minecraft:bucket" => items\Bucket::class,
        "minecraft:milk_bucket" => items\MilkBucket::class,
        "minecraft:flint_and_steel" => items\FlintSteel::class,
        "minecraft:compass" => items\Compass::class,
        "minecraft:clock" => items\Clock::class,
        "minecraft:fishing_rod" => items\FishingRod::class,
        "minecraft:shears" => items\Shears::class,
        "minecraft:trident" => items\Trident::class,
    ];

    private function __construct(){
    }

    /** @return array<string, mixed>|null */
    public static function item(string $identifier) : ?array{
        if(!array_key_exists($identifier, self::$items)){
            self::$items[$identifier] = falcon_item_info($identifier);
        }
        return self::$items[$identifier];
    }

    /** @return array<string, mixed>|null */
    public static function block(string $identifier) : ?array{
        if(!array_key_exists($identifier, self::$blocks)){
            self::$blocks[$identifier] = falcon_block_info($identifier);
        }
        return self::$blocks[$identifier];
    }

    public static function tier(int $tier) : ?ToolTier{
        $name = self::TIERS[$tier] ?? null;
        return $name === null ? null : constant(ToolTier::class . "::" . $name);
    }

    /** a new item of that identifier, of the class PocketMine-MP gives it */
    public static function create(string $identifier) : Item{
        $class = self::CLASSES[$identifier] ?? null;
        if($class === null){
            $info = self::item($identifier);
            if($info !== null){
                $class = match(true){
                    str_ends_with($identifier, "_bucket") => items\Bucket::class,
                    $info["armorSlot"] > 0 => items\Armor::class,
                    $info["toolType"] === 1 => items\Sword::class,
                    $info["toolType"] === 2 => items\Shovel::class,
                    $info["toolType"] === 3 => items\Pickaxe::class,
                    $info["toolType"] === 4 => items\Axe::class,
                    $info["toolType"] === 5 => items\Shears::class,
                    $info["toolType"] === 6 => items\Hoe::class,
                    $info["nutrition"] > 0 => items\Food::class,
                    $info["maxDurability"] > 0 => items\Durable::class,
                    self::block($identifier) !== null => items\ItemBlock::class,
                    default => Item::class,
                };
            }elseif(self::block($identifier) !== null){
                $class = items\ItemBlock::class;
            }else{
                $class = Item::class;
            }
        }
        return new $class($identifier);
    }
}
