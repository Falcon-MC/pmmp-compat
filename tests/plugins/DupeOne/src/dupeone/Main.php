<?php

declare(strict_types=1);

namespace dupeone;

final class Main extends \pocketmine\plugin\PluginBase{
    protected function onLoad() : void{
        echo "fixture: Dupe loaded\n";
    }

    protected function onEnable() : void{
        echo "fixture: Dupe enabled\n";
    }

    protected function onDisable() : void{
        echo "fixture: Dupe disabled\n";
    }
}
