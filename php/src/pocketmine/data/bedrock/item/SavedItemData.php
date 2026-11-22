<?php

declare(strict_types=1);

namespace pocketmine\data\bedrock\item;

use pocketmine\data\bedrock\block\BlockStateData;
use pocketmine\nbt\tag\CompoundTag;

/** An item type as the game saves it: name, meta, the block it places and its tag */
final class SavedItemData{
    public const TAG_NAME = "Name";
    public const TAG_DAMAGE = "Damage";
    public const TAG_BLOCK = "Block";
    public const TAG_TAG = "tag";

    public function __construct(
        private string $name,
        private int $meta = 0,
        private ?BlockStateData $block = null,
        private ?CompoundTag $tag = null
    ){}

    public function getName() : string{
        return $this->name;
    }

    public function getMeta() : int{
        return $this->meta;
    }

    public function getBlock() : ?BlockStateData{
        return $this->block;
    }

    public function getTag() : ?CompoundTag{
        return $this->tag;
    }

    public function toNbt() : CompoundTag{
        $result = CompoundTag::create()->setString(self::TAG_NAME, $this->name)->setShort(self::TAG_DAMAGE, $this->meta);
        if($this->block !== null){
            $result->setTag(self::TAG_BLOCK, $this->block->toNbt());
        }
        if($this->tag !== null){
            $result->setTag(self::TAG_TAG, $this->tag);
        }
        return $result;
    }
}
