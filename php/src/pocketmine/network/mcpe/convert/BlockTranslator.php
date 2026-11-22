<?php

declare(strict_types=1);

namespace pocketmine\network\mcpe\convert;

use pocketmine\block\Block;

/**
 * Block states to the ids clients know them by, for packets plugins build.
 *
 * A block state's id here is Block::getStateId(); the network id is the one Falcon sends the state by.
 */
final class BlockTranslator{
    /** @var array<int, int> */
    private array $networkIds = [];

    public function internalIdToNetworkId(int $internalStateId) : int{
        if(isset($this->networkIds[$internalStateId])){
            return $this->networkIds[$internalStateId];
        }
        $state = Block::stateOf($internalStateId);
        if($state === null){
            throw new \InvalidArgumentException("Unknown block state id $internalStateId");
        }
        [$identifier, $states] = $state;
        return $this->networkIds[$internalStateId] = falcon_block_network_id($identifier, $states);
    }
}
