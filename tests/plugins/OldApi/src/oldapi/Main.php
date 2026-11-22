<?php

declare(strict_types=1);

namespace oldapi;

final class Main extends \pocketmine\plugin\PluginBase{
    protected function onLoad() : void{
        echo "fixture: OldApi loaded\n";
    }

    protected function onEnable() : void{
        echo "fixture: OldApi enabled\n";
    }

    protected function onDisable() : void{
        echo "fixture: OldApi disabled\n";
    }
}
