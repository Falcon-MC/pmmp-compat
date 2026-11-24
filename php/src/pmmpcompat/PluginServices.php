<?php

declare(strict_types=1);

namespace pmmpcompat;

use Closure;
use pocketmine\plugin\Plugin;
use Throwable;

/** Named synchronous string services shared with Falcon plugins on the server thread. */
final class PluginServices{
    /** @var array<string, array{Plugin, Closure(string) : string}> */
    private static array $services = [];

    private function __construct(){
    }

    /** @param Closure(string) : string $handler */
    public static function register(Plugin $owner, string $name, Closure $handler) : bool{
        if(!$owner->isEnabled() || isset(self::$services[$name]) || !falcon_service_register($name)){
            return false;
        }
        self::$services[$name] = [$owner, $handler];
        return true;
    }

    public static function unregister(Plugin $owner, string $name) : void{
        if((self::$services[$name][0] ?? null) === $owner){
            falcon_service_unregister($name);
            unset(self::$services[$name]);
        }
    }

    public static function exists(string $name) : bool{
        return falcon_service_exists($name);
    }

    public static function provider(string $name) : ?string{
        return isset(self::$services[$name]) ? self::$services[$name][0]->getName() : falcon_service_provider($name);
    }

    /** Returns null when the native registry cannot call the named service. */
    public static function call(string $name, string $request = '') : ?string{
        return falcon_service_call($name, $request);
    }

    /** @internal */
    public static function removeOwned(Plugin $owner) : void{
        foreach(self::$services as $name => [$plugin]){
            if($plugin === $owner){
                self::unregister($owner, (string) $name);
            }
        }
    }

    /** @internal Falcon calls this through the bridge. */
    public static function dispatch(string $name, string $request) : string{
        $service = self::$services[$name] ?? null;
        if($service === null || !$service[0]->isEnabled()){
            return '';
        }
        try{
            $response = $service[1]($request);
            if(str_contains($response, "\0")){
                throw new \UnexpectedValueException('a service response cannot contain a null byte');
            }
            return $response;
        }catch(Throwable $error){
            Log::critical("Service '$name' failed: " . $error->getMessage());
            Log::exception($error);
            return '';
        }
    }
}
