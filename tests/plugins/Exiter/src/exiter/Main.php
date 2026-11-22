<?php

declare(strict_types=1);

namespace exiter;

final class Main extends \pocketmine\plugin\PluginBase{
    protected function onEnable() : void{
        exit(1);
    }
}
