<?php

declare(strict_types=1);

namespace pocketmine\network\mcpe\convert;

use pocketmine\utils\SingletonTrait;

/** Server values to the ones the protocol carries; the block ids are what plugins reach it for */
final class TypeConverter{
    use SingletonTrait;

    private BlockTranslator $blockTranslator;

    public function __construct(){
        $this->blockTranslator = new BlockTranslator();
    }

    public function getBlockTranslator() : BlockTranslator{
        return $this->blockTranslator;
    }

    private ?SkinAdapter $skinAdapter = null;

    /** skins to what the protocol carries and back, PocketMine-MP's own adapter unless a plugin sets one */
    public function getSkinAdapter() : SkinAdapter{
        return $this->skinAdapter ??= new LegacySkinAdapter();
    }

    public function setSkinAdapter(SkinAdapter $adapter) : void{
        $this->skinAdapter = $adapter;
    }
}
