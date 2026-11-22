<?php

declare(strict_types=1);

namespace pocketmine\item;

/** A tool: it mines its blocks faster */
abstract class Tool extends Durable{
    public function getMaxStackSize() : int{
        return 1;
    }

    public function getMiningEfficiency(bool $isCorrectTool) : float{
        $efficiency = 1;
        if($isCorrectTool){
            $efficiency = $this->getBaseMiningEfficiency();
            $level = $this->getEnchantmentLevel(\pocketmine\item\enchantment\VanillaEnchantments::EFFICIENCY());
            if($level > 0){
                $efficiency += ($level ** 2 + 1);
            }
        }
        return $efficiency;
    }

    protected function getBaseMiningEfficiency() : float{
        return 1;
    }
}
