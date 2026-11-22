<?php

declare(strict_types=1);

namespace pocketmine\entity;

use pocketmine\entity\effect\EffectManager;
use pocketmine\entity\effect\VanillaEffects;
use pocketmine\event\entity\EntityRegainHealthEvent;
use pocketmine\math\Vector3;
use function sqrt;

/** An entity with health that can die: mobs and players */
abstract class Living extends Entity{
    public const DEFAULT_BREATH_TICKS = 300;
    public const DEFAULT_KNOCKBACK_FORCE = 0.4;
    public const DEFAULT_KNOCKBACK_VERTICAL_LIMIT = 0.4;

    private const ATTRIBUTE_ABSORPTION = "minecraft:absorption";
    private const ATTRIBUTE_MOVEMENT = "minecraft:movement";

    private ?EffectManager $effectManager = null;

    public function getEyeHeight() : float{
        return 1.62;
    }

    public function getEffects() : EffectManager{
        return $this->effectManager ??= new EffectManager($this);
    }

    /** gives health back as PocketMine-MP does: through an event a listener can change or cancel */
    public function heal(EntityRegainHealthEvent $source) : void{
        $source->call();
        if($source->isCancelled()){
            return;
        }
        $this->setHealth(min($this->getMaxHealth(), $this->getHealth() + $source->getAmount()));
    }

    public function getAbsorption() : float{
        return $this->attribute(self::ATTRIBUTE_ABSORPTION)[0] ?? 0.0;
    }

    public function setAbsorption(float $absorption) : void{
        $this->setAttribute(self::ATTRIBUTE_ABSORPTION, $absorption);
    }

    public function getMovementSpeed() : float{
        return $this->attribute(self::ATTRIBUTE_MOVEMENT)[0] ?? 0.1;
    }

    public function setMovementSpeed(float $v, bool $fit = false) : void{
        $this->setAttribute(self::ATTRIBUTE_MOVEMENT, $v);
    }

    /** pushes the entity away, as a hit does */
    public function knockBack(float $x, float $z, float $force = self::DEFAULT_KNOCKBACK_FORCE, ?float $verticalLimit = self::DEFAULT_KNOCKBACK_VERTICAL_LIMIT) : void{
        $f = sqrt($x * $x + $z * $z);
        if($f <= 0){
            return;
        }
        $f = 1 / $f;
        $motion = $this->getMotion();
        $motionX = $motion->x / 2 + $x * $f * $force;
        $motionY = $motion->y / 2 + $force;
        $motionZ = $motion->z / 2 + $z * $f * $force;
        $verticalLimit ??= $force;
        if($motionY > $verticalLimit){
            $motionY = $verticalLimit;
        }
        $this->setMotion(new Vector3($motionX, $motionY, $motionZ));
    }

    public function jump() : void{
        $this->setMotion($this->getMotion()->withComponents(null, $this->getJumpVelocity(), null));
    }

    public function getJumpVelocity() : float{
        $boost = $this->getEffects()->get(VanillaEffects::JUMP_BOOST());
        return 0.42 + ($boost !== null ? $boost->getEffectLevel() / 10 : 0);
    }

    /** armour points: what Falcon's armour slots give, for a player */
    public function getArmorPoints() : int{
        return 0;
    }

    public function isSprinting() : bool{
        return false;
    }

    public function isSneaking() : bool{
        return false;
    }

    public function getAirSupplyTicks() : int{
        return self::DEFAULT_BREATH_TICKS;
    }

    public function getMaxAirSupplyTicks() : int{
        return self::DEFAULT_BREATH_TICKS;
    }

    public function canBreathe() : bool{
        return true;
    }
}
