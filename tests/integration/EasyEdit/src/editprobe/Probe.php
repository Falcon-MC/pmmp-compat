<?php

declare(strict_types=1);

namespace editprobe;

use pmmpcompat\world\ChunkEdit;
use pocketmine\block\VanillaBlocks;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\ClosureTask;
use pocketmine\world\format\io\FastChunkSerializer;
use pocketmine\world\World;

final class Probe extends PluginBase implements Listener{
    protected function onEnable() : void{
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
    }

    public function onJoin(PlayerJoinEvent $event) : void{
        $player = $event->getPlayer();
        $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($player) : void{
            $server = $this->getServer();
            $world = $player->getWorld();
            $player->setOp(true);
            for($x = 2; $x <= 3; ++$x){
                for($z = 2; $z <= 3; ++$z){
                    $world->setBlockAt($x, 250, $z, VanillaBlocks::OBSIDIAN());
                }
            }
            $world->setBlockAt(4, 250, 2, VanillaBlocks::DIAMOND());
            $this->verifySnapshotChecks($world);

            foreach(['/pos1 2 250 2', '/pos2 3 250 3', '/set gold_block'] as $command){
                self::require($server->dispatchCommand($player, $command), 'Missing EasyEdit command ' . $command);
            }
            $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($world, $player, $server) : void{
                $this->verifyRegion($world, 'minecraft:gold_block');
                $this->getLogger()->info('EasyEdit selection and fill passed');
                self::require($server->dispatchCommand($player, '/undo'), 'Missing EasyEdit undo command');
                $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($world) : void{
                    $this->verifyRegion($world, 'minecraft:obsidian');
                    $this->getLogger()->info('EasyEdit undo passed');
                }), 50);
            }), 4);
        }), 2);
    }

    private function verifySnapshotChecks(World $world) : void{
        $stateId = $world->getBlockAt(2, 250, 2)->getStateId();
        self::require($stateId > 0x7fffffff, 'Fixture state ID does not set the high bit');
        $chunk = ChunkEdit::snapshot($world, 0, 0);
        $chunk = FastChunkSerializer::deserializeTerrain(FastChunkSerializer::serializeTerrain($chunk));
        self::require($chunk->getBlockStateId(2, 250, 2) === $stateId, 'High-bit state failed to round-trip');
        $gold = VanillaBlocks::GOLD()->getStateId();
        $chunk->setBlockStateId(2, 250, 2, $gold);
        $chunk->setBlockStateId(3, 250, 2, $gold);
        $world->setBlockAt(3, 250, 2, VanillaBlocks::DIRT());
        self::expectRejection($world, $chunk, 'changed while the edit was running');
        self::require($world->getBlockAt(2, 250, 2)->getStateId() === $stateId, 'Stale batch partially applied');
        $world->setBlockAt(3, 250, 2, VanillaBlocks::OBSIDIAN());

        $chunk->setBlockStateId(3, 250, 2, VanillaBlocks::CHEST()->getStateId());
        self::expectRejection($world, $chunk, 'cannot be edited');
        self::require($world->getBlockAt(2, 250, 2)->getStateId() === $stateId, 'Block entity batch partially applied');
        $this->getLogger()->info('Terrain snapshot and rejection checks passed');
    }

    private static function expectRejection(World $world, \pocketmine\world\format\Chunk $chunk, string $message) : void{
        try{
            ChunkEdit::apply($world, [World::chunkHash(0, 0) => $chunk]);
        }catch(\RuntimeException $failure){
            self::require(str_contains($failure->getMessage(), $message), $failure->getMessage());
            return;
        }
        throw new \RuntimeException('Expected editor rejection: ' . $message);
    }

    private function verifyRegion(World $world, string $wanted) : void{
        for($x = 2; $x <= 3; ++$x){
            for($z = 2; $z <= 3; ++$z){
                self::require($world->getBlockAt($x, 250, $z)->getFalconIdentifier() === $wanted,
                    "Expected $wanted at $x,250,$z");
            }
        }
        self::require($world->getBlockAt(4, 250, 2)->getFalconIdentifier() === 'minecraft:diamond_block',
            'Edit changed an adjacent untouched block');
    }

    private static function require(bool $condition, string $message) : void{
        if(!$condition){
            throw new \RuntimeException($message);
        }
    }
}
