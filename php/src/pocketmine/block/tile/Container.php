<?php

declare(strict_types=1);

namespace pocketmine\block\tile;

use pocketmine\inventory\Inventory;
use pocketmine\inventory\InventoryHolder;

interface Container extends InventoryHolder{
    public const TAG_ITEMS = "Items";
    public const TAG_LOCK = "Lock";

    public function getRealInventory() : Inventory;

    public function canOpenWith(string $key) : bool;
}
