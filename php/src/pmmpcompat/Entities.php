<?php

declare(strict_types=1);

namespace pmmpcompat;

use pmmpcompat\entity\GenericEntity;
use pmmpcompat\entity\GenericLiving;
use pocketmine\entity\Entity;

/**
 * One Entity object for each of Falcon's entities, by runtime id; a player's is their Player.
 */
final class Entities{
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
        return self::$byId[$id] = $info["maxHealth"] > 0 ? new GenericLiving($id) : new GenericEntity($id);
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
