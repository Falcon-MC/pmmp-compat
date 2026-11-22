<?php

declare(strict_types=1);

namespace pocketmine\player;

use pocketmine\nbt\tag\CompoundTag;
use pocketmine\Server;

/** A player who is not online: what the server knows of them is their name */
class OfflinePlayer implements IPlayer{
    public function __construct(
        private string $name,
        private ?CompoundTag $namedtag
    ){
    }

    public function getName() : string{
        return $this->name;
    }

    public function isOp() : bool{
        return Server::getInstance()->isOp($this->name);
    }

    public function setOp(bool $value) : void{
        $value ? Server::getInstance()->addOp($this->name) : Server::getInstance()->removeOp($this->name);
    }

    public function getFirstPlayed() : ?int{
        return $this->namedtag?->getLong("firstPlayed", 0);
    }

    public function getLastPlayed() : ?int{
        return $this->namedtag?->getLong("lastPlayed", 0);
    }

    public function hasPlayedBefore() : bool{
        return $this->namedtag !== null;
    }
}
