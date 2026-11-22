<?php

declare(strict_types=1);

namespace pocketmine\item;

use pocketmine\block\Block;
use pocketmine\block\VanillaBlocks;
use pocketmine\data\bedrock\EnchantmentIdMap;
use pocketmine\item\enchantment\Enchantment;
use pocketmine\item\enchantment\EnchantmentInstance;
use pocketmine\nbt\LittleEndianNbtSerializer;
use pocketmine\nbt\NbtDataException;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\nbt\TreeRoot;

/**
 * An item stack: a type, a count, a name, lore and its NBT, as PocketMine-MP's.
 *
 * The type is the game's identifier, minecraft:diamond_sword; getTypeId() is a number made from it, the same from one
 * run to the next. Items are values: changing one changes no inventory until it is set back.
 */
class Item implements \JsonSerializable{
    public const TAG_DISPLAY = "display";
    public const TAG_DISPLAY_NAME = "Name";
    public const TAG_DISPLAY_LORE = "Lore";
    public const TAG_ENCH = "ench";

    protected int $count = 1;
    protected string $customName = "";
    /** @var string[] */
    protected array $lore = [];
    protected int $damage = 0;
    protected CompoundTag $nbt;
    /** @var EnchantmentInstance[] by enchantment object id */
    protected array $enchantments = [];

    private string $identifier;
    private ?ItemIdentifier $identifierObject = null;

    /**
     * @param ItemIdentifier|string $identifier the game's identifier, or the type id a plugin's own item class gives
     * @param string[]              $enchantmentTags
     */
    public function __construct(
        ItemIdentifier|string $identifier,
        protected string $name = "",
        private array $enchantmentTags = []
    ){
        if($identifier instanceof ItemIdentifier){
            $this->identifierObject = $identifier;
            // an item of the plugin's own: a type of its own, which no Falcon item stands for
            $identifier = "pmmpcompat:item_" . $identifier->getTypeId();
        }
        $this->identifier = $identifier;
        if($this->name === ""){
            $this->name = self::nameOf($identifier);
        }
        $this->nbt = new CompoundTag();
    }

    public function getIdentifier() : ItemIdentifier{
        return $this->identifierObject ??= new ItemIdentifier($this->getTypeId());
    }

    /** @return string[] */
    public function getEnchantmentTags() : array{
        return $this->enchantmentTags;
    }

    public const TAG_NAME = "Name";
    public const TAG_COUNT = "Count";
    public const TAG_DAMAGE = "Damage";
    public const TAG_TAG = "tag";
    public const TAG_SLOT = "Slot";

    /**
     * The item as PocketMine-MP saves it: Name, Count, Damage and tag, and Slot when one is given.
     */
    public function nbtSerialize(int $slot = -1) : CompoundTag{
        $result = CompoundTag::create()
            ->setString(self::TAG_NAME, $this->identifier)
            ->setByte(self::TAG_COUNT, \pocketmine\utils\Binary::signByte($this->count))
            ->setShort(self::TAG_DAMAGE, $this->damage);
        $tag = $this->getNamedTag();
        if($tag->count() > 0){
            $result->setTag(self::TAG_TAG, $tag);
        }
        if($slot !== -1){
            $result->setByte(self::TAG_SLOT, $slot);
        }
        return $result;
    }

    /**
     * An item PocketMine-MP saved; one it cannot know, such as an old numeric id, reads as air.
     */
    public static function nbtDeserialize(CompoundTag $tag) : Item{
        $name = $tag->getTag(self::TAG_NAME);
        $identifier = $name instanceof \pocketmine\nbt\tag\StringTag ? $name->getValue() : "";
        $item = VanillaItems::fromIdentifier($identifier) ?? ($identifier !== "" && str_starts_with($identifier, "pmmpcompat:")
            ? new Item($identifier) : VanillaItems::AIR());
        $item->setCount(\pocketmine\utils\Binary::unsignByte($tag->getByte(self::TAG_COUNT, 1)));
        $item->damage = max(0, $tag->getShort(self::TAG_DAMAGE, 0));
        $named = $tag->getCompoundTag(self::TAG_TAG);
        if($named !== null){
            $item->setNamedTag($named);
        }
        return $item;
    }

    /** "minecraft:diamond_sword" reads "Diamond Sword" */
    public static function nameOf(string $identifier) : string{
        $path = str_contains($identifier, ":") ? substr($identifier, strpos($identifier, ":") + 1) : $identifier;
        return ucwords(str_replace("_", " ", $path));
    }

    /**
     * @internal an item as the native side gives it: identifier, count, damage, little-endian NBT
     * @param array{id: string, count: int, damage: int, nbt: string} $data
     */
    public static function fromFalcon(array $data) : Item{
        $item = \pmmpcompat\ItemTypes::create($data["id"]);
        $item->count = $data["count"];
        $item->damage = $data["damage"];
        if($data["nbt"] !== ""){
            try{
                $tag = (new LittleEndianNbtSerializer())->read($data["nbt"])->getTag();
                if($tag instanceof CompoundTag){
                    $item->setNamedTag($tag);
                }
            }catch(NbtDataException){
                // an unreadable tag stays Falcon's: the item keeps no NBT here
            }
        }
        return $item;
    }

    /**
     * @internal what the native side takes back
     * @return array{id: string, count: int, damage: int, nbt: string}
     */
    public function toFalcon() : array{
        $tag = $this->getNamedTag();
        return [
            "id" => $this->identifier,
            "count" => $this->count,
            "damage" => $this->damage,
            "nbt" => $tag->count() === 0 ? "" : (new LittleEndianNbtSerializer())->write(new TreeRoot($tag)),
        ];
    }

    /** @internal the game's identifier */
    public function getFalconIdentifier() : string{
        return $this->identifier;
    }

    public function getTypeId() : int{
        return $this->identifierObject?->getTypeId() ?? crc32($this->identifier);
    }

    public function getStateId() : int{
        return crc32($this->identifier . ":" . $this->damage);
    }

    public function getVanillaName() : string{
        return $this->name;
    }

    public function getName() : string{
        return $this->hasCustomName() ? $this->customName : $this->name;
    }

    public function hasCustomName() : bool{
        return $this->customName !== "";
    }

    public function getCustomName() : string{
        return $this->customName;
    }

    /** @return $this */
    public function setCustomName(string $name) : Item{
        $this->customName = $name;
        return $this;
    }

    /** @return $this */
    public function clearCustomName() : Item{
        return $this->setCustomName("");
    }

    /** @return string[] */
    public function getLore() : array{
        return $this->lore;
    }

    /**
     * @param string[] $lines
     * @return $this
     */
    public function setLore(array $lines) : Item{
        foreach($lines as $line){
            if(!is_string($line)){
                throw new \TypeError("Expected string[], but found " . gettype($line) . " in given array");
            }
        }
        $this->lore = array_values($lines);
        return $this;
    }

    public function getCount() : int{
        return $this->count;
    }

    /** @return $this */
    public function setCount(int $count) : Item{
        $this->count = $count;
        return $this;
    }

    /** takes some off the stack, and gives an item of that many */
    public function pop(int $count = 1) : Item{
        if($count > $this->count){
            throw new \InvalidArgumentException("Cannot pop $count items from a stack of $this->count");
        }
        $item = clone $this;
        $item->count = $count;
        $this->count -= $count;
        return $item;
    }

    public function isNull() : bool{
        return $this->count <= 0 || $this->identifier === "minecraft:air";
    }

    public function getMaxStackSize() : int{
        return (int) (\pmmpcompat\ItemTypes::item($this->identifier)["maxStackSize"]
            ?? \pmmpcompat\ItemTypes::block($this->identifier)["maxStackSize"] ?? 64);
    }

    /** the damage a hit with it deals, 1 for an item that is not a weapon */
    public function getAttackPoints() : int{
        return 1;
    }

    /** the armour points it gives worn, 0 for an item that is not armour */
    public function getDefensePoints() : int{
        return 0;
    }

    /** one of BlockToolType's bits: the blocks it mines faster */
    public function getBlockToolType() : int{
        return \pocketmine\block\BlockToolType::NONE;
    }

    public function getBlockToolHarvestLevel() : int{
        return 0;
    }

    public function getMiningEfficiency(bool $isCorrectTool) : float{
        return 1;
    }

    /** ticks it burns for in a furnace, 0 for an item that is not fuel */
    public function getFuelTime() : int{
        return 0;
    }

    public function getFuelResidue() : Item{
        $item = clone $this;
        $item->pop();
        return $item;
    }

    public function isFireProof() : bool{
        return str_contains($this->identifier, "netherite");
    }

    public function getEnchantability() : int{
        return 1;
    }

    public function getCooldownTicks() : int{
        return 0;
    }

    public function getCooldownTag() : ?string{
        return null;
    }

    /** @param Item[] &$returnedItems */
    public function onAttackEntity(\pocketmine\entity\Entity $victim, array &$returnedItems) : bool{
        return false;
    }

    /** @param Item[] &$returnedItems */
    public function onDestroyBlock(Block $block, array &$returnedItems) : bool{
        return false;
    }

    public function keepOnDeath() : bool{
        return false;
    }

    /** the damage a tool or armour has taken; 0 for items that take none */
    public function getDamage() : int{
        return $this->damage;
    }

    /** @return $this */
    public function setDamage(int $damage) : Item{
        if($damage < 0){
            throw new \InvalidArgumentException("Damage must be positive");
        }
        $this->damage = $damage;
        return $this;
    }

    public function hasNamedTag() : bool{
        return $this->getNamedTag()->count() > 0;
    }

    /** the NBT, the name and lore written into its display tag */
    public function getNamedTag() : CompoundTag{
        $tag = clone $this->nbt;
        $display = $tag->getCompoundTag(self::TAG_DISPLAY) ?? new CompoundTag();
        if($this->customName !== ""){
            $display->setString(self::TAG_DISPLAY_NAME, $this->customName);
        }else{
            $display->removeTag(self::TAG_DISPLAY_NAME);
        }
        if(count($this->lore) > 0){
            $display->setTag(self::TAG_DISPLAY_LORE, new ListTag(array_map(static fn(string $line) => new StringTag($line), $this->lore)));
        }else{
            $display->removeTag(self::TAG_DISPLAY_LORE);
        }
        if($display->count() > 0){
            $tag->setTag(self::TAG_DISPLAY, $display);
        }else{
            $tag->removeTag(self::TAG_DISPLAY);
        }
        $enchantments = [];
        foreach($this->enchantments as $instance){
            $id = EnchantmentIdMap::getInstance()->toId($instance->getType());
            $enchantments[] = CompoundTag::create()->setShort("id", $id)->setShort("lvl", $instance->getLevel());
        }
        if(count($enchantments) > 0){
            $tag->setTag(self::TAG_ENCH, new ListTag($enchantments));
        }else{
            $tag->removeTag(self::TAG_ENCH);
        }
        return $tag;
    }

    /** @return $this */
    public function setNamedTag(CompoundTag $tag) : Item{
        $this->nbt = clone $tag;
        $display = $tag->getCompoundTag(self::TAG_DISPLAY);
        $this->customName = $display?->getString(self::TAG_DISPLAY_NAME, "") ?? "";
        $this->enchantments = [];
        $enchantments = $tag->getListTag(self::TAG_ENCH);
        if($enchantments !== null){
            foreach($enchantments as $entry){
                if(!$entry instanceof CompoundTag){
                    continue;
                }
                $type = EnchantmentIdMap::getInstance()->fromId($entry->getShort("id", -1));
                if($type !== null){
                    $this->enchantments[spl_object_id($type)] = new EnchantmentInstance($type, $entry->getShort("lvl", 1));
                }
            }
        }
        $this->lore = [];
        $lore = $display?->getListTag(self::TAG_DISPLAY_LORE);
        if($lore !== null){
            foreach($lore as $line){
                if($line instanceof StringTag){
                    $this->lore[] = $line->getValue();
                }
            }
        }
        return $this;
    }

    /** @return $this */
    public function clearNamedTag() : Item{
        $this->nbt = new CompoundTag();
        $this->customName = "";
        $this->lore = [];
        $this->enchantments = [];
        return $this;
    }

    public function hasEnchantments() : bool{
        return count($this->enchantments) > 0;
    }

    public function hasEnchantment(Enchantment $enchantment, int $level = -1) : bool{
        $instance = $this->enchantments[spl_object_id($enchantment)] ?? null;
        return $instance !== null && ($level < 0 || $instance->getLevel() === $level);
    }

    public function getEnchantment(Enchantment $enchantment) : ?EnchantmentInstance{
        return $this->enchantments[spl_object_id($enchantment)] ?? null;
    }

    public function getEnchantmentLevel(Enchantment $enchantment) : int{
        return ($this->enchantments[spl_object_id($enchantment)] ?? null)?->getLevel() ?? 0;
    }

    /**
     * @return EnchantmentInstance[]
     */
    public function getEnchantments() : array{
        return $this->enchantments;
    }

    /** @return $this */
    public function addEnchantment(EnchantmentInstance $enchantment) : Item{
        $this->enchantments[spl_object_id($enchantment->getType())] = $enchantment;
        return $this;
    }

    /** @return $this */
    public function removeEnchantment(Enchantment $enchantment, int $level = -1) : Item{
        $instance = $this->enchantments[spl_object_id($enchantment)] ?? null;
        if($instance !== null && ($level < 0 || $instance->getLevel() === $level)){
            unset($this->enchantments[spl_object_id($enchantment)]);
        }
        return $this;
    }

    /** @return $this */
    public function removeEnchantments() : Item{
        $this->enchantments = [];
        return $this;
    }

    /** the block this item places, air when it places none */
    public function getBlock(?int $clickedFace = null) : Block{
        return VanillaBlocks::fromIdentifier($this->identifier) ?? VanillaBlocks::AIR();
    }

    /** same type and damage, and the same NBT when checkCompound */
    public function equals(Item $item, bool $checkDamage = true, bool $checkCompound = true) : bool{
        return $this->identifier === $item->identifier
            && (!$checkDamage || $this->damage === $item->damage)
            && (!$checkCompound || $this->getNamedTag()->equals($item->getNamedTag()));
    }

    public function canStackWith(Item $other) : bool{
        return $this->equals($other, true, true);
    }

    public function equalsExact(Item $other) : bool{
        return $this->canStackWith($other) && $this->count === $other->count;
    }

    final public function __toString() : string{
        return "Item " . $this->name . " (" . $this->identifier . ")x" . $this->count
            . ($this->hasNamedTag() ? " tags:0x" . bin2hex((new LittleEndianNbtSerializer())->write(new TreeRoot($this->getNamedTag()))) : "");
    }

    /**
     * @return mixed[]
     */
    final public function jsonSerialize() : array{
        $data = ["id" => $this->identifier];
        if($this->count !== 1){
            $data["count"] = $this->count;
        }
        if($this->damage !== 0){
            $data["damage"] = $this->damage;
        }
        if($this->hasNamedTag()){
            $data["nbt_b64"] = base64_encode((new LittleEndianNbtSerializer())->write(new TreeRoot($this->getNamedTag())));
        }
        return $data;
    }

    /**
     * @param mixed[] $data what jsonSerialize() gave
     */
    final public static function jsonDeserialize(array $data) : Item{
        $item = VanillaItems::fromIdentifier((string) ($data["id"] ?? "")) ?? VanillaItems::AIR();
        $item->setCount((int) ($data["count"] ?? 1));
        $item->damage = (int) ($data["damage"] ?? 0);
        if(isset($data["nbt_b64"])){
            $tag = (new LittleEndianNbtSerializer())->read(base64_decode((string) $data["nbt_b64"], true) ?: "")->getTag();
            if($tag instanceof CompoundTag){
                $item->setNamedTag($tag);
            }
        }
        return $item;
    }

    public function __clone(){
        $this->nbt = clone $this->nbt;
    }
}
