<?php

declare(strict_types=1);

namespace pocketmine\entity;

class Squid extends Living{
    protected function getInitialSizeInfo() : EntitySizeInfo{
        return new EntitySizeInfo(0.95, 0.95);
    }

    public function getName() : string{
        return "Squid";
    }
}
