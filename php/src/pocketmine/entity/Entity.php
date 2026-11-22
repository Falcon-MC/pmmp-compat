<?php

declare(strict_types=1);

namespace pocketmine\entity;

use pmmpcompat\DamageCauses;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\math\Vector3;
use pocketmine\Server;
use pocketmine\world\Position;
use pocketmine\world\World;

/**
 * An entity, standing for one of Falcon's by its runtime id.
 *
 * Falcon is asked again on each call, so an object a plugin keeps after the entity is gone reads as closed and does
 * nothing, as PocketMine-MP's closed entities do.
 */
abstract class Entity{
    private bool $closed = false;
    private bool $flaggedForDespawn = false;
    private ?EntityDamageEvent $lastDamageCause = null;

    /** @internal Entities and Players make them */
    public function __construct(
        private int $runtimeId
    ){
    }

    /** @internal Falcon's handle now, 0 once the entity is gone */
    public function getEntityHandle() : int{
        if($this->closed){
            return 0;
        }
        $handle = falcon_server_entity($this->runtimeId);
        if($handle === 0){
            $this->closed = true;
        }
        return $handle;
    }

    /**
     * @return array{type: string, level: int, position: float[], rotation: float[], motion: float[], health: float,
     *     maxHealth: float, alive: bool, nameTag: string, player: int}|null
     */
    private function info() : ?array{
        $handle = $this->getEntityHandle();
        return $handle === 0 ? null : falcon_entity_info($handle);
    }

    public function getId() : int{
        return $this->runtimeId;
    }

    /** the game's identifier, minecraft:zombie */
    public function getNetworkTypeId() : string{
        return $this->info()["type"] ?? "";
    }

    public function isClosed() : bool{
        return $this->getEntityHandle() === 0;
    }

    public function close() : void{
        $handle = $this->getEntityHandle();
        if($handle !== 0 && !$this instanceof \pocketmine\player\Player){
            falcon_entity_remove($handle);
        }
        $this->closed = true;
    }

    public function flagForDespawn() : void{
        $this->flaggedForDespawn = true;
        $this->close();
    }

    public function isFlaggedForDespawn() : bool{
        return $this->flaggedForDespawn;
    }

    public function getWorld() : World{
        $world = Server::getInstance()->getWorldManager()->forLevel($this->info()["level"] ?? 0);
        if($world === null){
            throw new \LogicException("The entity is not in a world");
        }
        return $world;
    }

    public function getPosition() : Position{
        $info = $this->info();
        if($info === null){
            return new Position(0, 0, 0, null);
        }
        [$x, $y, $z] = $info["position"];
        return new Position($x, $y, $z, Server::getInstance()->getWorldManager()->forLevel($info["level"]));
    }

    /** Falcon gives the rotation as pitch, yaw */
    public function getLocation() : Location{
        $info = $this->info();
        if($info === null){
            return new Location(0, 0, 0, null, 0, 0);
        }
        [$x, $y, $z] = $info["position"];
        [$pitch, $yaw] = $info["rotation"];
        return new Location($x, $y, $z, Server::getInstance()->getWorldManager()->forLevel($info["level"]), $yaw, $pitch);
    }

    public function getEyeHeight() : float{
        return 0.0;
    }

    public function getEyePos() : Vector3{
        $position = $this->getPosition();
        return new Vector3($position->x, $position->y + $this->getEyeHeight(), $position->z);
    }

    public function getDirectionVector() : Vector3{
        $location = $this->getLocation();
        $y = -sin(deg2rad($location->pitch));
        $xz = cos(deg2rad($location->pitch));
        $x = -$xz * sin(deg2rad($location->yaw));
        $z = $xz * cos(deg2rad($location->yaw));
        return (new Vector3($x, $y, $z))->normalize();
    }

    /**
     * @param float|null $yaw kept for PocketMine-MP's signature: Falcon keeps the entity's facing
     */
    public function teleport(Vector3 $pos, ?float $yaw = null, ?float $pitch = null) : bool{
        $handle = $this->getEntityHandle();
        if($handle === 0){
            return false;
        }
        $world = $pos instanceof Position && $pos->isValid() ? $pos->getWorld() : $this->getWorld();
        falcon_entity_teleport($handle, $world->getHandle(), $pos->x, $pos->y, $pos->z);
        return true;
    }

    public function getMotion() : Vector3{
        [$x, $y, $z] = $this->info()["motion"] ?? [0.0, 0.0, 0.0];
        return new Vector3($x, $y, $z);
    }

    public function setMotion(Vector3 $motion) : bool{
        $handle = $this->getEntityHandle();
        if($handle === 0){
            return false;
        }
        falcon_entity_set_motion($handle, $motion->x, $motion->y, $motion->z);
        return true;
    }

    public function getHealth() : float{
        return $this->info()["health"] ?? 0.0;
    }

    public function setHealth(float $amount) : void{
        $handle = $this->getEntityHandle();
        if($handle !== 0){
            falcon_entity_set_health($handle, $amount);
        }
    }

    public function getMaxHealth() : int{
        return (int) ($this->info()["maxHealth"] ?? 0);
    }

    public function isAlive() : bool{
        return $this->info()["alive"] ?? false;
    }

    public function kill() : void{
        $handle = $this->getEntityHandle();
        if($handle !== 0){
            falcon_entity_kill($handle);
        }
    }

    public function getNameTag() : string{
        return $this->info()["nameTag"] ?? "";
    }

    public function setNameTag(string $name) : void{
        $handle = $this->getEntityHandle();
        if($handle !== 0){
            falcon_entity_set_name_tag($handle, $name);
        }
    }

    /**
     * Hurts the entity through Falcon, which fires its damage event: PocketMine-MP listeners see it there.
     */
    public function attack(EntityDamageEvent $source) : void{
        $handle = $this->getEntityHandle();
        if($handle === 0){
            return;
        }
        $attacker = $source instanceof EntityDamageByEntityEvent ? ($source->getDamager()?->getEntityHandle() ?? 0) : 0;
        $this->lastDamageCause = $source;
        falcon_entity_damage($handle, $source->getFinalDamage(), DamageCauses::toFalcon($source->getCause()), $attacker);
    }

    public function getLastDamageCause() : ?EntityDamageEvent{
        return $this->lastDamageCause;
    }

    public function setLastDamageCause(EntityDamageEvent $type) : void{
        $this->lastDamageCause = $type;
    }

    public function getScale() : float{
        return 1.0;
    }

    public function __toString() : string{
        return (new \ReflectionClass($this))->getShortName() . "(" . $this->runtimeId . ")";
    }
}
