<?php

declare(strict_types=1);

namespace pocketmine\item;

/** An item that places a block */
class ItemBlock extends Item{
    private ?\pocketmine\block\Block $block = null;

    /** @internal a block registered for this item by a plugin */
    public function bindBlock(\pocketmine\block\Block $block) : void{
        $this->block = clone $block;
    }

    public function getBlock(?int $clickedFace = null) : \pocketmine\block\Block{
        if($this->block !== null){
            return clone $this->block;
        }
        return \pocketmine\block\VanillaBlocks::fromIdentifier($this->getFalconIdentifier()) ?? \pocketmine\block\VanillaBlocks::AIR();
    }

    public function getFuelTime() : int{
        return 0;
    }
}
