<?php

declare(strict_types=1);

namespace beta;

final class Main extends \pocketmine\plugin\PluginBase{
    protected function onLoad() : void{
        echo "fixture: Beta loaded\n";
    }

    protected function onEnable() : void{
        echo "fixture: Beta enabled, data folder " . (is_dir($this->getDataFolder()) ? "made" : "missing") . "\n";
    }

    protected function onDisable() : void{
        echo "fixture: Beta disabled\n";
    }
}
