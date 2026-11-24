<?php

declare(strict_types=1);

namespace pocketmine\world;

use pmmpcompat\world\WorldGeneration;

/**
 * Falcon's worlds as PocketMine-MP's: Server::getWorldManager().
 *
 * Each of Falcon's levels is a World, the nether and the end named after their world (see World). Falcon loads and
 * generates its worlds with a shared native store and optional PHP generator overlays.
 */
class WorldManager{
    private const DIMENSION_NAMES = [0 => "", 1 => " nether", 2 => " the_end"];

    /** @var World[] by Falcon level handle */
    private array $byLevel = [];
    private ?\pocketmine\utils\Config $displayNames = null;

    /** @internal */
    public function displayNameOf(string $folderName) : string{
        return (string) ($this->displayNames(false)?->get($folderName, $folderName) ?? $folderName);
    }

    /** @internal */
    public function storeDisplayName(string $folderName, string $name) : void{
        $config = $this->displayNames(true);
        $config->set($folderName, $name);
        $config->save();
    }

    private function displayNames(bool $create) : ?\pocketmine\utils\Config{
        $path = dirname(rtrim(\pocketmine\Server::getInstance()->getPluginPath(), "/\\")) . DIRECTORY_SEPARATOR;
        if($this->displayNames === null){
            $file = $path . "world-display-names.json";
            if($create || is_file($file)){
                $this->displayNames = new \pocketmine\utils\Config($file, \pocketmine\utils\Config::JSON);
            }
        }
        return $this->displayNames;
    }

    /** @internal Refresh native handles after world load and deferred unload. */
    public function refresh() : void{
        $active = [];
        foreach(falcon_worlds() as $worldName => $levels){
            foreach($levels as $dimension => $level){
                $name = $worldName . (self::DIMENSION_NAMES[$dimension] ?? " $dimension");
                $world = $this->byLevel[$level] ?? null;
                if($world === null || $world->getFolderName() !== $name){
                    $world?->onUnload();
                    $world = new World($name, $dimension, $level);
                }
                $active[$level] = $world;
            }
        }
        foreach(array_diff_key($this->byLevel, $active) as $world){
            $world->onUnload();
            WorldGeneration::forget($world->getFolderName());
        }
        $this->byLevel = $active;
    }

    /** @internal the World for a Falcon level handle */
    public function forLevel(int $level) : ?World{
        if($level === 0){
            return null;
        }
        if(!isset($this->byLevel[$level])){
            $this->refresh();
        }
        return $this->byLevel[$level] ?? null;
    }

    /**
     * @return World[] by world id
     */
    public function getWorlds() : array{
        $this->refresh();
        $worlds = [];
        foreach($this->byLevel as $world){
            $worlds[$world->getId()] = $world;
        }
        return $worlds;
    }

    /** @internal */
    public function tickScheduledBlockUpdates(int $currentTick) : void{
        foreach($this->byLevel as $world){
            $world->tickScheduledBlockUpdates($currentTick);
        }
    }

    public function getDefaultWorld() : ?World{
        return $this->getWorldByName(falcon_default_world());
    }

    public function getWorld(int $worldId) : ?World{
        return $this->getWorlds()[$worldId] ?? null;
    }

    public function getWorldByName(string $name) : ?World{
        foreach($this->getWorlds() as $world){
            if(strcasecmp($world->getFolderName(), $name) === 0){
                return $world;
            }
        }
        return null;
    }

    /** an entity of any world, by its id */
    public function findEntity(int $entityId) : ?\pocketmine\entity\Entity{
        return \pmmpcompat\Entities::byId($entityId);
    }

    public function isWorldLoaded(string $name) : bool{
        return $this->getWorldByName($name) !== null;
    }

    public function isWorldGenerated(string $name) : bool{
        $directory = WorldGeneration::directory($name);
        return $this->isWorldLoaded($name) || ($directory !== null && is_dir($directory . '/db'));
    }

    public function loadWorld(string $name, bool $autoUpgrade = false) : bool{
        return $this->isWorldLoaded($name) || (WorldGeneration::directory($name) !== null && falcon_world_load($name, false, ''));
    }

    public function generateWorld(string $name, WorldCreationOptions $options, bool $backgroundGeneration = true) : bool{
        $directory = WorldGeneration::directory($name);
        if($directory === null || $this->isWorldGenerated($name) || file_exists($directory) || is_link($directory)){
            return false;
        }
        if(!mkdir($directory, 0777, true)){
            return false;
        }
        try{
            WorldGeneration::prepare($directory, $name, $options);
            if(falcon_world_load($name, true, (string) $options->getSeed())){
                $world = $this->getWorldByName($name);
                if($world !== null && (!$backgroundGeneration || WorldGeneration::hasCustomGenerator($name))){
                    $spawn = $world->getSpawnLocation();
                    $world->loadChunk($spawn->getFloorX() >> 4, $spawn->getFloorZ() >> 4);
                }
                return $world !== null;
            }
        }catch(\Throwable $error){
            \pmmpcompat\Log::critical("Could not generate world '$name': " . $error->getMessage());
        }
        WorldGeneration::forget($name);
        if(!is_dir($directory . '/db')){
            foreach(['level.dat', 'compat-generator.json'] as $file){
                if(is_file($directory . '/' . $file)){
                    unlink($directory . '/' . $file);
                }
            }
            rmdir($directory);
        }
        return false;
    }

    public function unloadWorld(World $world, bool $forceUnload = false) : bool{
        $name = $world->getFolderName();
        if(!$world->isLoaded() || $world === $this->getDefaultWorld() || WorldGeneration::directory($name) === null){
            return false;
        }
        if($forceUnload){
            $default = $this->getDefaultWorld();
            if($default === null){
                return false;
            }
            foreach($world->getPlayers() as $player){
                if(!$player->teleport($default->getSpawnLocation())){
                    return false;
                }
            }
        }
        return falcon_world_unload($name);
    }
}
