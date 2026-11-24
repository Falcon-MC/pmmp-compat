<?php

declare(strict_types=1);

namespace pocketmine\block;

use pocketmine\utils\SingletonTrait;

/**
 * Block state ids to blocks, as PocketMine-MP's registry of the states it knows.
 *
 * Falcon knows every state: an id this runtime gave out reads back, and a block a plugin registers is kept too.
 */
class RuntimeBlockStateRegistry{
    use SingletonTrait;

    /** @var Block[] by state id */
    private array $registered = [];
    /** @var Block[] by type id */
    private array $typeIndex = [];

    /** @var array<int, float> Blast resistance overrides, indexed by block state ID. */
    public array $blastResistance = [];

    public function register(Block $block) : void{
        $this->typeIndex[$block->getTypeId()] = clone $block;
        foreach($block->generateStatePermutations() as $state){
            $this->fillStaticArrays($state->getStateId(), $state);
        }
    }

    public function fromStateId(int $stateId) : Block{
        if($stateId < 0 && $stateId >= -0x80000000){
            $stateId &= 0xffffffff;
        }
        if(isset($this->registered[$stateId])){
            return clone $this->registered[$stateId];
        }
        $state = Block::stateOf($stateId);
        if($state === null){
            return VanillaBlocks::AIR();
        }
        return Block::fromFalcon($state[0], $state[1]);
    }

    public function fillStaticArrays(int $index, Block $block) : void{
        $this->registered[$index] = clone $block;
    }

    public function hasStateId(int $stateId) : bool{
        if($stateId < 0 && $stateId >= -0x80000000){
            $stateId &= 0xffffffff;
        }
        return isset($this->registered[$stateId]) || Block::stateOf($stateId) !== null;
    }

    /** @internal A translated state may travel in a signed 32-bit worker message. */
    public function rememberTranslatedState(Block $block, int $stateId) : void{
        $workerId = $stateId << 32 >> 32;
        $this->typeIndex[$workerId] ??= $block;
    }

    /** @return Block[] the states known so far, by id */
    public function getAllKnownStates() : array{
        return $this->registered;
    }
}
