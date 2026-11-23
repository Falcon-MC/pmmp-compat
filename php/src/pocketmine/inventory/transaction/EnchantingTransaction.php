<?php

declare(strict_types=1);

namespace pocketmine\inventory\transaction;

use pocketmine\item\enchantment\EnchantingOption;
use pocketmine\player\Player;

class EnchantingTransaction extends InventoryTransaction{
    public function __construct(
        Player $source,
        EnchantingOption $option,
        int $cost,
    ){
        parent::__construct($source);
    }
}
