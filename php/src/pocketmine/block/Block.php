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
    protected Position $position;
    /** @var array<string, bool|int|string> */
    private array $states;

    /**
     * @param array<string, bool|int|string> $states
     */
    public function __construct(
        private string $identifier,
        array $states = [],
        protected string $fallbackName = ""
    ){
        if($this->fallbackName === ""){
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
        return str_ends_with($identifier, "_sign") || $identifier === "minecraft:standing_sign" || $identifier === "minecraft:wall_sign"
            ? new BaseSign($identifier, $states) : new Block($identifier, $states);
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
        return crc32($this->identifier);
    }

    public function getStateId() : int{
        return crc32($this->identifier . $this->getFalconStates());
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
