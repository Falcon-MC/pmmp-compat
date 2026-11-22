<?php

declare(strict_types=1);

namespace quitter;

final class Main extends \pocketmine\plugin\PluginBase{
    protected function onEnable() : void{
        throw new \pocketmine\plugin\DisablePluginException();
    }
}
