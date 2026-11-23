<?php

declare(strict_types=1);

namespace pocketmine\entity\projectile;

use pocketmine\entity\Entity;

/** One of Falcon's projectiles: an arrow, a snowball, a trident... Falcon moves it and makes it hit */
abstract class Projectile extends Entity{
    protected ?int $ownerId = null;
    protected float $damage = 0.0;

    /** @internal who shot it, as Falcon's launch event tells */
    public function setOwnerFromFalcon(?Entity $owner) : void{
        $this->ownerId = $owner?->getId();
    }

    public function getOwningEntityId() : ?int{
        return $this->ownerId;
    }

    public function getOwningEntity() : ?Entity{
        return $this->ownerId === null ? null : $this->getServer()->getWorldManager()->findEntity($this->ownerId);
    }

    public function setOwningEntity(?Entity $owner) : void{
        $this->ownerId = $owner?->getId();
    }

    public function getBaseDamage() : float{
        return $this->damage;
    }

    public function setBaseDamage(float $damage) : void{
        $this->damage = $damage;
    }

    public function getResultDamage() : int{
        return (int) ceil($this->damage);
    }
}
