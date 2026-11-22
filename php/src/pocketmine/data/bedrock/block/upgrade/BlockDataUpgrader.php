<?php

declare(strict_types=1);

namespace pocketmine\data\bedrock\block\upgrade;

use pocketmine\data\bedrock\block\BlockStateData;
use pocketmine\nbt\tag\CompoundTag;

/** Saved block states of older games to the current one; Falcon reads its worlds itself, these pass through */
final class BlockDataUpgrader{
    public function upgradeIntIdMeta(int $id, int $meta) : BlockStateData{
        throw new \pocketmine\data\bedrock\block\BlockStateDeserializeException("Numeric block ids are not known any more ($id:$meta)");
    }

    public function upgradeStringIdMeta(string $id, int $meta) : BlockStateData{
        return BlockStateData::current($id, []);
    }

    public function upgradeBlockStateNbt(CompoundTag $tag) : BlockStateData{
        return BlockStateData::fromNbt($tag);
    }
}
