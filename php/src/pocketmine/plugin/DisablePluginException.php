<?php

declare(strict_types=1);

namespace pocketmine\plugin;

/** Thrown from onEnable() to disable the plugin without an error */
class DisablePluginException extends \RuntimeException{
}
