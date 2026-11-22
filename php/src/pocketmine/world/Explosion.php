<?php

declare(strict_types=1);

namespace pocketmine\world;

use pocketmine\block\Block;
use pocketmine\entity\Entity;

/**
 * An explosion, as PocketMine-MP's: explodeA() then explodeB().
 *
 * Falcon makes it: the rays, the broken blocks, the drops, the damage and knockback are its. The blocks it breaks
 * reach plugins in EntityExplodeEvent or BlockExplodeEvent, called while Falcon explodes.
 */
class Explosion{
    public const DEFAULT_FIRE_CHANCE = 1.0 / 3.0;

    /** @var Block[] */
    public array $affectedBlocks = [];
    public float $stepLen = 0.3;
    public World $world;

    public function __construct(
        public Position $source,
        public float $radius,
        private Entity|Block|null $what = null,
        private float $fireChance = 0.0
    ){
        if(!$this->source->isValid()){
            throw new \InvalidArgumentException("Position does not have a valid world");
        }
        $this->world = $this->source->getWorld();
        if($radius <= 0){
            throw new \InvalidArgumentException("Explosion radius must be greater than 0, got $radius");
        }
    }

    /** Falcon works out the blocks when it explodes */
    public function explodeA() : bool{
        return true;
    }

    public function explodeB() : bool{
        $this->source->getWorld()->createExplosion($this->source, $this->radius, true);
        return true;
    }

    public function setFireChance(float $fireChance) : void{
        $this->fireChance = $fireChance;
    }
}
