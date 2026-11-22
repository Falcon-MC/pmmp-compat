<?php

declare(strict_types=1);

namespace pocketmine\block\tile;

class Barrel extends Spawnable implements Container, Nameable{
    use NameableTrait;
    use ContainerTrait;

    public function getDefaultName() : string{
        return "Barrel";
    }
}
