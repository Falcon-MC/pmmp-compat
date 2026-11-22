<?php

declare(strict_types=1);

namespace pocketmine\block;

class SnowLayer extends Flowable{
    public const MIN_LAYERS = 1;
    public const MAX_LAYERS = 8;

    public function getLayers() : int{
        return ((int) ($this->getStates()["height"] ?? 0)) + 1;
    }

    /** @return $this */
    public function setLayers(int $layers) : self{
        if($layers < self::MIN_LAYERS || $layers > self::MAX_LAYERS){
            throw new \InvalidArgumentException("Layers must be in range " . self::MIN_LAYERS . " ... " . self::MAX_LAYERS);
        }
        $states = $this->getStates();
        $states["height"] = $layers - 1;
        $layered = Block::fromFalcon($this->getFalconIdentifier(), (string) json_encode($states));
        $pos = $this->getPosition();
        return $pos->isValid() ? $layered->withPosition($pos->getWorld(), $pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ()) : $layered;
    }

    public function canBeReplaced() : bool{
        return $this->getLayers() < self::MAX_LAYERS;
    }
}
