<?php

declare(strict_types=1);

namespace pmmpcompat;

use pmmpcompat\entity\GenericEntity;
use pmmpcompat\entity\GenericLiving;
use pmmpcompat\entity\FalconProjectile;
use pocketmine\entity\Entity;
use pocketmine\entity\object as objects;
use pocketmine\entity\projectile as projectiles;
use pocketmine\entity\Squid;
use pocketmine\entity\Villager;
use pocketmine\entity\Zombie;

/**
 * One Entity object for each of Falcon's entities, by runtime id; a player's is their Player.
 */
final class Entities{
    /** the PocketMine-MP class of each Falcon entity type that has one */
    private const CLASSES = [
        "minecraft:item" => objects\ItemEntity::class,
        "minecraft:xp_orb" => objects\ExperienceOrb::class,
        "minecraft:falling_block" => objects\FallingBlock::class,
        "minecraft:tnt" => objects\PrimedTNT::class,
        "minecraft:painting" => objects\Painting::class,
        "minecraft:arrow" => projectiles\Arrow::class,
        "minecraft:snowball" => projectiles\Snowball::class,
        "minecraft:egg" => projectiles\Egg::class,
        "minecraft:ender_pearl" => projectiles\EnderPearl::class,
        "minecraft:xp_bottle" => projectiles\ExperienceBottle::class,
        "minecraft:splash_potion" => projectiles\SplashPotion::class,
        "minecraft:lingering_potion" => projectiles\SplashPotion::class,
        "minecraft:thrown_trident" => projectiles\Trident::class,
        "minecraft:fireball" => FalconProjectile::class,
        "minecraft:small_fireball" => FalconProjectile::class,
        "minecraft:dragon_fireball" => FalconProjectile::class,
        "minecraft:wither_skull" => FalconProjectile::class,
        "minecraft:wither_skull_dangerous" => FalconProjectile::class,
        "minecraft:shulker_bullet" => FalconProjectile::class,
        "minecraft:llama_spit" => FalconProjectile::class,
        "minecraft:wind_charge_projectile" => FalconProjectile::class,
        "minecraft:breeze_wind_charge_projectile" => FalconProjectile::class,
        "minecraft:fishing_hook" => FalconProjectile::class,
        "minecraft:zombie" => Zombie::class,
        "minecraft:villager" => Villager::class,
        "minecraft:villager_v2" => Villager::class,
        "minecraft:squid" => Squid::class,
    ];

    /** the identifier a PocketMine-MP entity class stands for, "" for the classes several types share */
    public static function networkTypeOf(string $class) : string{
        static $types = null;
        if($types === null){
            $types = [];
            foreach(self::CLASSES as $identifier => $entityClass){
                $types[$entityClass] ??= $identifier;
            }
            $types[FalconProjectile::class] = "";
        }
        return $types[$class] ?? "";
    }

    /** @var Entity[] by runtime id */
    private static array $byId = [];

    private function __construct(){
    }

    /** the object for a Falcon entity handle */
    public static function get(int $handle) : Entity{
        $player = falcon_entity_info($handle)["player"];
        if($player !== 0){
            return Players::get($player);
        }
        $id = falcon_entity_runtime_id($handle);
        $known = self::$byId[$id] ?? null;
        if($known !== null && !$known->isClosed()){
            return $known;
        }
        $info = falcon_entity_info($handle);
        $class = self::CLASSES[$info["type"]] ?? ($info["maxHealth"] > 0 ? GenericLiving::class : GenericEntity::class);
        return self::$byId[$id] = new $class($id);
    }

    public static function byId(int $runtimeId) : ?Entity{
        $handle = falcon_server_entity($runtimeId);
        return $handle === 0 ? null : self::get($handle);
    }

    /** objects for entities that are gone, dropped now and then */
    public static function forgetClosed() : void{
        foreach(self::$byId as $id => $entity){
            if($entity->isClosed()){
                unset(self::$byId[$id]);
            }
        }
    }
}
