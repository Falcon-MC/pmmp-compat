<?php

declare(strict_types=1);

namespace compatworldevents;

use pocketmine\block\Block;
use pocketmine\block\Farmland;
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\effect\EffectInstance;
use pocketmine\entity\effect\VanillaEffects;
use pocketmine\entity\object\FallingBlock;
use pocketmine\entity\Zombie;
use pocketmine\event\block\BlockUpdateEvent;
use pocketmine\event\block\FarmlandHydrationChangeEvent;
use pocketmine\event\entity\AreaEffectCloudApplyEvent;
use pocketmine\event\entity\EntityBlockChangeEvent;
use pocketmine\event\entity\EntitySpawnEvent;
use pocketmine\event\Listener;
use pocketmine\event\server\DataPacketSendEvent;
use pocketmine\event\server\LowMemoryEvent;
use pocketmine\event\server\QueryRegenerateEvent;
use pocketmine\event\server\UpdateNotifyEvent;
use pocketmine\event\world\ChunkPopulateEvent;
use pocketmine\event\world\SpawnChangeEvent;
use pocketmine\event\world\WorldDifficultyChangeEvent;
use pocketmine\event\world\WorldDisplayNameChangeEvent;
use pocketmine\event\world\WorldInitEvent;
use pocketmine\event\world\WorldLoadEvent;
use pocketmine\event\world\WorldParticleEvent;
use pocketmine\event\world\WorldSoundEvent;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\PlaySoundPacket;
use pocketmine\network\mcpe\protocol\SpawnParticleEffectPacket;
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
    private bool $observedEntitySpawn = false;
    private ?Vector3 $expectedEntitySpawn = null;
    private bool $observedSound = false;
    private bool $observedParticle = false;
    private bool $observedDisplayName = false;
    private bool $observedLowMemory = false;
    private bool $observedInit = false;
    private bool $initializedBeforeLoad = false;
    private bool $observedPopulate = false;
    private bool $testNativeWorld = false;
    private bool $startupInitSeen = false;
    private bool $startupInitBeforeLoad = false;
    private bool $checkingNativeMedia = false;
    private bool $nativeSound = false;
    private bool $nativeParticle = false;
    private bool $nativeSoundReplacement = false;
    private bool $nativeSoundSuppressed = false;
    private bool $nativeCloudReplaced = false;

    protected function onEnable() : void{
        $this->testNativeWorld = getenv('PMMP_COMPAT_TEST_NATIVE_WORLD_EVENTS') === '1';
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
        $world->setBlock($pos, VanillaBlocks::OAK_SLAB());
        self::expect($this->observedNeighbour, 'neighbour update');
        if($this->testNativeWorld){
            $this->verifyBlockLayers($pos);
        }
        $world->setBlock($pos, $oldBlock);
        $this->expectedEntitySpawn = $pos->add(0.5, 1, 0.5);
        $spawnAt = $this->expectedEntitySpawn;
        self::expect($server->dispatchCommand($server->getConsoleSender(), "summon zombie {$spawnAt->x} {$spawnAt->y} {$spawnAt->z}"),
            'summoned an entity to check its spawn snapshot');

        self::expect($server->dispatchCommand($server->getConsoleSender(), 'world create compat-events-fixture'), 'world create');
    }

    private function verifyBlockLayers(Vector3 $pos) : void{
        $world = $this->getServer()->getWorldManager()->getDefaultWorld();
        $x = $pos->getFloorX();
        $y = $pos->getFloorY();
        $z = $pos->getFloorZ();
        $chunk = $world->getChunk($x >> 4, $z >> 4);
        self::expect($chunk !== null, 'block-layer chunk is loaded');
        $subChunk = $chunk->getSubChunk($y >> 4);
        [$primary, $overlay] = $subChunk->getBlockLayers();
        self::expect($primary->get($x, $y, $z) === $world->getBlockAt($x, $y, $z)->getStateId()
            && $world->getBlockAt($x, $y, $z)->getFalconIdentifier() === 'minecraft:oak_slab', 'primary block in the subchunk');
        $previous = $overlay->get($x, $y, $z);
        $water = Block::fromFalcon('minecraft:water', '{"liquid_depth":0}')->getStateId();
        $overlay->set($x, $y, $z, $water);
        $otherView = $chunk->getSubChunk($y >> 4)->getBlockLayers()[1];
        self::expect($otherView->get($x, $y, $z) === $water, 'overlay write is visible from a second subchunk view');
        $otherView->set($x, $y, $z, $previous);
        self::expect($overlay->get($x, $y, $z) === $previous, 'overlay restored through the second view');
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

        if($this->testNativeWorld){
            $this->checkingNativeMedia = true;
            try{
                $server->dispatchCommand($server->getConsoleSender(), 'playsound random.pop Visitor');
                $server->dispatchCommand($server->getConsoleSender(), 'playsound random.bow Visitor');
                $server->dispatchCommand($server->getConsoleSender(), 'particle minecraft:basic_flame_particle 8 100 8');
            }finally{
                $this->checkingNativeMedia = false;
            }
            $this->exerciseCloud();
        }
    }

    private function exerciseCloud() : void{
        $server = $this->getServer();
        $player = $server->getPlayerExact('Visitor');
        self::expect($player !== null, 'cloud has a simulated player');
        $world = $player->getWorld();
        $position = $player->getPosition();
        $x = $position->getFloorX();
        $y = $position->getFloorY();
        $z = $position->getFloorZ();
        $world->setBlockAt($x, $y - 1, $z, VanillaBlocks::STONE());
        $world->setBlockAt($x, $y, $z, VanillaBlocks::AIR());
        $world->setBlockAt($x, $y + 1, $z, VanillaBlocks::AIR());
        $world->setBlockAt($x, $y + 2, $z, VanillaBlocks::AIR());
        self::expect($player->teleport(new Vector3($x + 0.5, $y, $z + 0.5)), 'player in the cloud test area');

        // Aim beside the player so the potion lands at their feet instead of hitting their head.
        ++$x;
        $world->setBlockAt($x, $y - 1, $z, VanillaBlocks::STONE());
        $world->setBlockAt($x, $y, $z, VanillaBlocks::AIR());
        $world->setBlockAt($x, $y + 1, $z, VanillaBlocks::AIR());
        $world->setBlockAt($x, $y + 2, $z, VanillaBlocks::AIR());
        $dispenserY = $y + 3;
        $dispenser = Block::fromFalcon('minecraft:dispenser', '{"facing_direction":0,"triggered_bit":false}');
        $world->setBlockAt($x, $dispenserY, $z, $dispenser);
        self::expect($server->dispatchCommand($server->getConsoleSender(),
            "replaceitem block $x $dispenserY $z slot.container 0 lingering_potion 1 17"), 'filled the potion dispenser');
        $world->setBlockAt($x, $dispenserY + 1, $z, VanillaBlocks::REDSTONE_BLOCK());
    }

    private function verify() : void{
        self::expect($this->initializedBeforeLoad, 'new world initialized before load');
        self::expect($this->observedPopulate, 'generated chunk populated');
        $world = $this->getServer()->getWorldManager()->getDefaultWorld();
        self::expect($this->observedFallingBlock && $world->getBlock($this->fallingBlockLanding)->getFalconIdentifier() === 'minecraft:air',
            'falling block cancellation');
        self::expect($this->observedEntitySpawn, 'spawn listener saw the entity in its world');
        $query = $this->getServer()->getQueryInformation();
        self::expect(($query->getExtraData()['fixture'] ?? '') === 'yes' &&
            str_contains($query->getLongQuery(), "fixture\0yes\0"), 'query override serialized');
        if($this->testNativeWorld){
            self::expect($this->startupInitBeforeLoad, 'default world init before load');
            self::expect($this->nativeSound && $this->nativeSoundReplacement && $this->nativeSoundSuppressed && $this->nativeParticle,
                'native sound replacement and particle cancellation');
            $effects = $this->getServer()->getPlayerExact('Visitor')?->getEffects();
            $speed = $effects?->get(VanillaEffects::SPEED());
            self::expect($this->nativeCloudReplaced, 'native cloud offered its effects to the player');
            self::expect($speed?->getAmplifier() === 2 && $speed->getDuration() <= 100
                && $speed->getDuration() > 0 && !$speed->isVisible() && $speed->isAmbient()
                && !$effects->has(VanillaEffects::SLOWNESS()), 'player received the replacement cloud effect');
            $this->getLogger()->info('native default world and media passed');
            $this->getLogger()->info('native cloud replacement reached the player');
        }
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
        if($this->checkingNativeMedia){
            $packet = $event->getSound()->encode($event->getPosition())[0] ?? null;
            if($packet instanceof PlaySoundPacket && $packet->soundName === 'random.pop'){
                $this->nativeSound = count($event->getRecipients()) === 1
                    && $event->getRecipients()[0]->getName() === 'Visitor';
                $event->setSound(new class implements Sound{
                    public function encode(Vector3 $pos) : array{
                        return [PlaySoundPacket::create('random.orb', $pos->x, $pos->y, $pos->z, 1.0, 1.0)];
                    }
                });
            }elseif($packet instanceof PlaySoundPacket && $packet->soundName === 'random.bow'){
                $event->setSound(new class(function() : void{
                    $this->nativeSoundSuppressed = true;
                }) implements Sound{
                    public function __construct(private \Closure $encode){}
                    public function encode(Vector3 $pos) : array{
                        ($this->encode)();
                        return [];
                    }
                });
            }
            return;
        }
        $this->observedSound = $event->getRecipients() === [];
        $event->cancel();
    }

    public function onPacket(DataPacketSendEvent $event) : void{
        if(!$this->checkingNativeMedia){
            return;
        }
        foreach($event->getPackets() as $packet){
            if($packet instanceof PlaySoundPacket && $packet->soundName === 'random.orb'){
                $this->nativeSoundReplacement = count($event->getTargets()) === 1
                    && $event->getTargets()[0]->getPlayer()?->getName() === 'Visitor';
            }
        }
    }

    public function onParticle(WorldParticleEvent $event) : void{
        if($this->checkingNativeMedia){
            $packet = $event->getParticle()->encode($event->getPosition())[0] ?? null;
            $this->nativeParticle = $packet instanceof SpawnParticleEffectPacket;
            $event->cancel();
            return;
        }
        $this->observedParticle = $event->getRecipients() === [];
        $event->cancel();
    }

    public function onCloudApply(AreaEffectCloudApplyEvent $event) : void{
        if(!$this->testNativeWorld || $this->nativeCloudReplaced
            || !in_array($this->getServer()->getPlayerExact('Visitor'), $event->getAffectedEntities(), true)){
            return;
        }
        $effects = $event->getEntity()->getEffects();
        self::expect($effects->has(VanillaEffects::SLOWNESS()), 'native cloud started with slowness');
        $effects->clear();
        $effects->add(new EffectInstance(VanillaEffects::SPEED(), 100, 2, false, true));
        $this->nativeCloudReplaced = true;
    }

    public function onMemory(LowMemoryEvent $event) : void{
        $this->observedLowMemory = $event->getMemory() === 2_000_000 && $event->getMemoryLimit() === 1_000_000
            && $event->isGlobal() && $event->getTriggerCount() === 7;
    }

    public function onUpdateNotify(UpdateNotifyEvent $event) : void{
        $checker = $event->getUpdater();
        $release = $checker->getUpdateInfo();
        $expected = getenv('PMMP_COMPAT_TEST_UPDATE_TAG');
        if($expected !== false){
            self::expect($checker->hasUpdate() && $release !== null &&
                $release->base_version === ltrim($expected, 'vV') && $release->date > 0 &&
                str_contains($release->details_url, '/releases/tag/' . $expected), 'live Falcon update metadata');
            $this->getLogger()->info("Falcon update notification delivered: $expected");
        }
    }

    public function onQuery(QueryRegenerateEvent $event) : void{
        $event->getQueryInfo()->setExtraData(['fixture' => 'yes']);
    }

    public function onBlockUpdate(BlockUpdateEvent $event) : void{
        if(!$this->observedNeighbour){
            $this->observedNeighbour = true;
            $event->cancel();
        }
    }

    public function onFallingBlockLand(EntityBlockChangeEvent $event) : void{
        if($this->fallingBlockLanding !== null && $event->getEntity() instanceof FallingBlock &&
            $event->getBlock()->getPosition()->equals($this->fallingBlockLanding)){
            $this->observedFallingBlock = $event->getTo()->getFalconIdentifier() === 'minecraft:sand';
            $event->cancel();
        }
    }

    public function onEntitySpawn(EntitySpawnEvent $event) : void{
        $entity = $event->getEntity();
        if($entity instanceof Zombie && $this->expectedEntitySpawn?->equals($entity->getPosition()) === true){
            $this->observedEntitySpawn = $entity->getWorld() === $this->getServer()->getWorldManager()->getDefaultWorld();
        }
    }

    public function onWorldInit(WorldInitEvent $event) : void{
        if($event->getWorld() === $this->getServer()->getWorldManager()->getDefaultWorld()){
            $this->startupInitSeen = true;
        }
        if($event->getWorld()->getFolderName() === 'compat-events-fixture'){
            $this->observedInit = true;
        }
    }

    public function onWorldLoad(WorldLoadEvent $event) : void{
        if($event->getWorld() === $this->getServer()->getWorldManager()->getDefaultWorld()){
            $this->startupInitBeforeLoad = $this->startupInitSeen;
        }
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
