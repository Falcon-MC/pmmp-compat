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

    public function register(Block $block) : void{
        $this->registered[$block->getStateId()] = $block;
    }

    public function fromStateId(int $stateId) : Block{
        if(isset($this->registered[$stateId])){
            return clone $this->registered[$stateId];
        }
        $state = Block::stateOf($stateId);
        if($state === null){
            return VanillaBlocks::AIR();
        }
        return Block::fromFalcon($state[0], $state[1]);
    }

    public function hasStateId(int $stateId) : bool{
        return isset($this->registered[$stateId]) || Block::stateOf($stateId) !== null;
    }

    /** @return Block[] the states known so far, by id */
    public function getAllKnownStates() : array{
        return $this->registered;
    }
}
