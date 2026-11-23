<?php

declare(strict_types=1);

namespace pocketmine\inventory\transaction;

use pocketmine\crafting\CraftingManager;
use pocketmine\crafting\CraftingRecipe;
use pocketmine\player\Player;

class CraftingTransaction extends InventoryTransaction{
    public function __construct(
        Player $source,
        CraftingManager $craftingManager,
        array $actions = [],
        ?CraftingRecipe $recipe = null,
        ?int $repetitions = null
    ){
        parent::__construct($source, $actions);
    }
}
