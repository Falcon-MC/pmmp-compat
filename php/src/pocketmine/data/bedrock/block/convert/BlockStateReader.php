<?php

declare(strict_types=1);

namespace pocketmine\data\bedrock\block\convert;

use pocketmine\data\bedrock\block\BlockStateData;
use pocketmine\nbt\tag\ByteTag;
use pocketmine\nbt\tag\IntTag;
use pocketmine\nbt\tag\StringTag;

/** A saved block state, read state by state by what a plugin mapped the block with */
final class BlockStateReader{
    public function __construct(private BlockStateData $data){
    }

    public function getData() : BlockStateData{
        return $this->data;
    }

    public function readBool(string $name) : bool{
        $tag = $this->data->getState($name);
        return $tag instanceof ByteTag && $tag->getValue() !== 0;
    }

    public function readInt(string $name) : int{
        $tag = $this->data->getState($name);
        return $tag instanceof IntTag || $tag instanceof ByteTag ? $tag->getValue() : 0;
    }

    public function readString(string $name) : string{
        $tag = $this->data->getState($name);
        return $tag instanceof StringTag ? $tag->getValue() : "";
    }

    public function ignored(string $name) : void{
        //NOOP
    }

    public function todo(string $name) : void{
        //NOOP
    }

    public function checkUnreadProperties() : void{
        //NOOP
    }
}
