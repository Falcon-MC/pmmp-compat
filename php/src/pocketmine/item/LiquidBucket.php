<?php

declare(strict_types=1);

namespace pocketmine\item;

use pocketmine\block\Block;
use pocketmine\block\Liquid;
use pocketmine\block\VanillaBlocks;

/** A bucket of water or lava */
class LiquidBucket extends Bucket{
    public function __construct(ItemIdentifier|string $identifier, string $name = "", private ?Liquid $liquid = null){
        parent::__construct($identifier, $name);
    }

    public function getMaxStackSize() : int{
        return 1;
    }

    public function getLiquid() : Liquid{
        if($this->liquid !== null){
            return clone $this->liquid;
        }
        $block = str_contains($this->getFalconIdentifier(), "lava") ? VanillaBlocks::LAVA() : VanillaBlocks::WATER();
        assert($block instanceof Liquid);
        return $block;
    }

    public function getFuelTime() : int{
        return $this->getLiquid()->getFalconIdentifier() === "minecraft:lava" ? 20000 : 0;
    }
}
