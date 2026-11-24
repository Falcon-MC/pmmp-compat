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
        // signs are standing signs and wall signs as blocks, named for their wood as items
        "sign" => "standing_sign",
        "oak_sign" => "standing_sign",
        "oak_wall_sign" => "wall_sign",
        "spruce_sign" => "spruce_standing_sign",
        "birch_sign" => "birch_standing_sign",
        "jungle_sign" => "jungle_standing_sign",
        "acacia_sign" => "acacia_standing_sign",
        "dark_oak_sign" => "darkoak_standing_sign",
        "dark_oak_wall_sign" => "darkoak_wall_sign",
        "mangrove_sign" => "mangrove_standing_sign",
        "cherry_sign" => "cherry_standing_sign",
        "pale_oak_sign" => "pale_oak_standing_sign",
        "bamboo_sign" => "bamboo_standing_sign",
        "crimson_sign" => "crimson_standing_sign",
        "warped_sign" => "warped_standing_sign",
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
        "diamond" => "diamond_block",
        "gold" => "gold_block",
        "iron" => "iron_block",
        "emerald" => "emerald_block",
        "lapis_lazuli" => "lapis_block",
        "lapis_lazuli_ore" => "lapis_ore",
        "redstone" => "redstone_block",
        "coal" => "coal_block",
        "netherite" => "netherite_block",
        "copper" => "copper_block",
        "quartz" => "quartz_block",
        "amethyst" => "amethyst_block",
        "raw_iron" => "raw_iron_block",
        "raw_gold" => "raw_gold_block",
        "raw_copper" => "raw_copper_block",
        "hay_bale" => "hay_block",
        "melon" => "melon_block",
        "end_stone_bricks" => "end_bricks",
        "nether_bricks" => "nether_brick",
        "red_nether_bricks" => "red_nether_brick",
        "dried_kelp" => "dried_kelp_block",
        "cobweb" => "web",
        "sugarcane" => "reeds",
        "nether_portal" => "portal",
        "glowing_obsidian" => "glowingobsidian",
        "item_frame" => "frame",
        "glowing_item_frame" => "glow_frame",
        "mob_head" => "skeleton_skull",
    ];

    /** @var Block[] by identifier */
    private static array $prototypes = [];

    private function __construct(){
    }

    /** a block of that identifier, null when the game has none */
    /** a block by PocketMine-MP's name for it, DIAMOND for the diamond block, or by the game's identifier */
    public static function fromName(string $name) : ?Block{
        $path = strtolower($name);
        return self::fromIdentifier(self::ALIASES[$path] ?? $path);
    }

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
    /**
     * members plugins put in place of the game's, by upper-case name: PocketMine-MP keeps its registry here, and
     * plugins that replace one of its members through reflection write to it
     * @var Block[]
     */
    private static array $members = [];

    /** @return Block[] the members plugins set, by upper-case name */
    public static function getAll() : array{
        return array_map(static fn(Block $member) => clone $member, self::$members);
    }

    public static function __callStatic(string $name, array $arguments) : Block{
        if(count($arguments) > 0){
            throw new \ArgumentCountError("Expected exactly 0 arguments, " . count($arguments) . " passed");
        }
        $upper = mb_strtoupper($name);
        if(isset(self::$members[$upper])){
            return clone self::$members[$upper];
        }
        $path = strtolower($name);
        $block = self::fromIdentifier(self::ALIASES[$path] ?? $path);
        if($block === null){
            throw new \Error("No such registry member: " . self::class . "::" . mb_strtoupper($name));
        }
        return $block;
    }
}
