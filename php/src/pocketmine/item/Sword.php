<?php

declare(strict_types=1);

namespace pocketmine\item;

class Sword extends TieredTool{
    public function getBlockToolType() : int{
        return \pocketmine\block\BlockToolType::SWORD;
    }
}
