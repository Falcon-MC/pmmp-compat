<?php

declare(strict_types=1);

namespace pocketmine\item;

/**
 * The game's items by PocketMine-MP's names: VanillaItems::DIAMOND_SWORD().
 *
 * A name is the item's identifier in capitals, minecraft:diamond_sword; the few names PocketMine-MP gives otherwise
 * are listed in ALIASES. Each call gives a new stack of one, as PocketMine-MP's registry does.
 *
 * @method static Item AIR()
 */
final class VanillaItems{
    /** PocketMine-MP's names that are not the game's identifier */
    private const ALIASES = [
        "steak" => "cooked_beef",
        "raw_beef" => "beef",
        "raw_chicken" => "chicken",
        "raw_porkchop" => "porkchop",
        "raw_mutton" => "mutton",
        "raw_rabbit" => "rabbit",
        "raw_fish" => "cod",
        "raw_salmon" => "salmon",
        "cooked_fish" => "cooked_cod",
        "clownfish" => "tropical_fish",
        "dye" => "white_dye",
        "nether_quartz" => "quartz",
        "glistering_melon" => "glistering_melon_slice",
        "melon" => "melon_slice",
        "scute" => "turtle_scute",
    ];

    /** @var Item[] by identifier */
    private static array $prototypes = [];

    private function __construct(){
    }

    /** an item of that identifier, null when the game has none */
    public static function fromIdentifier(string $identifier) : ?Item{
        $identifier = strtolower($identifier);
        if(!str_contains($identifier, ":")){
            $identifier = "minecraft:" . $identifier;
        }
        if(!isset(self::$prototypes[$identifier])){
            if($identifier !== "minecraft:air" && !falcon_item_exists($identifier)){
                return null;
            }
            self::$prototypes[$identifier] = new Item($identifier);
        }
        return clone self::$prototypes[$identifier];
    }

    /**
     * @param mixed[] $arguments
     */
    public static function __callStatic(string $name, array $arguments) : Item{
        if(count($arguments) > 0){
            throw new \ArgumentCountError("Expected exactly 0 arguments, " . count($arguments) . " passed");
        }
        $path = strtolower($name);
        $item = self::fromIdentifier(self::ALIASES[$path] ?? $path);
        if($item === null){
            throw new \Error("No such registry member: " . self::class . "::" . mb_strtoupper($name));
        }
        return $item;
    }
}
