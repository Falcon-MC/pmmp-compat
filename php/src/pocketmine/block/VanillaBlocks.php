<?php

declare(strict_types=1);

namespace pocketmine\block;

/**
 * The game's blocks by PocketMine-MP's names: VanillaBlocks::STONE(), in their default state.
 *
 * A name is the block's identifier in capitals, minecraft:oak_planks; the names PocketMine-MP gives otherwise are in
 * ALIASES. Each call gives a new block, without a position.
 *
 * @method static Block AIR()
 */
final class VanillaBlocks{
    private const ALIASES = [
        "grass" => "grass_block",
        "tall_grass" => "short_grass",
        "double_tallgrass" => "tall_grass",
        "nether_quartz_ore" => "quartz_ore",
        "sign" => "oak_sign",
        "wool" => "white_wool",
        "carpet" => "white_carpet",
        "concrete" => "white_concrete",
        "concrete_powder" => "white_concrete_powder",
        "stained_glass" => "white_stained_glass",
        "stained_glass_pane" => "white_stained_glass_pane",
        "stained_clay" => "white_terracotta",
        "glazed_terracotta" => "white_glazed_terracotta",
        "monster_spawner" => "mob_spawner",
        "lily_pad" => "waterlily",
    ];

    /** @var Block[] by identifier */
    private static array $prototypes = [];

    private function __construct(){
    }

    /** a block of that identifier, null when the game has none */
    public static function fromIdentifier(string $identifier) : ?Block{
        $identifier = strtolower($identifier);
        if(!str_contains($identifier, ":")){
            $identifier = "minecraft:" . $identifier;
        }
        if(!isset(self::$prototypes[$identifier])){
            // blocks with no item of their own, which falcon_item_exists() cannot tell
            $itemless = ["minecraft:air", "minecraft:water", "minecraft:flowing_water", "minecraft:lava",
                "minecraft:flowing_lava", "minecraft:fire", "minecraft:soul_fire", "minecraft:portal",
                "minecraft:end_portal", "minecraft:end_gateway", "minecraft:bubble_column"];
            if(!in_array($identifier, $itemless, true) && !falcon_item_exists($identifier)){
                return null;
            }
            self::$prototypes[$identifier] = Block::fromFalcon($identifier, "{}");
        }
        return clone self::$prototypes[$identifier];
    }

    /**
     * @param mixed[] $arguments
     */
    public static function __callStatic(string $name, array $arguments) : Block{
        if(count($arguments) > 0){
            throw new \ArgumentCountError("Expected exactly 0 arguments, " . count($arguments) . " passed");
        }
        $path = strtolower($name);
        $block = self::fromIdentifier(self::ALIASES[$path] ?? $path);
        if($block === null){
            throw new \Error("No such registry member: " . self::class . "::" . mb_strtoupper($name));
        }
        return $block;
    }
}
