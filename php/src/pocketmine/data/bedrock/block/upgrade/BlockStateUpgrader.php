<?php

declare(strict_types=1);

namespace pocketmine\data\bedrock\block\upgrade;

use pocketmine\data\bedrock\block\BlockStateData;

/** Saved states of older game versions to the current one; Falcon upgrades its worlds itself, so states pass as they are */
final class BlockStateUpgrader{
    public function upgrade(BlockStateData $blockStateData) : BlockStateData{
        return $blockStateData;
    }
}
