<?php

declare(strict_types=1);

namespace pocketmine\item;

class Pickaxe extends TieredTool{
    public function getBlockToolType() : int{
        return \pocketmine\block\BlockToolType::PICKAXE;
    }
}
