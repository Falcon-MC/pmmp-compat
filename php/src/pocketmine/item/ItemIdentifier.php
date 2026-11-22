<?php

declare(strict_types=1);

namespace pocketmine\item;

/** The type id of an item a plugin defines, as PocketMine-MP's item classes take it */
class ItemIdentifier{
    public function __construct(
        private int $typeId
    ){}

    public static function fromBlock(\pocketmine\block\Block $block) : self{
        return new self(ItemTypeIds::fromBlockTypeId($block->getTypeId()));
    }

    public function getTypeId() : int{
        return $this->typeId;
    }
}
