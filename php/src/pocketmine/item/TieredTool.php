<?php

declare(strict_types=1);

namespace pocketmine\item;

/** A tool of a material: wood, stone, iron, gold, diamond, netherite */
abstract class TieredTool extends Tool{
    private ?ToolTier $tier = null;

    /**
     * the game's identifier, or PocketMine-MP's form for a plugin's own tool: an id, a name and its tier
     * @param string[] $enchantmentTags
     */
    public function __construct(ItemIdentifier|string $identifier, string $name = "", ?ToolTier $tier = null, array $enchantmentTags = []){
        parent::__construct($identifier, $name, $enchantmentTags);
        $this->tier = $tier;
    }

    public function getTier() : ToolTier{
        return $this->tier ?? \pmmpcompat\ItemTypes::tier((int) (\pmmpcompat\ItemTypes::item($this->getFalconIdentifier())["toolTier"] ?? 0)) ?? ToolTier::WOOD;
    }

    public function getBlockToolHarvestLevel() : int{
        return $this->getTier()->getHarvestLevel();
    }

    protected function getBaseMiningEfficiency() : float{
        return $this->getTier()->getBaseEfficiency();
    }

    public function getEnchantability() : int{
        return $this->getTier()->getEnchantability();
    }

    public function getFuelTime() : int{
        return $this->getTier() === ToolTier::WOOD ? 200 : 0;
    }

    public function isFireProof() : bool{
        return $this->getTier() === ToolTier::NETHERITE;
    }

    public function getAttackPoints() : int{
        return (int) (\pmmpcompat\ItemTypes::item($this->getFalconIdentifier())["attackDamage"] ?? 1);
    }
}
