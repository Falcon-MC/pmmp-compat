<?php

declare(strict_types=1);

namespace menagerie;

use pocketmine\entity\EntityDataHelper;
use pocketmine\entity\EntityFactory;
use pocketmine\entity\Human;
use pocketmine\entity\Location;
use pocketmine\entity\Skin;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntitySpawnEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\ClosureTask;
use pocketmine\world\World;

// entities a plugin defines: they spawn, tick, move, take hits and despawn
final class Main extends PluginBase implements Listener{
    private const SPAWN_Y = 300.0;

    private int $passed = 0;
    private int $total = 0;
    private int $spawnEvents = 0;
    private int $damageEvents = 0;
    private bool $started = false;

    private function check(string $what, bool $ok) : void{
        ++$this->total;
        if($ok){
            ++$this->passed;
        }else{
            $this->getLogger()->error("fixture: Menagerie check failed: $what");
        }
    }

    protected function onEnable() : void{
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $factory = EntityFactory::getInstance();
        $factory->register(Dummy::class, function(World $world, CompoundTag $nbt) : Dummy{
            return new Dummy(EntityDataHelper::parseLocation($nbt, $world), $nbt);
        }, ["Dummy", "menagerie:dummy"]);
        $factory->register(Puppet::class, function(World $world, CompoundTag $nbt) : Puppet{
            return new Puppet(EntityDataHelper::parseLocation($nbt, $world), Human::parseSkinNBT($nbt), $nbt);
        }, ["Puppet"]);
        $this->getLogger()->info("fixture: Menagerie registered " . ($factory->isRegistered(Dummy::class) ? "Dummy" : "nothing"));
    }

    public function onSpawn(EntitySpawnEvent $event) : void{
        if($event->getEntity() instanceof Dummy){
            ++$this->spawnEvents;
        }
    }

    public function onDamage(EntityDamageEvent $event) : void{
        if($event->getEntity() instanceof Dummy){
            ++$this->damageEvents;
        }
    }

    public function onJoin(PlayerJoinEvent $event) : void{
        if($this->started){
            return;
        }
        $this->started = true;
        $world = $event->getPlayer()->getWorld();
        $this->getScheduler()->scheduleDelayedTask(new ClosureTask(fn() => $this->spawn($world)), 5);
    }

    private function spawn(World $world) : void{
        // Keep the damage and movement checks clear of randomly generated terrain.
        $dummy = new Dummy(new Location(8.5, self::SPAWN_Y, 8.5, $world, 90.0, 0.0));
        $dummy->setNameTag("Bob");
        $dummy->spawnToAll();
        $id = $dummy->getId();
        $this->check("a runtime id of Falcon's", $id > 0 && $id < (1 << 40));
        $this->check("found by id", $this->getServer()->getWorldManager()->findEntity($id) === $dummy);
        $this->check("name tag", $dummy->getNameTag() === "Bob");
        $dummy->setScale(2.0);
        $this->check("scale", $dummy->getScale() === 2.0 && abs($dummy->getSize()->getHeight() - 3.9) < 0.001);
        $this->check("health", $dummy->getHealth() === 20.0 && $dummy->getMaxHealth() === 20 && $dummy->isAlive());

        $puppet = new Puppet(new Location(10.5, self::SPAWN_Y, 8.5, $world, 0.0, 0.0), new Skin("Standard_Custom", str_repeat("\xff", 64 * 64 * 4)));
        $puppet->setNameTag("Alice");
        $puppet->setNameTagAlwaysVisible();
        $puppet->spawnToAll();
        $saved = $puppet->saveNBT();
        $this->check("human", $puppet->getSkin()->getSkinId() === "Standard_Custom" && $saved->getCompoundTag("Skin") !== null
            && $puppet->getName() === "Alice" && $puppet->isNameTagAlwaysVisible());

        $copy = EntityFactory::getInstance()->createFromData($world, $dummy->saveNBT()->setString("Mood", "grumpy"));
        $this->check("made from saved data", $copy instanceof Dummy && $copy->getNameTag() === "Bob" && $copy->mood === "grumpy"
            && $copy->getId() !== $id);
        $copy?->close();

        $this->getScheduler()->scheduleDelayedTask(new ClosureTask(fn() => $this->afterTicks($dummy, $puppet, $world)), 20);
    }

    private function afterTicks(Dummy $dummy, Puppet $puppet, World $world) : void{
        $this->getLogger()->info("fixture: Menagerie Dummy ticked " . ($dummy->ticks >= 15 ? "on its own" : "only " . $dummy->ticks . " times"));
        // the copy closed before its first tick: only the first fired it
        $this->check("spawn event", $this->spawnEvents === 1);
        $this->check("no gravity", abs($dummy->getPosition()->y - self::SPAWN_Y) < 0.001);

        $dummy->teleport(new Vector3(12.5, self::SPAWN_Y, 12.5));
        $position = $dummy->getPosition();
        $this->check("teleport", abs($position->x - 12.5) < 0.001 && abs($position->z - 12.5) < 0.001);

        $dummy->attack(new EntityDamageEvent($dummy, EntityDamageEvent::CAUSE_MAGIC, 5));
        $this->check("hurt by a plugin (health {$dummy->getHealth()}, {$this->damageEvents} damage events, causes "
            . implode(", ", $dummy->hits) . ")", $dummy->getHealth() === 15.0 && $this->damageEvents === 1
            && $dummy->hits === [EntityDamageEvent::CAUSE_MAGIC]);

        $dummy->setMotion(new Vector3(0.5, 0.0, 0.0));
        $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($dummy, $puppet, $world) : void{
            $position = $dummy->getPosition();
            $this->check("moved by its motion", $position->x > 13.0);
            // Falcon's stand-in followed: the world's own queries find the entity where PHP moved it
            $near = $world->getNearbyEntities(new AxisAlignedBB($position->x - 1, self::SPAWN_Y - 1, $position->z - 1,
                $position->x + 1, self::SPAWN_Y + 2, $position->z + 1));
            $this->check("stand-in followed", in_array($dummy, $near, true));
            $dummy->setMotion(Vector3::zero());
            // an explosion of Falcon's hurts the stand-in: the hit reaches attack()
            $world->createExplosion($position, 1.0, false);
            $this->getScheduler()->scheduleDelayedTask(new ClosureTask(fn() => $this->afterExplosion($dummy, $puppet)), 2);
        }), 5);
    }

    private function afterExplosion(Dummy $dummy, Puppet $puppet) : void{
        $hits = count($dummy->hits);
        $this->getLogger()->info("fixture: Menagerie Dummy " . ($hits > 1 && $dummy->getHealth() < 15.0 ? "was hurt by Falcon's explosion" : "missed Falcon's explosion, $hits hits"));
        $id = $dummy->getId();
        $dummy->flagForDespawn();
        $puppet->close();
        $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($dummy, $puppet, $id) : void{
            $this->check("despawned", $dummy->isClosed() && $puppet->isClosed()
                && $this->getServer()->getWorldManager()->findEntity($id) === null);
            $this->getLogger()->info("fixture: Menagerie {$this->passed} of {$this->total} checks passed");
        }), 2);
    }
}
