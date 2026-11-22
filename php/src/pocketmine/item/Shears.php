<?php

declare(strict_types=1);

namespace pocketmine\item;

class Shears extends Tool{
    public function getBlockToolType() : int{
        return \pocketmine\block\BlockToolType::SHEARS;
    }

    public function getBlockToolHarvestLevel() : int{
        return 1;
    }

    protected function getBaseMiningEfficiency() : float{
        return 15;
    }
}
