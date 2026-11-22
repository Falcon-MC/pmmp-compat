<?php

declare(strict_types=1);

namespace fatalenable;

final class Main extends \pocketmine\plugin\PluginBase{
    protected function onEnable() : void{
        echo "fixture: FatalEnable enabling\n";
        new Broken();
    }
}
