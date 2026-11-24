<?php

declare(strict_types=1);

namespace pocketmine\data\java;

use pocketmine\player\GameMode;
use pocketmine\utils\SingletonTrait;

/** Game mode IDs used in Java world data and by plugins that store player state. */
final class GameModeIdMap{
    use SingletonTrait;

    public function fromId(int $id) : ?GameMode{
        return match($id){
            0 => GameMode::SURVIVAL,
            1 => GameMode::CREATIVE,
            2 => GameMode::ADVENTURE,
            3 => GameMode::SPECTATOR,
            default => null,
        };
    }

    public function toId(GameMode $type) : int{
        return match($type){
            GameMode::SURVIVAL => 0,
            GameMode::CREATIVE => 1,
            GameMode::ADVENTURE => 2,
            GameMode::SPECTATOR => 3,
        };
    }
}
