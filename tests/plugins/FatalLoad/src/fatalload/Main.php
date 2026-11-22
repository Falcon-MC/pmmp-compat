<?php

declare(strict_types=1);

namespace fatalload;

// a missing trait is a fatal error, which used to stop every plugin after this one
final class Main extends \pocketmine\plugin\PluginBase{
    use \pocketmine\utils\NoSuchTrait;
}
