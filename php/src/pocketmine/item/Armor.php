<?php

declare(strict_types=1);

namespace pocketmine\item;

/** A piece of armour, worn in one of the four armour slots */
class Armor extends Durable{
    private ?ArmorTypeInfo $armorInfo = null;

    /**
     * the game's identifier, or PocketMine-MP's form for a plugin's own armour: an id, a name and its type
     * @param string[] $enchantmentTags
     */
    public function __construct(ItemIdentifier|string $identifier, string $name = "", ?ArmorTypeInfo $info = null, array $enchantmentTags = []){
        parent::__construct($identifier, $name, $enchantmentTags);
        $this->armorInfo = $info;
    }

    public function getArmorTypeInfo() : ?ArmorTypeInfo{
        return $this->armorInfo;
    }

    public function getMaterial() : ArmorMaterial{
        return $this->armorInfo?->getMaterial() ?? VanillaArmorMaterials::LEATHER();
    }

    public const TAG_CUSTOM_COLOR = "customColor";

    private function info() : array{
        return \pmmpcompat\ItemTypes::item($this->getFalconIdentifier()) ?? [];
    }

    public function getMaxDurability() : int{
        return $this->armorInfo?->getMaxDurability() ?? parent::getMaxDurability();
    }

    public function getDefensePoints() : int{
        if($this->armorInfo !== null){
            return $this->armorInfo->getDefensePoints();
        }
        return (int) ($this->info()["armorPoints"] ?? 0);
    }

    public function getToughness() : int{
        return $this->armorInfo?->getToughness() ?? (int) ($this->info()["armorToughness"] ?? 0);
    }

    /** one of ArmorInventory's SLOT_ numbers */
    public function getArmorSlot() : int{
        if($this->armorInfo !== null){
            return $this->armorInfo->getArmorSlot();
        }
        return max(0, (int) ($this->info()["armorSlot"] ?? 1) - 1);
    }

    public function getMaxStackSize() : int{
        return 1;
    }

    public function isFireProof() : bool{
        return $this->armorInfo?->isFireProof() ?? str_contains($this->getFalconIdentifier(), "netherite");
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
