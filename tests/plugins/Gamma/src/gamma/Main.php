<?php

declare(strict_types=1);

namespace gamma;

final class Main extends \pocketmine\plugin\PluginBase{
    protected function onLoad() : void{
        echo "fixture: Gamma loaded\n";
    }

    protected function onEnable() : void{
        echo "fixture: Gamma enabled\n";
    }

    protected function onDisable() : void{
        echo "fixture: Gamma disabled\n";
    }
}
