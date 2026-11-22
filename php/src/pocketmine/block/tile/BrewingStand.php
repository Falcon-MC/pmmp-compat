<?php

declare(strict_types=1);

namespace pocketmine\block\tile;

class BrewingStand extends Spawnable implements Container, Nameable{
    use NameableTrait;
    use ContainerTrait;

    public const BREW_TIME_TICKS = 400;

    public function getDefaultName() : string{
        return "Brewing Stand";
    }
}
