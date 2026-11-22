<?php

declare(strict_types=1);

namespace pocketmine\item;

/** An item that wears as it is used, and breaks once worn out */
class Durable extends Item{
    public const TAG_UNBREAKABLE = "Unbreakable";

    protected bool $unbreakable = false;

    public function isUnbreakable() : bool{
        return $this->getNamedTag()->getByte(self::TAG_UNBREAKABLE, 0) !== 0;
    }

    public function setUnbreakable(bool $value = true) : self{
        $tag = $this->getNamedTag();
        $value ? $tag->setByte(self::TAG_UNBREAKABLE, 1) : $tag->removeTag(self::TAG_UNBREAKABLE);
        $this->setNamedTag($tag);
        return $this;
    }

    /** wears the item by that much, Unbreaking giving its chance not to; false for an unbreakable one */
    public function applyDamage(int $amount) : bool{
        if($this->isUnbreakable() || $this->isBroken()){
            return false;
        }
        $level = $this->getEnchantmentLevel(\pocketmine\item\enchantment\VanillaEnchantments::UNBREAKING());
        if($level > 0){
            $kept = 0;
            for($i = 0; $i < $amount; ++$i){
                if(mt_rand(1, $level + 1) !== 1){
                    ++$kept;
                }
            }
            $amount -= $kept;
        }
        $this->damage = min($this->damage + max(0, $amount), $this->getMaxDurability());
        if($this->isBroken()){
            $this->onBroken();
        }
        return true;
    }

    public function setDamage(int $damage) : Item{
        if($damage < 0 || $damage > $this->getMaxDurability()){
            throw new \InvalidArgumentException("Damage must be in range 0 - " . $this->getMaxDurability());
        }
        return parent::setDamage($damage);
    }

    protected function onBroken() : void{
        $this->pop();
    }

    public function getMaxDurability() : int{
        return (int) (\pmmpcompat\ItemTypes::item($this->getFalconIdentifier())["maxDurability"] ?? 0);
    }

    public function isBroken() : bool{
        return $this->damage >= $this->getMaxDurability() || $this->isNull();
    }
}
