<?php

declare(strict_types=1);

namespace pocketmine\block;

class Lava extends Liquid{
    public function getLightLevel() : int{
        return 15;
    }
}
