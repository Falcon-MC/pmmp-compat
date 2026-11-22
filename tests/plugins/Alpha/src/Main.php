<?php

declare(strict_types=1);

namespace alpha\plugin;

use alpha\plugin\util\Greeter;

final class Main extends \pocketmine\plugin\PluginBase{
    protected function onEnable() : void{
        echo "fixture: Alpha enabled, " . Greeter::greet() . "\n";
    }

    protected function onDisable() : void{
        echo "fixture: Alpha disabled\n";
    }
}
