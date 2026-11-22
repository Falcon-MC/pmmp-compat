<?php

declare(strict_types=1);

namespace cycleb;

final class Main extends \pocketmine\plugin\PluginBase{
    protected function onLoad() : void{
        echo "fixture: CycleB loaded\n";
    }

    protected function onEnable() : void{
        echo "fixture: CycleB enabled\n";
    }

    protected function onDisable() : void{
        echo "fixture: CycleB disabled\n";
    }
}
