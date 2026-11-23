<?php

declare(strict_types=1);

namespace pocketmine\entity;

class Zombie extends Living{
    protected function getInitialSizeInfo() : EntitySizeInfo{
        return new EntitySizeInfo(1.8, 0.6);
    }

    public function getName() : string{
        return "Zombie";
    }
}
