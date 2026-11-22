<?php

declare(strict_types=1);

namespace pocketmine\item;

class Shovel extends TieredTool{
    public function getBlockToolType() : int{
        return \pocketmine\block\BlockToolType::SHOVEL;
    }
}
