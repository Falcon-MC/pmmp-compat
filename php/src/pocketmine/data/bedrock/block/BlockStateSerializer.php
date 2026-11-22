<?php

declare(strict_types=1);

namespace pocketmine\data\bedrock\block;

use pocketmine\block\Block;

/** Block state ids to the game's saved states */
final class BlockStateSerializer{
    /** @var \Closure[] what plugins mapped their blocks with, by type id */
    private array $serializers = [];

    /** a plugin's block and how it saves: kept, the block reads back through it */
    public function map(Block $block, \Closure $serializer) : void{
        $this->serializers[$block->getTypeId()] = $serializer;
    }

    public function mapSimple(Block $block, string $id) : void{
        $this->serializers[$block->getTypeId()] = static fn() => BlockStateData::current($id, []);
    }

    /**
     * the other ways PocketMine-MP maps blocks, mapSlab(), mapStairs() and the like: the block is the first
     * argument, its id the second when there is one
     * @param mixed[] $arguments
     */
    public function __call(string $name, array $arguments) : void{
        if(!str_starts_with($name, "map") || !($arguments[0] ?? null) instanceof Block){
            throw new \Error("Call to undefined method " . self::class . "::$name()");
        }
        $id = is_string($arguments[1] ?? null) ? $arguments[1] : null;
        $this->serializers[$arguments[0]->getTypeId()] = $id !== null
            ? static fn() => BlockStateData::current($id, [])
            : ($arguments[1] ?? null);
    }

    /**
     * @throws BlockStateSerializeException
     */
    public function serialize(int $stateId) : BlockStateData{
        $registered = \pocketmine\block\RuntimeBlockStateRegistry::getInstance()->getAllKnownStates()[$stateId] ?? null;
        if($registered !== null && isset($this->serializers[$registered->getTypeId()]) && $this->serializers[$registered->getTypeId()] instanceof \Closure){
            $data = ($this->serializers[$registered->getTypeId()])($registered);
            if($data instanceof BlockStateData){
                return $data;
            }
        }
        $state = Block::stateOf($stateId);
        if($state === null){
            throw new BlockStateSerializeException("No block state is known by id $stateId");
        }
        return BlockStateData::fromFalcon($state[0], $state[1]);
    }

    public function serializeBlock(Block $block) : BlockStateData{
        return BlockStateData::fromFalcon($block->getFalconIdentifier(), $block->getFalconStates());
    }
}
