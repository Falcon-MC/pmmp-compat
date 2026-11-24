<?php

declare(strict_types=1);

namespace legacyapi;

final class Main extends \pocketmine\plugin\PluginBase{
    protected function onLoad() : void{
        echo "fixture: LegacyApi loaded\n";
    }

    protected function onEnable() : void{
        echo "fixture: LegacyApi enabled\n";
    }

    protected function onDisable() : void{
        echo "fixture: LegacyApi disabled\n";
    }
}
