<?php

declare(strict_types=1);

namespace pocketmine\entity;

use pocketmine\event\player\PlayerExhaustEvent;

/**
 * A human's food, saturation and exhaustion, which Falcon keeps.
 *
 * Falcon runs hunger as the game does: eating, exhaustion from moving and the damage of starving. What a plugin sets
 * here is set in Falcon; exhaust() calls the event first.
 */
class HungerManager{
    private const SATURATION = "minecraft:player.saturation";
    private const EXHAUSTION = "minecraft:player.exhaustion";

    private bool $enabled = true;

    public function __construct(private Human $entity){
    }

    private function player() : int{
        return $this->entity instanceof \pocketmine\player\Player && $this->entity->isOnline() ? $this->entity->getHandle() : 0;
    }

    public function getFood() : float{
        $player = $this->player();
        return $player === 0 ? $this->getMaxFood() : falcon_player_food($player);
    }

    public function setFood(float $new) : void{
        $player = $this->player();
        if($player !== 0){
            falcon_player_set_food($player, max(0.0, min($this->getMaxFood(), $new)));
        }
    }

    public function getMaxFood() : float{
        return 20.0;
    }

    public function addFood(float $amount) : void{
        $this->setFood($this->getFood() + $amount);
    }

    public function isHungry() : bool{
        return $this->getFood() < $this->getMaxFood();
    }

    public function getSaturation() : float{
        return $this->value(self::SATURATION, 5.0);
    }

    public function setSaturation(float $saturation) : void{
        $this->set(self::SATURATION, max(0.0, min($this->getFood(), $saturation)));
    }

    public function addSaturation(float $amount) : void{
        $this->setSaturation($this->getSaturation() + $amount);
    }

    public function getExhaustion() : float{
        return $this->value(self::EXHAUSTION, 0.0);
    }

    public function setExhaustion(float $exhaustion) : void{
        $this->set(self::EXHAUSTION, max(0.0, $exhaustion));
    }

    /** @return float the exhaustion added, 0 when a listener cancelled it */
    public function exhaust(float $amount, int $cause = PlayerExhaustEvent::CAUSE_CUSTOM) : float{
        if(!$this->enabled){
            return 0.0;
        }
        $ev = new PlayerExhaustEvent($this->entity, $amount, $cause);
        $ev->call();
        if($ev->isCancelled()){
            return 0.0;
        }
        $this->setExhaustion($this->getExhaustion() + $ev->getAmount());
        return $ev->getAmount();
    }

    public function getFoodTickTimer() : int{
        return 0;
    }

    public function setFoodTickTimer(int $foodTickTimer) : void{
        if($foodTickTimer < 0){
            throw new \InvalidArgumentException("Expected a non-negative value");
        }
    }

    /** Falcon ticks hunger */
    public function tick(int $tickDiff = 1) : void{
    }

    public function isEnabled() : bool{
        return $this->enabled;
    }

    public function setEnabled(bool $enabled) : void{
        $this->enabled = $enabled;
    }

    private function value(string $attribute, float $default) : float{
        $handle = $this->entity->getEntityHandle();
        return $handle === 0 ? $default : (falcon_entity_attribute($handle, $attribute)[0] ?? $default);
    }

    private function set(string $attribute, float $value) : void{
        $handle = $this->entity->getEntityHandle();
        if($handle !== 0){
            falcon_entity_set_attribute($handle, $attribute, $value);
        }
    }
}
