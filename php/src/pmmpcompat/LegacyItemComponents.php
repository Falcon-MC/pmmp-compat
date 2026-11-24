<?php

declare(strict_types=1);

namespace pmmpcompat;

use pocketmine\data\bedrock\item\SavedItemData;
use pocketmine\item\Item;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\tag\Tag;
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\network\mcpe\protocol\types\ItemComponentPacketEntry;
use pocketmine\network\mcpe\protocol\types\ItemTypeEntry;
use pocketmine\world\format\io\GlobalItemDataHandlers;

/** Converts the older packet's component NBT into behavior-pack definitions. */
final class LegacyItemComponents{
    private const CATEGORIES = [1 => "construction", 2 => "nature", 3 => "equipment", 4 => "items"];

    /** @param ItemComponentPacketEntry[] $entries */
    public static function register(array $entries) : void{
        $definitions = [];
        $types = [];
        foreach($entries as $entry){
            if(isset($definitions[$entry->name])){
                throw new \InvalidArgumentException("Duplicate components for item {$entry->name}");
            }
            [$item, $json, $category] = self::prepare($entry);
            $typeId = $item->getTypeId();
            if(isset($types[$typeId])){
                throw new \InvalidArgumentException("Multiple identifiers for custom item type $typeId");
            }
            $types[$typeId] = true;
            $definitions[$entry->name] = [$entry, $item, $json, $category];
        }

        foreach($definitions as [$entry, $item, $json, $category]){
            ItemTypes::registerComponentItem($entry->name, $item, $json, $category);
            [$networkId] = falcon_item_network_id($item->toFalcon());
            if($networkId === 0){
                throw new \RuntimeException("No native network ID for item {$entry->name}");
            }
            TypeConverter::getInstance()->getItemTypeDictionary()->setEntry(
                new ItemTypeEntry($entry->name, $networkId, true, 2, $entry->componentNbt)
            );
        }
    }

    /** @return array{Item, string, string} */
    private static function prepare(ItemComponentPacketEntry $entry) : array{
        $root = $entry->componentNbt->getRoot();
        $components = $root instanceof CompoundTag ? $root->getCompoundTag("components") : null;
        if($components === null){
            throw new \InvalidArgumentException("Missing components for item {$entry->name}");
        }

        $item = GlobalItemDataHandlers::getDeserializer()->deserializeType(new SavedItemData($entry->name));
        $definition = [];
        foreach($components as $key => $tag){
            if(str_contains($key, ":")){
                $definition[$key] = self::value($tag);
            }
        }

        $properties = $components->getCompoundTag("item_properties");
        $category = self::CATEGORIES[$properties?->getInt("creative_category", 0) ?? 0] ?? "";
        if($properties !== null){
            $icon = $properties->getCompoundTag("minecraft:icon")?->getString("texture", "") ?? "";
            if($icon !== ""){
                $definition["minecraft:icon"] = $icon;
            }
            $definition["minecraft:max_stack_size"] = $properties->getInt("max_stack_size", $item->getMaxStackSize());
            $definition["minecraft:allow_off_hand"] = $properties->getByte("allow_off_hand", 0) !== 0;
            $definition["minecraft:can_destroy_in_creative"] = $properties->getByte("can_destroy_in_creative", 0) !== 0;
            $definition["minecraft:hand_equipped"] = $properties->getByte("hand_equipped", 0) !== 0;
            $definition["minecraft:glint"] = $properties->getByte("foil", 0) !== 0;
            $duration = $properties->getInt("use_duration", 0);
            if($duration > 0){
                $definition["minecraft:use_modifiers"] ??= (object)["use_duration" => $duration / 20];
            }
        }
        if(($definition["minecraft:food"] ?? null) instanceof \stdClass){
            $food = $definition["minecraft:food"];
            $food->can_always_eat = (bool) ($food->can_always_eat ?? false);
        }
        if(($definition["minecraft:cooldown"] ?? null) instanceof \stdClass){
            $cooldown = $definition["minecraft:cooldown"];
            $cooldown->duration ??= $cooldown->value ?? 0;
        }
        if(($definition["minecraft:digger"] ?? null) instanceof \stdClass){
            $digger = $definition["minecraft:digger"];
            $digger->use_efficiency = (bool) ($digger->use_efficiency ?? false);
        }
        $attack = $item->getAttackPoints();
        if($attack > 0){
            $definition["minecraft:damage"] ??= $attack;
        }

        return [$item, json_encode((object) $definition, JSON_THROW_ON_ERROR), $category];
    }

    private static function value(Tag $tag) : mixed{
        if($tag instanceof CompoundTag){
            $compound = new \stdClass();
            foreach($tag as $name => $value){
                $compound->{$name} = self::value($value);
            }
            return $compound;
        }
        if($tag instanceof ListTag){
            return array_map(self::value(...), $tag->getValue());
        }
        return $tag->getValue();
    }
}
