<?php

declare(strict_types=1);

namespace pocketmine\data\bedrock\item\upgrade;

use pocketmine\data\bedrock\item\SavedItemData;
use pocketmine\data\bedrock\item\SavedItemStackData;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\StringTag;

/** Saved items of older games to the current form: names pass through, old numeric ids are not known any more */
final class ItemDataUpgrader{
    public function upgradeItemTypeDataString(string $rawNameId, int $meta, int $count, ?CompoundTag $nbt) : SavedItemStackData{
        return new SavedItemStackData(new SavedItemData($rawNameId, $meta, null, $nbt), $count, null, null, [], []);
    }

    public function upgradeItemTypeDataInt(int $legacyNumericId, int $meta, int $count, ?CompoundTag $nbt) : SavedItemStackData{
        throw new \pocketmine\data\bedrock\item\ItemTypeDeserializeException("Numeric item ids are not known any more ($legacyNumericId)");
    }

    public function upgradeItemStackNbt(CompoundTag $tag) : ?SavedItemStackData{
        $name = $tag->getTag(SavedItemData::TAG_NAME);
        if(!$name instanceof StringTag){
            return null;
        }
        $slot = $tag->getTag(SavedItemStackData::TAG_SLOT);
        return new SavedItemStackData(
            new SavedItemData($name->getValue(), $tag->getShort(SavedItemData::TAG_DAMAGE, 0), null, $tag->getCompoundTag(SavedItemData::TAG_TAG)),
            \pocketmine\utils\Binary::unsignByte($tag->getByte(SavedItemStackData::TAG_COUNT, 1)),
            $slot === null ? null : $tag->getByte(SavedItemStackData::TAG_SLOT),
            null, [], []
        );
    }
}
