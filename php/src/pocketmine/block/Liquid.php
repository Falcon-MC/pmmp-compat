<?php

declare(strict_types=1);

namespace pocketmine\block;

/** Water or lava, still or flowing; Falcon makes it flow */
abstract class Liquid extends Transparent{
    public const MAX_DECAY = 7;

    public function canBeReplaced() : bool{
        return true;
    }

    public function isSolid() : bool{
        return false;
    }

    public function isSource() : bool{
        return (int) ($this->getStates()["liquid_depth"] ?? 0) === 0;
    }

    public function getDecay() : int{
        return ((int) ($this->getStates()["liquid_depth"] ?? 0)) & 0x7;
    }

    public function isFalling() : bool{
        return (((int) ($this->getStates()["liquid_depth"] ?? 0)) & 0x8) !== 0;
    }

    public function setDecay(int $decay) : self{
        if($decay < 0 || $decay > self::MAX_DECAY){
            throw new \InvalidArgumentException('Invalid liquid decay ' . $decay);
        }
        $this->setState('liquid_depth', $decay | ($this->isFalling() ? 8 : 0));
        return $this;
    }

    public function setFalling(bool $falling) : self{
        $this->setState('liquid_depth', $this->getDecay() | ($falling ? 8 : 0));
        return $this;
    }

    public function setStill(bool $still = true) : self{
        $identifier = $this->getFalconIdentifier();
        $base = str_replace('minecraft:flowing_', 'minecraft:', $identifier);
        $this->setFalconIdentifier($still ? $base : str_replace('minecraft:', 'minecraft:flowing_', $base));
        return $this;
    }

    public function getStillForm() : static{
        return (clone $this)->setStill();
    }

    public function getFlowingForm() : static{
        return (clone $this)->setStill(false);
    }

    public function isStill() : bool{
        return !str_starts_with($this->getFalconIdentifier(), "minecraft:flowing_");
    }
}
