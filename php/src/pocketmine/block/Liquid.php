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

    public function getStillForm() : static{
        $identifier = str_replace('minecraft:flowing_', 'minecraft:', $this->getFalconIdentifier());
        $block = Block::fromFalcon($identifier, $this->getFalconStates());
        return $block instanceof static ? $block : clone $this;
    }

    public function getFlowingForm() : static{
        $identifier = 'minecraft:flowing_' . substr($this->getFalconIdentifier(), strrpos($this->getFalconIdentifier(), ':') + 1);
        $identifier = str_replace('flowing_flowing_', 'flowing_', $identifier);
        $block = Block::fromFalcon($identifier, $this->getFalconStates());
        return $block instanceof static ? $block : clone $this;
    }

    public function isStill() : bool{
        return !str_starts_with($this->getFalconIdentifier(), "minecraft:flowing_");
    }
}
