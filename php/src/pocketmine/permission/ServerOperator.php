<?php

declare(strict_types=1);

namespace pocketmine\permission;

interface ServerOperator{
    public function isOp() : bool;

    public function setOp(bool $value) : void;
}
