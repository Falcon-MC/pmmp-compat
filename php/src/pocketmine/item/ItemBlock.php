<?php

declare(strict_types=1);

namespace pocketmine\item;

/** An item that places a block */
class ItemBlock extends Item{
    public function getBlock(?int $clickedFace = null) : \pocketmine\block\Block{
        return \pocketmine\block\VanillaBlocks::fromIdentifier($this->getFalconIdentifier()) ?? \pocketmine\block\VanillaBlocks::AIR();
    }

    public function getFuelTime() : int{
        return 0;
    }
}
