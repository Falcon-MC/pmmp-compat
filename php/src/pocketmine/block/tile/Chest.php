<?php

declare(strict_types=1);

namespace pocketmine\block\tile;

use pocketmine\block\inventory\ChestInventory;
use pocketmine\inventory\Inventory;
use pocketmine\math\Facing;
use pmmpcompat\inventory\FalconBlockInventory;

/** A chest; Falcon pairs chests next to each other and keeps their items */
class Chest extends Spawnable implements Container, Nameable{
    use NameableTrait;
    use ContainerTrait{
        getInventory as private containerInventory;
    }

    public const TAG_PAIRX = "pairx";
    public const TAG_PAIRZ = "pairz";
    public const TAG_PAIR_LEAD = "pairlead";

    public function getDefaultName() : string{
        return "Chest";
    }

    /** @return ChestInventory */
    public function getInventory() : Inventory{
        return $this->containerInventory();
    }

    public function getRealInventory() : Inventory{
        return $this->getInventory();
    }

    /** a chest of the same kind beside it, which Falcon shows as one double chest */
    public function getPair() : ?Chest{
        $world = $this->position->getWorld();
        $name = $this->getBlock()->getFalconIdentifier();
        foreach([Facing::NORTH, Facing::SOUTH, Facing::EAST, Facing::WEST] as $side){
            $pos = $this->position->getSide($side);
            if($world->getBlock($pos)->getFalconIdentifier() === $name){
                $tile = $world->getTile($pos);
                if($tile instanceof Chest){
                    return $tile;
                }
            }
        }
        return null;
    }

    public function isPaired() : bool{
        return $this->getPair() !== null;
    }

    public function pairWith(Chest $tile) : bool{
        return false;
    }

    public function unpair() : bool{
        return false;
    }
}
