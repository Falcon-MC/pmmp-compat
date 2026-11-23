<?php

declare(strict_types=1);

namespace pocketmine\item;

use pocketmine\utils\SingletonTrait;

/**
 * Items written as PocketMine-MP 3's strings, "diamond_sword" or "diamond_sword:5", with the damage after the colon.
 *
 * The numeric ids of those days ("276:0") are read through the game's old id map.
 */
final class LegacyStringToItemParser{
    use SingletonTrait;

    /** @var array<string, string> plugin-mapped legacy ids, "276" => "minecraft:diamond_sword" */
    private array $map = [];

    public function addMapping(string $alias, int $id) : void{
        $this->map[strtolower($alias)] = (string) $id;
    }

    /** @return array<string, string> */
    public function getMappings() : array{
        return $this->map;
    }

    /**
     * @throws LegacyStringToItemParserException
     */
    public function parse(string $input) : Item{
        $parts = explode(":", str_replace([" ", "minecraft:"], ["_", ""], trim($input)));
        $name = strtolower($parts[0]);
        $meta = 0;
        if(isset($parts[1])){
            if(!is_numeric($parts[1])){
                throw new LegacyStringToItemParserException("Unable to parse \"" . $parts[1] . "\" from \"" . $input . "\" as a valid meta value");
            }
            $meta = (int) $parts[1];
        }
        if(is_numeric($name)){
            $legacy = $this->map[$name] ?? $name;
            $name = \pocketmine\data\bedrock\item\upgrade\LegacyItemIdToStringIdMap::getInstance()->legacyToString((int) $legacy) ?? $name;
        }
        $item = is_numeric($name) ? null : StringToItemParser::getInstance()->parse($name);
        if($item === null){
            throw new LegacyStringToItemParserException("Unable to resolve \"" . $input . "\" to a valid item");
        }
        if($meta > 0){
            $item->setDamage($meta);
        }
        return $item;
    }
}
