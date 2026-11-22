<?php

declare(strict_types=1);

namespace pocketmine\entity\projectile;

class Arrow extends Projectile{
    public const PICKUP_NONE = 0;
    public const PICKUP_ANY = 1;
    public const PICKUP_CREATIVE = 2;

    private int $pickupMode = self::PICKUP_ANY;
    private bool $critical = false;
    private float $punchKnockback = 0.0;

    public function isCritical() : bool{
        return $this->critical;
    }

    public function setCritical(bool $value = true) : void{
        $this->critical = $value;
    }

    public function getPickupMode() : int{
        return $this->pickupMode;
    }

    public function setPickupMode(int $pickupMode) : void{
        $this->pickupMode = $pickupMode;
    }

    public function getPunchKnockback() : float{
        return $this->punchKnockback;
    }

    public function setPunchKnockback(float $punchKnockback) : void{
        $this->punchKnockback = $punchKnockback;
    }
}
