<?php

declare(strict_types=1);

namespace pocketmine\item;

/** A piece of armour, worn in one of the four armour slots */
class Armor extends Durable{
    public const TAG_CUSTOM_COLOR = "customColor";

    private function info() : array{
        return \pmmpcompat\ItemTypes::item($this->getFalconIdentifier()) ?? [];
    }

    public function getDefensePoints() : int{
        return (int) ($this->info()["armorPoints"] ?? 0);
    }

    public function getToughness() : int{
        return (int) ($this->info()["armorToughness"] ?? 0);
    }

    /** one of ArmorInventory's SLOT_ numbers */
    public function getArmorSlot() : int{
        return max(0, (int) ($this->info()["armorSlot"] ?? 1) - 1);
    }

    public function getMaxStackSize() : int{
        return 1;
    }

    public function isFireProof() : bool{
        return str_contains($this->getFalconIdentifier(), "netherite");
    }

    public function getCustomColor() : ?\pocketmine\color\Color{
        $tag = $this->getNamedTag();
        return $tag->getTag(self::TAG_CUSTOM_COLOR) === null ? null : \pocketmine\color\Color::fromARGB(\pocketmine\utils\Binary::unsignInt($tag->getInt(self::TAG_CUSTOM_COLOR)));
    }

    public function setCustomColor(\pocketmine\color\Color $color) : self{
        $this->setNamedTag($this->getNamedTag()->setInt(self::TAG_CUSTOM_COLOR, \pocketmine\utils\Binary::signInt($color->toARGB())));
        return $this;
    }

    public function clearCustomColor() : self{
        $tag = $this->getNamedTag();
        $tag->removeTag(self::TAG_CUSTOM_COLOR);
        $this->setNamedTag($tag);
        return $this;
    }
}
