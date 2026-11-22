<?php

declare(strict_types=1);

namespace pocketmine\item;

class Axe extends TieredTool{
    public function getBlockToolType() : int{
        return \pocketmine\block\BlockToolType::AXE;
    }
}
