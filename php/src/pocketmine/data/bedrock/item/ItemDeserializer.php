<?php

declare(strict_types=1);

namespace pocketmine\data\bedrock\item;

use pocketmine\item\Item;
use pocketmine\item\VanillaItems;

/** The game's saved items to items */
final class ItemDeserializer{
    /** @var \Closure[] what plugins mapped their item ids with */
    private array $deserializers = [];

    public function map(string $id, \Closure $deserializer) : void{
        $this->deserializers[$id] = $deserializer;
    }

    public function mapBlock(string $id, \Closure $deserializer) : void{
        $this->deserializers[$id] = $deserializer;
    }

    /**
     * @throws ItemTypeDeserializeException
     */
    public function deserializeType(SavedItemData $data) : Item{
        if(isset($this->deserializers[$data->getName()])){
            $made = ($this->deserializers[$data->getName()])($data);
            if($made instanceof \pocketmine\block\Block){
                $made = $made->asItem();
            }
            if($made instanceof Item){
                return $made;
            }
        }
        $item = VanillaItems::fromIdentifier($data->getName());
        if($item === null){
            throw new ItemTypeDeserializeException("No deserializer found for ID " . $data->getName());
        }
        if($data->getMeta() > 0){
            $item->setDamage($data->getMeta());
        }
        if($data->getTag() !== null){
            $item->setNamedTag($data->getTag());
        }
        return $item;
    }

    public function deserializeStack(SavedItemStackData $data) : Item{
        return $this->deserializeType($data->getTypeData())->setCount($data->getCount());
    }
}
