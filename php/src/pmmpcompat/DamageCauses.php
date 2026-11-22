<?php

declare(strict_types=1);

namespace pmmpcompat;

use pocketmine\event\entity\EntityDamageEvent;

/**
 * PocketMine-MP's damage causes and Falcon's, which names them by the game's death message keys.
 */
final class DamageCauses{
    private const BY_KEY = [
        "death.attack.player" => EntityDamageEvent::CAUSE_ENTITY_ATTACK,
        "death.attack.mob" => EntityDamageEvent::CAUSE_ENTITY_ATTACK,
        "death.attack.arrow" => EntityDamageEvent::CAUSE_PROJECTILE,
        "death.attack.thrown" => EntityDamageEvent::CAUSE_PROJECTILE,
        "death.attack.trident" => EntityDamageEvent::CAUSE_PROJECTILE,
        "death.attack.fall" => EntityDamageEvent::CAUSE_FALL,
        "death.attack.fallingBlock" => EntityDamageEvent::CAUSE_FALLING_BLOCK,
        "death.attack.anvil" => EntityDamageEvent::CAUSE_FALLING_BLOCK,
        "death.attack.inFire" => EntityDamageEvent::CAUSE_FIRE,
        "death.attack.onFire" => EntityDamageEvent::CAUSE_FIRE_TICK,
        "death.attack.lava" => EntityDamageEvent::CAUSE_LAVA,
        "death.attack.drown" => EntityDamageEvent::CAUSE_DROWNING,
        "death.attack.inWall" => EntityDamageEvent::CAUSE_SUFFOCATION,
        "death.attack.cactus" => EntityDamageEvent::CAUSE_CONTACT,
        "death.attack.sweetBerry" => EntityDamageEvent::CAUSE_CONTACT,
        "death.attack.explosion" => EntityDamageEvent::CAUSE_ENTITY_EXPLOSION,
        "death.attack.explosion.player" => EntityDamageEvent::CAUSE_ENTITY_EXPLOSION,
        "death.attack.outOfWorld" => EntityDamageEvent::CAUSE_VOID,
        "death.attack.magic" => EntityDamageEvent::CAUSE_MAGIC,
        "death.attack.wither" => EntityDamageEvent::CAUSE_MAGIC,
        "death.attack.starve" => EntityDamageEvent::CAUSE_STARVATION,
        "death.attack.generic" => EntityDamageEvent::CAUSE_CUSTOM,
        "death.attack.lightningBolt" => EntityDamageEvent::CAUSE_CUSTOM,
        "death.attack.thorns" => EntityDamageEvent::CAUSE_CUSTOM,
    ];

    private function __construct(){
    }

    public static function fromFalcon(string $key) : int{
        return self::BY_KEY[$key] ?? EntityDamageEvent::CAUSE_CUSTOM;
    }

    /** the first key of a cause is the one it goes back to Falcon as */
    public static function toFalcon(int $cause) : string{
        $key = array_search($cause, self::BY_KEY, true);
        return is_string($key) ? $key : "death.attack.generic";
    }
}
