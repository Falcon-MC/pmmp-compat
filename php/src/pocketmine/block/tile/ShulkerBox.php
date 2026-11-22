<?php

declare(strict_types=1);

namespace pocketmine\block\tile;

class ShulkerBox extends Spawnable implements Container, Nameable{
    use NameableTrait;
    use ContainerTrait;

    public const TAG_FACING = "facing";

    public function getDefaultName() : string{
        return "Shulker Box";
    }
}
