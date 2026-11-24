<?php

declare(strict_types=1);

namespace pocketmine\item;

use pocketmine\utils\SingletonTrait;

/**
 * Items from what a player types: "diamond sword", "minecraft:stone", "oak_planks".
 *
 * Any identifier the game knows is taken, with or without its namespace and with spaces for underscores; aliases a
 * plugin registers come first.
 */
final class StringToItemParser{
    use SingletonTrait;

    /** @var \Closure[] by alias */
    private array $callbackMap = [];
    /** @var array<string, true>|null Names from the installed Bedrock palette. */
    private ?array $vanillaAliases = null;

    private static function normalise(string $input) : string{
        return strtolower(str_replace([" ", "minecraft:"], ["_", ""], trim($input)));
    }

    /**
     * @phpstan-param \Closure(string $input) : Item $callback
     */
    public function register(string $alias, \Closure $callback) : void{
        $key = self::normalise($alias);
        if(isset($this->callbackMap[$key])){
            throw new \InvalidArgumentException("Alias \"$key\" is already registered");
        }
        $this->callbackMap[$key] = $callback;
    }

    /**
     * @phpstan-param \Closure(string $input) : Item $callback
     */
    public function override(string $alias, \Closure $callback) : void{
        $this->callbackMap[self::normalise($alias)] = $callback;
    }

    public function parse(string $input) : ?Item{
        $key = self::normalise($input);
        if(isset($this->callbackMap[$key])){
            return ($this->callbackMap[$key])($key);
        }
        return $this->parseVanilla($key);
    }

    private function parseVanilla(string $key) : ?Item{
        if($key === ""){
            return null;
        }
        $item = VanillaItems::fromName($key);
        if($item === null){
            // a block's name, as PocketMine-MP's names it: the item that places it
            $block = \pocketmine\block\VanillaBlocks::fromName($key);
            $item = $block !== null && $block->getFalconIdentifier() !== "minecraft:air" ? $block->asItem() : null;
        }
        return $item === null || ($item->isNull() && $key !== "air") ? null : $item;
    }

    public function parseBlock(string $input) : ?\pocketmine\block\Block{
        $item = $this->parse($input);
        if($item === null){
            return null;
        }
        $block = $item->getBlock();
        return $block->getFalconIdentifier() === "minecraft:air" && $item->getFalconIdentifier() !== "minecraft:air" ? null : $block;
    }

    /**
     * the names an item goes by: the aliases registered for it, and its own identifier's
     * @return string[]
     */
    public function lookupAliases(Item $item) : array{
        $aliases = [];
        foreach($this->callbackMap as $alias => $callback){
            $made = $callback($alias);
            if($made instanceof Item && $made->equals($item, true, false)){
                $aliases[] = $alias;
            }
        }
        $own = self::normalise($item->getFalconIdentifier());
        if(!in_array($own, $aliases, true)){
            $aliases[] = $own;
        }
        return $aliases;
    }

    /** @return string[] */
    public function lookupBlockAliases(\pocketmine\block\Block $block) : array{
        return $this->lookupAliases($block->asItem());
    }

    /** @return string[] */
    public function getKnownAliases() : array{
        if($this->vanillaAliases === null){
            $this->vanillaAliases = [];
            foreach(\pocketmine\network\mcpe\convert\TypeConverter::getInstance()->getItemTypeDictionary()->getEntries() as $entry){
                $key = self::normalise($entry->getStringId());
                if($this->parseVanilla($key) !== null){
                    $this->vanillaAliases[$key] = true;
                }
            }
        }
        return array_map(strval(...), array_keys($this->callbackMap + $this->vanillaAliases));
    }
}
