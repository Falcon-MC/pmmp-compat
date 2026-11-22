<?php

declare(strict_types=1);

namespace needsmongo;

final class Main extends \pocketmine\plugin\PluginBase{
    protected function onLoad() : void{
        echo "fixture: NeedsMongo loaded\n";
    }

    protected function onEnable() : void{
        echo "fixture: NeedsMongo enabled\n";
    }

    protected function onDisable() : void{
        echo "fixture: NeedsMongo disabled\n";
    }
}
