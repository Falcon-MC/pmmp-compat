<?php

declare(strict_types=1);

namespace compatworldevents;

use pocketmine\block\Farmland;
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\object\FallingBlock;
use pocketmine\event\block\BlockUpdateEvent;
use pocketmine\event\block\FarmlandHydrationChangeEvent;
use pocketmine\event\entity\EntityBlockChangeEvent;
use pocketmine\event\Listener;
use pocketmine\event\server\LowMemoryEvent;
use pocketmine\event\server\QueryRegenerateEvent;
use pocketmine\event\world\ChunkPopulateEvent;
use pocketmine\event\world\SpawnChangeEvent;
use pocketmine\event\world\WorldDifficultyChangeEvent;
use pocketmine\event\world\WorldDisplayNameChangeEvent;
use pocketmine\event\world\WorldInitEvent;
use pocketmine\event\world\WorldLoadEvent;
use pocketmine\event\world\WorldParticleEvent;
use pocketmine\event\world\WorldSoundEvent;
use pocketmine\math\Vector3;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\ClosureTask;
use pocketmine\world\particle\Particle;
use pocketmine\world\sound\Sound;

final class Main extends PluginBase implements Listener{
    private ?Vector3 $observedSpawn = null;
    private ?int $observedDifficulty = null;
    private bool $observedNeighbour = false;
    private ?Vector3 $fallingBlockLanding = null;
    private bool $observedFallingBlock = false;
    private bool $observedSound = false;
    private bool $observedParticle = false;
    private bool $observedDisplayName = false;
    private bool $observedLowMemory = false;
    private bool $observedInit = false;
    private bool $initializedBeforeLoad = false;
    private bool $observedPopulate = false;

    protected function onEnable() : void{
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->getScheduler()->scheduleDelayedTask(new ClosureTask($this->exercise(...)), 5);
        $this->getScheduler()->scheduleDelayedTask(new ClosureTask($this->exerciseFallingBlock(...)), 35);
        $this->getScheduler()->scheduleDelayedTask(new ClosureTask($this->verify(...)), 75);
    }

    private function exercise() : void{
        $server = $this->getServer();
        $world = $server->getWorldManager()->getDefaultWorld();
        $spawn = $world->getSpawnLocation();
        $world->setSpawnLocation($spawn->add(1, 0, 0));
        self::expect($this->observedSpawn?->equals($spawn) === true, 'previous spawn');
        self::expect($world->getSpawnLocation()->getFloorX() === $spawn->getFloorX() + 1, 'new spawn');
        $world->setSpawnLocation($spawn);

        $difficulty = $world->getDifficulty();
        $newDifficulty = ($difficulty + 1) % 4;
        $world->setDifficulty($newDifficulty);
        self::expect($this->observedDifficulty === $newDifficulty, 'difficulty event');
        self::expect($world->getDifficulty() === $newDifficulty, 'new difficulty');
        $world->setDifficulty($difficulty);

        $displayName = $world->getDisplayName();
        $world->setDisplayName('Event fixture');
        self::expect($this->observedDisplayName && $world->getDisplayName() === 'Event fixture', 'display name');
        $world->setDisplayName($displayName);

        $suppressed = new class implements Sound, Particle{
            public function encode(Vector3 $pos) : array{
                throw new \LogicException('A cancelled sound or particle reached the encoder');
            }
        };
        $world->addSound($spawn, $suppressed, []);
        $world->addParticle($spawn, $suppressed, []);
        self::expect($this->observedSound && $this->observedParticle, 'sound and particle');

        $server->getMemoryManager()->trigger(2_000_000, 1_000_000, true, 7);
        self::expect($this->observedLowMemory, 'low memory payload');

        $farmland = VanillaBlocks::FARMLAND();
        self::expect($farmland instanceof Farmland && $farmland->setWetness(3)->getWetness() === 3,
            'typed farmland state');
        $hydration = new FarmlandHydrationChangeEvent($farmland, 3, 7);
        $hydration->setNewHydration(5);
        self::expect($hydration->getOldHydration() === 3 && $hydration->getNewHydration() === 5,
            'mutable hydration payload');

        $pos = new Vector3($spawn->getFloorX(), min($spawn->getFloorY() + 3, $world->getMaxY() - 1), $spawn->getFloorZ());
        $oldBlock = $world->getBlock($pos);
        $world->setBlock($pos, VanillaBlocks::DIAMOND_BLOCK());
        self::expect($this->observedNeighbour, 'neighbour update');
        $world->setBlock($pos, $oldBlock);

        self::expect($server->dispatchCommand($server->getConsoleSender(), 'world create compat-events-fixture'), 'world create');
    }

    private function exerciseFallingBlock() : void{
        $server = $this->getServer();
        $world = $server->getWorldManager()->getDefaultWorld();
        $spawn = $server->getPlayerExact('Visitor')?->getPosition() ?? $world->getSpawnLocation();
        $platform = new Vector3(($spawn->getFloorX() & ~15) + 8,
            min($spawn->getFloorY() + 6, $world->getMaxY() - 5), ($spawn->getFloorZ() & ~15) + 8);
        $this->fallingBlockLanding = $platform->add(0, 1, 0);
        $sand = $platform->add(0, 3, 0);
        $world->getBlock($platform); // ensure the chunk is resident before setting fixture blocks
        $world->setBlock($platform, VanillaBlocks::STONE());
        $world->setBlock($this->fallingBlockLanding, VanillaBlocks::AIR());
        $world->setBlock($platform->add(0, 2, 0), VanillaBlocks::AIR());
        $world->setBlock($sand, VanillaBlocks::SAND());
        self::expect($world->getBlock($sand)->getFalconIdentifier() === 'minecraft:sand', 'placed the falling block');
    }

    private function verify() : void{
        self::expect($this->initializedBeforeLoad, 'new world initialized before load');
        self::expect($this->observedPopulate, 'generated chunk populated');
        $world = $this->getServer()->getWorldManager()->getDefaultWorld();
        self::expect($this->observedFallingBlock && $world->getBlock($this->fallingBlockLanding)->getFalconIdentifier() === 'minecraft:air',
            'falling block cancellation');
        $query = $this->getServer()->getQueryInformation();
        self::expect(($query->getExtraData()['fixture'] ?? '') === 'yes' &&
            str_contains($query->getLongQuery(), "fixture\0yes\0"), 'query override serialized');
        $this->getLogger()->info('world compatibility events passed');
    }

    public function onSpawn(SpawnChangeEvent $event) : void{
        $this->observedSpawn = $event->getPreviousSpawn();
    }

    public function onDifficulty(WorldDifficultyChangeEvent $event) : void{
        $this->observedDifficulty = $event->getNewDifficulty();
    }

    public function onDisplayName(WorldDisplayNameChangeEvent $event) : void{
        $this->observedDisplayName = $event->getNewName() === 'Event fixture';
    }

    public function onSound(WorldSoundEvent $event) : void{
        $this->observedSound = $event->getRecipients() === [];
        $event->cancel();
    }

    public function onParticle(WorldParticleEvent $event) : void{
        $this->observedParticle = $event->getRecipients() === [];
        $event->cancel();
    }

    public function onMemory(LowMemoryEvent $event) : void{
        $this->observedLowMemory = $event->getMemory() === 2_000_000 && $event->getMemoryLimit() === 1_000_000
            && $event->isGlobal() && $event->getTriggerCount() === 7;
    }

    public function onQuery(QueryRegenerateEvent $event) : void{
        $event->getQueryInfo()->setExtraData(['fixture' => 'yes']);
    }

    public function onBlockUpdate(BlockUpdateEvent $event) : void{
        $this->observedNeighbour = true;
        $event->cancel();
    }

    public function onFallingBlockLand(EntityBlockChangeEvent $event) : void{
        if($this->fallingBlockLanding !== null && $event->getEntity() instanceof FallingBlock &&
            $event->getBlock()->getPosition()->equals($this->fallingBlockLanding)){
            $this->observedFallingBlock = $event->getTo()->getFalconIdentifier() === 'minecraft:sand';
            $event->cancel();
        }
    }

    public function onWorldInit(WorldInitEvent $event) : void{
        if($event->getWorld()->getFolderName() === 'compat-events-fixture'){
            $this->observedInit = true;
        }
    }

    public function onWorldLoad(WorldLoadEvent $event) : void{
        if($event->getWorld()->getFolderName() === 'compat-events-fixture'){
            $this->initializedBeforeLoad = $this->observedInit;
        }
    }

    public function onPopulate(ChunkPopulateEvent $event) : void{
        $this->observedPopulate = true;
    }

    private static function expect(bool $condition, string $check) : void{
        if(!$condition){
            throw new \RuntimeException("World event fixture failed: $check");
        }
    }
}
