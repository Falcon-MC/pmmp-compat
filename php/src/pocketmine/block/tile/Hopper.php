<?php

declare(strict_types=1);

namespace pocketmine\block\tile;

class Hopper extends Spawnable implements Container, Nameable{
    use NameableTrait;
    use ContainerTrait;

    public function getDefaultName() : string{
        return "Hopper";
    }
}
