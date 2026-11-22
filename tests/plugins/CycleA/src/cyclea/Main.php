<?php

declare(strict_types=1);

namespace cyclea;

final class Main extends \pocketmine\plugin\PluginBase{
    protected function onLoad() : void{
        echo "fixture: CycleA loaded\n";
    }

    protected function onEnable() : void{
        echo "fixture: CycleA enabled\n";
    }

    protected function onDisable() : void{
        echo "fixture: CycleA disabled\n";
    }
}
