<?php

declare(strict_types=1);

namespace pocketmine\world;

/**
 * Falcon's worlds as PocketMine-MP's: Server::getWorldManager().
 *
 * Each of Falcon's levels is a World, the nether and the end named after their world (see World). Falcon loads and
 * generates its worlds itself, so loading one here only finds it.
 */
class WorldManager{
    private const DIMENSION_NAMES = [0 => "", 1 => " nether", 2 => " the_end"];

    /** @var World[] by Falcon level handle */
    private array $byLevel = [];

    /** @internal what Falcon has now, kept for the worlds already seen */
    private function refresh() : void{
        foreach(falcon_worlds() as $worldName => $levels){
            foreach($levels as $dimension => $level){
                $this->byLevel[$level] ??= new World($worldName . (self::DIMENSION_NAMES[$dimension] ?? " $dimension"),
                    $dimension, $level);
            }
        }
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
        return $this->isWorldLoaded($name);
    }

    /** true when Falcon has the world: it loads its worlds itself */
    public function loadWorld(string $name, bool $autoUpgrade = false) : bool{
        return $this->isWorldLoaded($name);
    }

    /** Falcon keeps its worlds loaded: nothing is unloaded */
    public function unloadWorld(World $world, bool $forceUnload = false) : bool{
        return false;
    }
}
