<?php

declare(strict_types=1);

namespace pocketmine\block;

use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\world\Position;
use pocketmine\world\World;

/**
 * A block state, and where it is once read from a world: PocketMine-MP's Block.
 *
 * The type is the game's identifier, minecraft:oak_planks, and the state its block states; getTypeId() and
 * getStateId() are numbers made from them, the same from one run to the next.
 */
class Block{
    /** @var array<int, array{string, string}> the state ids given out, for the network ids plugins ask for */
    private static array $statesById = [];

    protected Position $position;
    /** @var array<string, bool|int|string> */
    private array $states;

    private string $identifier;
    private ?BlockIdentifier $idInfo = null;
    private ?BlockTypeInfo $typeInfo = null;

    /**
     * Falcon's form, the identifier and its states, or PocketMine-MP's, a type id, a name and its type info, for
     * the blocks plugins define.
     *
     * @param array<string, bool|int|string>|string $states the states, or the name in PocketMine-MP's form
     */
    public function __construct(
        BlockIdentifier|string $identifier,
        array|string $states = [],
        protected string|BlockTypeInfo $fallbackName = ""
    ){
        if($identifier instanceof BlockIdentifier){
            $this->idInfo = $identifier;
            $this->typeInfo = $fallbackName instanceof BlockTypeInfo ? $fallbackName : null;
            $this->fallbackName = is_string($states) ? $states : "";
            $states = [];
            $identifier = "pmmpcompat:block_" . $this->idInfo->getBlockTypeId();
        }elseif(is_string($states)){
            $this->fallbackName = $states;
            $states = [];
        }
        $this->identifier = $identifier;
        if(!is_string($this->fallbackName) || $this->fallbackName === ""){
            $this->fallbackName = Item::nameOf($identifier);
        }
        ksort($states);
        $this->states = $states;
        $this->position = new Position(0, 0, 0, null);
    }

    /** @internal a block as Falcon gives it: name and states as JSON */
    public static function fromFalcon(string $identifier, string $statesJson) : Block{
        $states = json_decode($statesJson, true);
        $states = is_array($states) ? $states : [];
        return new (self::classFor($identifier))($identifier, $states);
    }

    /** @var array<string, class-string<Block>> */
    private static array $classes = [];

    /** the PocketMine-MP class a block type gets: by what it is, then by what Falcon knows of it */
    private static function classFor(string $identifier) : string{
        if(isset(self::$classes[$identifier])){
            return self::$classes[$identifier];
        }
        $info = \pmmpcompat\ItemTypes::block($identifier);
        return self::$classes[$identifier] = match(true){
            str_ends_with($identifier, "_sign") || $identifier === "minecraft:standing_sign" || $identifier === "minecraft:wall_sign" => BaseSign::class,
            $identifier === "minecraft:air" || str_ends_with($identifier, "_air") => Air::class,
            $identifier === "minecraft:water" || $identifier === "minecraft:flowing_water" => Water::class,
            $identifier === "minecraft:lava" || $identifier === "minecraft:flowing_lava" => Lava::class,
            $identifier === "minecraft:chest" || $identifier === "minecraft:trapped_chest" => Chest::class,
            $identifier === "minecraft:hopper" => Hopper::class,
            str_ends_with($identifier, "_leaves") => Leaves::class,
            $info === null => Block::class,
            !$info["solid"] => Flowable::class,
            $info["transparent"] => Transparent::class,
            default => Opaque::class,
        };
    }

    private function info() : ?array{
        return \pmmpcompat\ItemTypes::block($this->identifier);
    }

    public function getBreakInfo() : BlockBreakInfo{
        $info = $this->info();
        if($info === null){
            return new BlockBreakInfo(1.0);
        }
        $toolType = $info["toolType"] > 0 && $info["toolType"] <= 6 ? 1 << ($info["toolType"] - 1) : BlockToolType::NONE;
        $level = \pmmpcompat\ItemTypes::tier($info["toolTier"])?->getHarvestLevel() ?? 0;
        return new BlockBreakInfo($info["hardness"], $toolType, $level, $info["blastResistance"]);
    }

    public function getLightLevel() : int{
        return (int) ($this->info()["lightEmission"] ?? 0);
    }

    public function getLightFilter() : int{
        return $this->isTransparent() ? 0 : 15;
    }

    public function isTransparent() : bool{
        return (bool) ($this->info()["transparent"] ?? false);
    }

    public function isSolid() : bool{
        return (bool) ($this->info()["solid"] ?? true);
    }

    public function isFullCube() : bool{
        return $this->isSolid() && !$this->isTransparent();
    }

    public function canBeFlowedInto() : bool{
        return false;
    }

    public function canBeReplaced() : bool{
        return false;
    }

    public function getFlameEncouragement() : int{
        return (int) ($this->info()["flameEncouragement"] ?? 0);
    }

    public function getFlammability() : int{
        return (int) ($this->info()["flammability"] ?? 0);
    }

    public function isFlammable() : bool{
        return $this->getFlammability() > 0;
    }

    public function burnsForever() : bool{
        return false;
    }

    public function getFrictionFactor() : float{
        return 0.6;
    }

    public function hasEntityCollision() : bool{
        return false;
    }

    public function isAffectedBySilkTouch() : bool{
        return true;
    }

    public function getMaxStackSize() : int{
        return (int) ($this->info()["maxStackSize"] ?? 64);
    }

    /** @internal a copy of this state at a place in a world */
    public function withPosition(World $world, int $x, int $y, int $z) : static{
        $block = clone $this;
        $block->position = new Position($x, $y, $z, $world);
        return $block;
    }

    /** @internal places this object, as PocketMine-MP does when a block is read or about to be set */
    public function position(World $world, int $x, int $y, int $z) : void{
        $this->position = new Position($x, $y, $z, $world);
    }

    /** @internal the game's identifier */
    public function getFalconIdentifier() : string{
        return $this->identifier;
    }

    /** @internal the states as Falcon's JSON */
    public function getFalconStates() : string{
        return count($this->states) === 0 ? "{}" : (string) json_encode($this->states);
    }

    /**
     * @return array<string, bool|int|string> the game's block states
     */
    public function getStates() : array{
        return $this->states;
    }

    public function getName() : string{
        return $this->fallbackName;
    }

    public function getTypeId() : int{
        return $this->idInfo?->getBlockTypeId() ?? crc32($this->identifier);
    }

    public function getIdInfo() : BlockIdentifier{
        return $this->idInfo ??= new BlockIdentifier($this->getTypeId());
    }

    public function getTypeInfo() : BlockTypeInfo{
        return $this->typeInfo ??= new BlockTypeInfo($this->getBreakInfo());
    }

    public function hasSameTypeId(Block $other) : bool{
        return $this->getTypeId() === $other->getTypeId();
    }

    public function hasTypeTag(string $tag) : bool{
        return $this->typeInfo?->hasTypeTag($tag) ?? false;
    }

    /** @return string[] */
    public function getTypeTags() : array{
        return $this->typeInfo?->getTypeTags() ?? [];
    }

    private const COLORS = ["white", "orange", "magenta", "light_blue", "yellow", "lime", "pink", "gray", "light_gray",
        "cyan", "purple", "blue", "brown", "green", "red", "black"];

    /** the colour of a coloured block, wool, concrete, glass, carpet: its identifier says it */
    public function getColor() : \pocketmine\block\utils\DyeColor{
        $path = substr($this->identifier, strpos($this->identifier, ":") + 1);
        foreach(array_merge(["light_blue", "light_gray"], self::COLORS) as $color){
            if(str_starts_with($path, $color . "_")){
                return constant(\pocketmine\block\utils\DyeColor::class . "::" . strtoupper($color));
            }
        }
        return \pocketmine\block\utils\DyeColor::WHITE;
    }

    /** @return $this the same block in another colour */
    public function setColor(\pocketmine\block\utils\DyeColor $color) : self{
        $prefix = substr($this->identifier, 0, strpos($this->identifier, ":") + 1);
        $path = substr($this->identifier, strlen($prefix));
        foreach(array_merge(["light_blue", "light_gray"], self::COLORS) as $known){
            if(str_starts_with($path, $known . "_")){
                $wanted = strtolower($color->name);
                $this->identifier = $prefix . $wanted . substr($path, strlen($known));
                break;
            }
        }
        return $this;
    }

    public function getStateId() : int{
        $id = crc32($this->identifier . $this->getFalconStates());
        self::$statesById[$id] ??= [$this->identifier, $this->getFalconStates()];
        return $id;
    }

    /**
     * @internal the identifier and JSON states of a state id this runtime gave out
     * @return array{string, string}|null
     */
    public static function stateOf(int $stateId) : ?array{
        return self::$statesById[$stateId] ?? null;
    }

    public function isSameType(Block $other) : bool{
        return $this->identifier === $other->identifier;
    }

    public function isSameState(Block $other) : bool{
        return $this->identifier === $other->identifier && $this->states === $other->states;
    }

    public function getPosition() : Position{
        return $this->position;
    }

    /** the block next to this one, read from its world */
    public function getSide(int $side, int $step = 1) : Block{
        $position = $this->position;
        if($position->isValid()){
            return $position->getWorld()->getBlock($position->getSide($side, $step));
        }
        throw new \LogicException("Block does not have a valid world");
    }

    /** the item that places this block, air when none does */
    public function asItem() : Item{
        return VanillaItems::fromIdentifier($this->identifier) ?? VanillaItems::AIR();
    }

    public function __toString() : string{
        return "Block[" . $this->getName() . "] (" . $this->identifier . ":" . $this->getFalconStates() . ")";
    }

    public function __clone(){
        $this->position = clone $this->position;
    }
}
