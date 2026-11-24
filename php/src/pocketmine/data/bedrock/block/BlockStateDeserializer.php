<?php

declare(strict_types=1);

namespace pocketmine\data\bedrock\block;

use pocketmine\block\Block;
use pocketmine\block\RuntimeBlockStateRegistry;
use pocketmine\data\bedrock\block\convert\UnsupportedBlockStateException;

/** The game's saved states to block state ids */
final class BlockStateDeserializer{
    /** @var \Closure[] what plugins mapped their block ids with */
    private array $deserializers = [];

    public function map(string $id, \Closure $deserializer) : void{
        $this->deserializers[$id] = $deserializer;
    }

    public function mapSimple(string $id, \Closure $getBlock) : void{
        $this->deserializers[$id] = static fn() => $getBlock();
    }

    /**
     * the other ways PocketMine-MP maps ids, mapSlab(), mapStairs() and the like: the id comes first
     * @param mixed[] $arguments
     */
    public function __call(string $name, array $arguments) : void{
        if(!str_starts_with($name, "map") || !is_string($arguments[0] ?? null)){
            throw new \Error("Call to undefined method " . self::class . "::$name()");
        }
        $last = end($arguments);
        if($last instanceof \Closure){
            $this->deserializers[$arguments[0]] = static fn() => $last();
        }
    }

    /**
     * @throws BlockStateDeserializeException|UnsupportedBlockStateException
     */
    public function deserialize(BlockStateData $stateData) : int{
        $block = $this->deserializeBlock($stateData);
        $stateId = $block->getStateId();
        RuntimeBlockStateRegistry::getInstance()->rememberTranslatedState($block, $stateId);
        return $stateId;
    }

    public function deserializeBlock(BlockStateData $stateData) : Block{
        if(isset($this->deserializers[$stateData->getName()])){
            $block = ($this->deserializers[$stateData->getName()])(new \pocketmine\data\bedrock\block\convert\BlockStateReader($stateData));
            if($block instanceof Block){
                return $block;
            }
        }
        $name = $stateData->getName();
        $states = $stateData->toFalconStates();
        if(falcon_block_network_id($name, $states) === 0){
            if(\pmmpcompat\ItemTypes::block($name) === null){
                throw new UnsupportedBlockStateException("Unknown block type " . $name);
            }
            throw new BlockStateDeserializeException("Invalid states for " . $name);
        }
        return Block::fromFalcon($name, $states);
    }
}
