<?php

declare(strict_types=1);

namespace pocketmine\block\tile;

use pocketmine\inventory\Inventory;
use pmmpcompat\inventory\FalconBlockInventory;

/** the items Falcon keeps in the block, and a lock kept on the tile object */
trait ContainerTrait{
    private ?string $lock = null;
    private ?Inventory $inventory = null;

    public function getInventory() : Inventory{
        return $this->inventory ??= FalconBlockInventory::at($this->getPosition());
    }

    public function getRealInventory() : Inventory{
        return $this->getInventory();
    }

    public function canOpenWith(string $key) : bool{
        return $this->lock === null || $this->lock === $key;
    }

    public function getLock() : ?string{
        return $this->lock;
    }

    public function setLock(?string $lock) : void{
        $this->lock = $lock;
    }

    /** Falcon drops a broken container's items itself */
    protected function onBlockDestroyedHook() : void{
    }
}
