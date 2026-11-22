<?php

declare(strict_types=1);

namespace pocketmine\block\tile;

use pocketmine\item\Item;

/** a custom name kept on the tile object */
trait NameableTrait{
    private ?string $customName = null;

    abstract public function getDefaultName() : string;

    public function getName() : string{
        return $this->customName ?? $this->getDefaultName();
    }

    public function setName(string $name) : void{
        $this->customName = $name === "" ? null : $name;
    }

    public function hasName() : bool{
        return $this->customName !== null;
    }

    public function copyDataFromItem(Item $item) : void{
        parent::copyDataFromItem($item);
        if($item->hasCustomName()){
            $this->setName($item->getCustomName());
        }
    }
}
