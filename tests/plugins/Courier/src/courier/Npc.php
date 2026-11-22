<?php

declare(strict_types=1);

namespace courier;

use pocketmine\entity\Human;
use pocketmine\entity\Location;
use pocketmine\nbt\tag\CompoundTag;

// a plugin's own entity class, as NPC plugins declare them
final class Npc extends Human{
    public function __construct(Location $location, ?CompoundTag $nbt = null){
        parent::__construct(0);
    }
}
