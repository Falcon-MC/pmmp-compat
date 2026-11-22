<?php

declare(strict_types=1);

namespace pocketmine\player;

use pocketmine\permission\ServerOperator;

interface IPlayer extends ServerOperator{
    public function getName() : string;

    public function getFirstPlayed() : ?int;

    public function getLastPlayed() : ?int;

    public function hasPlayedBefore() : bool;
}
