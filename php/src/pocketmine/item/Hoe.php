<?php

declare(strict_types=1);

namespace pocketmine\item;

class Hoe extends TieredTool{
    public function getBlockToolType() : int{
        return \pocketmine\block\BlockToolType::HOE;
    }
}
