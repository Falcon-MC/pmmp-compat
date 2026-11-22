<?php

declare(strict_types=1);

namespace pocketmine\item;

use pocketmine\block\Block;
use pocketmine\block\VanillaBlocks;
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

    protected int $count = 1;
    protected string $customName = "";
    /** @var string[] */
    protected array $lore = [];
    protected int $damage = 0;
    protected CompoundTag $nbt;

    public function __construct(
        private string $identifier,
        protected string $name = ""
    ){
        if($this->name === ""){
            $this->name = self::nameOf($identifier);
        }
        $this->nbt = new CompoundTag();
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
        $item = new self($data["id"]);
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
        return crc32($this->identifier);
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
        return 64;
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
        return $tag;
    }

    /** @return $this */
    public function setNamedTag(CompoundTag $tag) : Item{
        $this->nbt = clone $tag;
        $display = $tag->getCompoundTag(self::TAG_DISPLAY);
        $this->customName = $display?->getString(self::TAG_DISPLAY_NAME, "") ?? "";
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
