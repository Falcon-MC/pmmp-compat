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

    /** @var array<string, array{int, int}> the network ids of each item type, Falcon's own */
    private array $itemNetworkIds = [];

    /** an item as packets carry it, such as an entity's held item: Falcon gives the ids its clients know */
    public function coreItemStackToNet(\pocketmine\item\Item $item) : \pocketmine\network\mcpe\protocol\types\inventory\ItemStack{
        if($item->isNull()){
            return \pocketmine\network\mcpe\protocol\types\inventory\ItemStack::null();
        }
        $data = $item->toFalcon();
        // a block item's block depends on its state, kept in the damage
        $key = $data["id"] . ":" . $data["damage"];
        [$id, $block] = $this->itemNetworkIds[$key] ??= falcon_item_network_id(["id" => $data["id"], "count" => 1, "damage" => $data["damage"], "nbt" => ""]);
        if($id === 0){
            return \pocketmine\network\mcpe\protocol\types\inventory\ItemStack::null();
        }
        $nbt = $item->hasNamedTag() ? $item->getNamedTag() : null;
        $extra = new \pmmp\encoding\ByteBufferWriter();
        (new \pocketmine\network\mcpe\protocol\types\inventory\ItemStackExtraData($nbt, [], []))->write($extra);
        return new \pocketmine\network\mcpe\protocol\types\inventory\ItemStack($id, $data["damage"], $item->getCount(), $block, $extra->getData());
    }
}
