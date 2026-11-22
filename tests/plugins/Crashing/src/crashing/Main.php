<?php

declare(strict_types=1);

namespace crashing;

final class Main extends \pocketmine\plugin\PluginBase{
    protected function onEnable() : void{
        throw new \RuntimeException("boom");
    }

    protected function onDisable() : void{
        echo "fixture: Crashing cleaned up\n";
    }
}
