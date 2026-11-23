<?php

declare(strict_types=1);

namespace pocketmine\entity;

class Villager extends Living{
    protected function getInitialSizeInfo() : EntitySizeInfo{
        return new EntitySizeInfo(1.8, 0.6);
    }

    public function getName() : string{
        return "Villager";
    }
}
