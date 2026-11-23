<?php

declare(strict_types=1);

namespace pocketmine\entity\object;

use pocketmine\data\bedrock\EffectIdMap;
use pocketmine\entity\effect\EffectCollection;
use pocketmine\entity\effect\EffectInstance;
use pocketmine\entity\Entity;

class AreaEffectCloud extends Entity{
    private int $age = 0;
    private int $maxAge = 600;
    private float $radius = 3.0;
    private float $radiusChangeOnUse = -0.5;
    private float $radiusChangePerTick = -0.005;
    private int $reapplicationDelay = 40;
    private ?EffectCollection $effects = null;

    public static function getNetworkTypeId() : string{
        return "minecraft:area_effect_cloud";
    }

    /** @internal Snapshot the native cloud when it is about to apply its effects. */
    public function recordApplication(array $data) : void{
        $this->age = $data['age'];
        $this->maxAge = $data['maxAge'];
        $this->radius = $data['radius'];
        $this->radiusChangeOnUse = $data['radiusChangeOnUse'];
        $this->radiusChangePerTick = $data['radiusChangePerTick'];
        $this->reapplicationDelay = $data['reapplicationDelay'];
        $this->effects = new EffectCollection();
        $effectIds = EffectIdMap::getInstance();
        foreach($data['effects'] as [$effectId, $duration, $amplifier]){
            $effect = $effectIds->fromId($effectId);
            if($effect !== null){
                $this->effects->add(new EffectInstance($effect, $duration, $amplifier));
            }
        }
    }

    public function getAge() : int{ return $this->age; }
    public function getMaxAge() : int{ return $this->maxAge; }
    public function getRadius() : float{ return $this->radius; }
    public function getRadiusChangeOnUse() : float{ return $this->radiusChangeOnUse; }
    public function getRadiusChangePerTick() : float{ return $this->radiusChangePerTick; }
    public function getReapplicationDelay() : int{ return $this->reapplicationDelay; }
    public function getEffects() : EffectCollection{ return $this->effects ??= new EffectCollection(); }
}
