<?php

declare(strict_types=1);

namespace pocketmine\entity\effect;

use pocketmine\data\bedrock\EffectIdMap;
use pocketmine\entity\Living;
use pocketmine\event\entity\EntityEffectAddEvent;
use pocketmine\event\entity\EntityEffectRemoveEvent;
use function spl_object_id;

/**
 * The effects on a living entity, which Falcon keeps and runs.
 *
 * Falcon ticks them, applies what they do and sends them to players; this reads Falcon's list each time, so an
 * effect a potion or a command gave shows here. The add and remove events are called before Falcon is asked.
 */
class EffectManager extends EffectCollection{
    /** @internal set while this asks Falcon for an effect, so Falcon's own add event is not called twice */
    public static bool $applying = false;

    public function __construct(
        private Living $entity
    ){
        parent::__construct();
    }

    /** Falcon's list, the instances kept when their effect is still there */
    private function sync() : void{
        $handle = $this->entity->getEntityHandle();
        if($handle === 0){
            return;
        }
        $seen = [];
        foreach(falcon_entity_effects($handle) as [$id, $duration, $amplifier, $particles, $ambient]){
            $type = EffectIdMap::getInstance()->fromId($id);
            if($type === null){
                continue;
            }
            $index = spl_object_id($type);
            $seen[$index] = true;
            $infinite = $duration < 0;
            $instance = $this->effects[$index] ?? null;
            if($instance === null || $instance->isInfinite() !== $infinite){
                $instance = new EffectInstance($type, $infinite ? 0 : $duration, $amplifier, $particles, $ambient, null, $infinite);
                $this->effects[$index] = $instance;
            }else{
                if(!$infinite){
                    $instance->setDuration($duration);
                }
                $instance->setAmplifier($amplifier)->setVisible($particles)->setAmbient($ambient);
            }
        }
        foreach($this->effects as $index => $instance){
            if(!isset($seen[$index])){
                unset($this->effects[$index]);
            }
        }
        $this->recalculateEffectColor();
    }

    public function all() : array{
        $this->sync();
        return $this->effects;
    }

    public function get(Effect $effect) : ?EffectInstance{
        $this->sync();
        return parent::get($effect);
    }

    public function has(Effect $effect) : bool{
        $this->sync();
        return parent::has($effect);
    }

    public function clear() : void{
        foreach($this->all() as $effect){
            $this->remove($effect->getType());
        }
    }

    public function remove(Effect $effectType) : void{
        $effect = $this->get($effectType);
        if($effect === null){
            return;
        }
        $ev = new EntityEffectRemoveEvent($this->entity, $effect);
        $ev->call();
        if($ev->isCancelled()){
            return;
        }
        $id = EffectIdMap::getInstance()->toId($effectType);
        $handle = $this->entity->getEntityHandle();
        if($handle !== 0){
            falcon_entity_remove_effect($handle, $id);
        }
        parent::remove($effectType);
    }

    public function add(EffectInstance $effect) : bool{
        $old = $this->get($effect->getType());
        $ev = new EntityEffectAddEvent($this->entity, $effect, $old);
        if(!$this->canAdd($effect)){
            $ev->cancel();
        }
        $ev->call();
        if($ev->isCancelled()){
            return false;
        }
        $handle = $this->entity->getEntityHandle();
        if($handle === 0){
            return false;
        }
        self::$applying = true;
        try{
            $added = falcon_entity_add_effect($handle, EffectIdMap::getInstance()->toId($effect->getType()),
                $effect->isInfinite() ? -1 : $effect->getDuration(), $effect->getAmplifier(), $effect->isVisible());
        }finally{
            self::$applying = false;
        }
        if(!$added){
            return false;
        }
        return parent::add($effect);
    }

    /** Falcon ticks the effects; this only tells whether any is left */
    public function tick(int $tickDiff = 1) : bool{
        return count($this->all()) > 0;
    }
}
