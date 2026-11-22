<?php

declare(strict_types=1);

namespace pocketmine\entity\object;

use pocketmine\entity\Entity;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;

/** A dropped item stack on the ground; Falcon merges, despawns and gives it to the players who walk on it */
class ItemEntity extends Entity{
    public const MERGE_CHECK_PERIOD = 2;
    public const DEFAULT_DESPAWN_DELAY = 6000;
    public const NEVER_DESPAWN = -1;

    private ?Item $item = null;
    private int $pickupDelay = 10;
    private int $despawnDelay = self::DEFAULT_DESPAWN_DELAY;
    private ?string $owner = null;
    private ?string $thrower = null;

    /** @internal the stack, as the event that made or took it tells */
    public function setItemFromFalcon(Item $item) : void{
        $this->item = $item;
    }

    public function getItem() : Item{
        return clone ($this->item ?? VanillaItems::AIR());
    }

    public function getPickupDelay() : int{
        return $this->pickupDelay;
    }

    public function setPickupDelay(int $delay) : void{
        $this->pickupDelay = $delay;
    }

    public function getDespawnDelay() : int{
        return $this->despawnDelay;
    }

    public function setDespawnDelay(int $despawnDelay) : void{
        $this->despawnDelay = $despawnDelay;
    }

    public function getOwner() : ?string{
        return $this->owner;
    }

    public function setOwner(string $owner) : void{
        $this->owner = $owner;
    }

    public function getThrower() : ?string{
        return $this->thrower;
    }

    public function setThrower(string $thrower) : void{
        $this->thrower = $thrower;
    }
}
