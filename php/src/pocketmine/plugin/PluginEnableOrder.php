<?php

declare(strict_types=1);

namespace pocketmine\plugin;

/** When a plugin is enabled: plugin.yml's load, STARTUP before the worlds load or POSTWORLD after */
enum PluginEnableOrder{
    case STARTUP;
    case POSTWORLD;

    public static function fromString(string $name) : ?self{
        foreach(self::cases() as $case){
            if(in_array(strtolower($name), $case->getAliases(), true)){
                return $case;
            }
        }
        return null;
    }

    /** @return string[] */
    public function getAliases() : array{
        return match($this){
            self::STARTUP => ['startup'],
            self::POSTWORLD => ['postworld'],
        };
    }
}
