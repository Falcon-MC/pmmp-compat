<?php

declare(strict_types=1);

namespace pocketmine\data\bedrock\item;

use pocketmine\item\Item;
use pocketmine\item\ItemBlock;
use pocketmine\world\format\io\GlobalBlockStateHandlers;

/** Items to the game's saved form */
final class ItemSerializer{
    /** @var \Closure[] what plugins mapped their items with, by type id */
    private array $serializers = [];
    /** @var \Closure[] by block type id */
    private array $blockItemSerializers = [];

    public function map(\pocketmine\item\Item $item, \Closure $serializer) : void{
        if(str_starts_with($item->getFalconIdentifier(), "pmmpcompat:item_")){
            $data = $serializer(clone $item);
            if($data instanceof SavedItemData && preg_match('~^(?!minecraft:)[a-z0-9_.-]+:[a-z0-9_./-]+$~D', $data->getName()) === 1){
                \pmmpcompat\ItemTypes::registerCustom($data->getName(), $item);
            }
        }
        $this->serializers[$item->getTypeId()] = $serializer;
    }

    public function mapBlock(\pocketmine\block\Block $block, \Closure $serializer) : void{
        $this->blockItemSerializers[$block->getTypeId()] = $serializer;
    }

    public function serializeType(Item $item) : SavedItemData{
        if($item->isNull()){
            throw new ItemTypeSerializeException("Cannot serialize a null itemstack");
        }
        if(isset($this->serializers[$item->getTypeId()])){
            $data = ($this->serializers[$item->getTypeId()])($item);
            if($data instanceof SavedItemData){
                return $data;
            }
        }
        $block = null;
        if($item instanceof ItemBlock){
            $blockType = $item->getBlock();
            $serializer = $this->blockItemSerializers[$blockType->getTypeId()] ?? null;
            if($serializer !== null){
                $data = $serializer($blockType);
                if($data instanceof SavedItemData){
                    return $data;
                }
            }
            $block = GlobalBlockStateHandlers::getSerializer()->serializeBlock($blockType);
        }
        $tag = $item->getNamedTag();
        return new SavedItemData($item->getFalconIdentifier(), $item->getDamage(), $block, $tag->count() > 0 ? $tag : null);
    }

    public function serializeStack(Item $item, ?int $slot = null) : SavedItemStackData{
        return new SavedItemStackData($this->serializeType($item), $item->getCount(), $slot, null, [], []);
    }
}
