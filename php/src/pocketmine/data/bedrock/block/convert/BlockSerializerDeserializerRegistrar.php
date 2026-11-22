<?php

declare(strict_types=1);

namespace pocketmine\data\bedrock\block\convert;

use pocketmine\block\Block;
use pocketmine\data\bedrock\block\BlockStateDeserializer;
use pocketmine\data\bedrock\block\BlockStateSerializer;

/** A plugin's block mapped both ways at once */
final class BlockSerializerDeserializerRegistrar{
    public function __construct(
        public BlockStateDeserializer $deserializer,
        public BlockStateSerializer $serializer
    ){}

    public function mapSimple(Block $block, string $id) : void{
        $this->deserializer->mapSimple($id, fn() => clone $block);
        $this->serializer->mapSimple($block, $id);
    }

    /**
     * the other ways PocketMine-MP maps blocks: the block first, its id second
     * @param mixed[] $arguments
     */
    public function __call(string $name, array $arguments) : void{
        $block = $arguments[0] ?? null;
        $id = $arguments[1] ?? null;
        if(!str_starts_with($name, "map") || !$block instanceof Block){
            throw new \Error("Call to undefined method " . self::class . "::$name()");
        }
        if(is_string($id)){
            $this->mapSimple($block, $id);
        }
    }
}
