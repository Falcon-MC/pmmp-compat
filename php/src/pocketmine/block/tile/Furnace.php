<?php

declare(strict_types=1);

namespace pocketmine\block\tile;

/** A furnace, blast furnace or smoker; Falcon smelts */
abstract class Furnace extends Spawnable implements Container, Nameable{
    use NameableTrait;
    use ContainerTrait;

    public const TAG_BURN_TIME = "BurnTime";
    public const TAG_COOK_TIME = "CookTime";
    public const TAG_MAX_TIME = "MaxTime";

    public function getDefaultName() : string{
        return "Furnace";
    }

    public function getRemainingFuelTime() : int{
        return 0;
    }

    public function getCookProgress() : int{
        return 0;
    }

    public function getMaxFuelTime() : int{
        return 0;
    }
}
