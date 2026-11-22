<?php

declare(strict_types=1);

namespace pocketmine\block\tile;

interface Nameable{
    public const TAG_CUSTOM_NAME = "CustomName";

    public function getDefaultName() : string;

    public function getName() : string;

    public function setName(string $name) : void;

    public function hasName() : bool;
}
