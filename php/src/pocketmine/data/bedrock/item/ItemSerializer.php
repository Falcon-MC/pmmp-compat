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

    public function map(\pocketmine\item\Item $item, \Closure $serializer) : void{
        $this->serializers[$item->getTypeId()] = $serializer;
    }

    public function mapBlock(\pocketmine\block\Block $block, \Closure $serializer) : void{
        //NOOP: a block's item saves as the block does
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
        $block = $item instanceof ItemBlock ? GlobalBlockStateHandlers::getSerializer()->serializeBlock($item->getBlock()) : null;
        $tag = $item->getNamedTag();
        return new SavedItemData($item->getFalconIdentifier(), $item->getDamage(), $block, $tag->count() > 0 ? $tag : null);
    }

    public function serializeStack(Item $item, ?int $slot = null) : SavedItemStackData{
        return new SavedItemStackData($this->serializeType($item), $item->getCount(), $slot, null, [], []);
    }
}
