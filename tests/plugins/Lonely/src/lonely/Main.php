<?php

declare(strict_types=1);

namespace lonely;

final class Main extends \pocketmine\plugin\PluginBase{
    protected function onLoad() : void{
        echo "fixture: Lonely loaded\n";
    }

    protected function onEnable() : void{
        echo "fixture: Lonely enabled\n";
    }

    protected function onDisable() : void{
        echo "fixture: Lonely disabled\n";
    }
}
