<?php

declare(strict_types=1);

namespace pocketmine\item;

use pocketmine\block\Block;

/** An item that places one block on a floor and another on a wall: a torch, a sign */
class ItemBlockWallOrFloor extends Item{
    public function __construct(
        ItemIdentifier|string $identifier,
        private ?Block $floorVariant = null,
        private ?Block $wallVariant = null
    ){
        parent::__construct($identifier, $floorVariant?->getName() ?? "");
    }

    public function getBlock(?int $clickedFace = null) : Block{
        $horizontal = $clickedFace !== null && $clickedFace > 1;
        return clone (($horizontal ? $this->wallVariant : $this->floorVariant) ?? parent::getBlock($clickedFace));
    }
}
