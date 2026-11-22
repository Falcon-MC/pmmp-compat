<?php

declare(strict_types=1);

namespace pocketmine\plugin;

use pocketmine\utils\VersionString;

/** Whether this server can run a plugin, from its plugin.yml alone */
final class PluginLoadabilityChecker{
    private const COMPARATORS = ['<=', 'le', '<>', '!=', 'ne', '<', 'lt', '==', '=', 'eq', '>=', 'ge', '>', 'gt'];

    public function __construct(
        private string $apiVersion
    ){
    }

    /** null when the plugin can load, otherwise why not */
    public function check(PluginDescription $description) : ?string{
        $name = $description->getName();
        if(stripos($name, 'pocketmine') !== false || stripos($name, 'minecraft') !== false
            || stripos($name, 'mojang') !== false){
            return 'Restricted name';
        }

        foreach($description->getCompatibleApis() as $api){
            if(!VersionString::isValidBaseVersion($api)){
                return "Invalid API version '$api'";
            }
        }
        if(!ApiVersion::isCompatible($this->apiVersion, $description->getCompatibleApis())){
            return 'Incompatible API version (plugin requires one of: '
                . implode(', ', $description->getCompatibleApis()) . ')';
        }
        $ambiguous = ApiVersion::checkAmbiguousVersions($description->getCompatibleApis());
        if(count($ambiguous) > 0){
            return 'Multiple minimum API versions found for some major versions (' . implode(', ', $ambiguous) . ')';
        }

        $systems = $description->getCompatibleOperatingSystems();
        if(count($systems) > 0 && !in_array(self::operatingSystem(), $systems, true)){
            return 'Incompatible Operating System (plugin requires one of: ' . implode(', ', $systems) . ')';
        }
        // mcpe-protocol is not checked: Falcon speaks its own protocol versions, and plugins listing one are rare

        foreach($description->getRequiredExtensions() as $extension => $constraints){
            $extension = (string) $extension;
            if(!extension_loaded($extension)){
                return "Required PHP extension '$extension' is not loaded";
            }
            $loaded = phpversion($extension);
            if($loaded === false){
                $loaded = '**UNKNOWN**';
            }
            foreach($constraints as $index => $constraint){
                $problem = self::checkConstraint($extension, $loaded, (string) $index, $constraint);
                if($problem !== null){
                    return $problem;
                }
            }
        }
        return null;
    }

    private static function checkConstraint(string $extension, string $loaded, string $index, string $constraint) : ?string{
        if($constraint === '*'){
            return null;
        }
        if($constraint === ''){
            return "Version constraint $index for required PHP extension '$extension' is empty";
        }
        foreach(self::COMPARATORS as $comparator){
            if(str_starts_with($constraint, $comparator)){
                $wanted = substr($constraint, strlen($comparator));
                if(!version_compare($loaded, $wanted, $comparator)){
                    return "Incompatible version $loaded of PHP extension '$extension' (plugin requires: $constraint)";
                }
                return null;
            }
        }
        return "Invalid version constraint $constraint for required PHP extension '$extension'";
    }

    /** as PocketMine-MP names the system in plugin.yml's os */
    private static function operatingSystem() : string{
        return match(PHP_OS_FAMILY){
            'Windows' => 'win',
            'Darwin' => 'mac',
            'BSD' => 'bsd',
            default => 'linux',
        };
    }
}
