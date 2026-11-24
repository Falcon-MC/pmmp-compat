<?php

declare(strict_types=1);

namespace compatpoggitplayers;

use pmmpcompat\world\FalconFlatGenerator;
use pocketmine\block\Block;
use pocketmine\entity\Location;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityMotionEvent;
use pocketmine\event\entity\EntityTeleportEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerBedEnterEvent;
use pocketmine\event\player\PlayerChatEvent;
use pocketmine\event\player\PlayerEmoteEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerJumpEvent;
use pocketmine\event\player\PlayerMoveEvent;
use pocketmine\event\player\PlayerToggleFlightEvent;
use pocketmine\event\player\PlayerToggleGlideEvent;
use pocketmine\event\player\PlayerToggleSneakEvent;
use pocketmine\event\player\PlayerToggleSwimEvent;
use pocketmine\event\plugin\PluginDisableEvent;
use pocketmine\event\plugin\PluginEnableEvent;
use pocketmine\item\VanillaItems;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\ClosureTask;
use pocketmine\world\World;
use pocketmine\world\WorldCreationOptions;
use pocketmine\world\Position;

final class Main extends PluginBase implements Listener{
    private const ATTACKER = 'GameplayAttacker';
    private const TARGET = 'GameplayTarget';
    private int $toggleRequests = 0;
    private bool $motionCancelled = false;
    private bool $teleportCancelled = false;
    private ?World $redirectWorld = null;
    private bool $destinationObserved = false;

    protected function onEnable() : void{
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
    }

    public function onJoin(PlayerJoinEvent $event) : void{
        $player = $event->getPlayer();
        if($player->getName() === self::ATTACKER){
            $player->setOp(true);
            $player->getArmorInventory()->setChestplate(VanillaItems::ELYTRA());
            return;
        }
        $attacker = $this->getServer()->getPlayerExact(self::ATTACKER);
        if($player->getName() !== self::TARGET || $attacker === null){
            return;
        }
        $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($attacker, $player) : void{
            $position = $attacker->getPosition();
            $player->teleport($position->add(0, 0, 1.5));
            $originalMotion = $attacker->getMotion();
            self::require(!$attacker->setMotion(new Vector3(0, 0.333, 0)), 'Motion event did not cancel');
            self::require($this->motionCancelled && $attacker->getMotion()->distanceSquared($originalMotion) < 0.001, 'Cancelled motion applied');
            self::require($attacker->setMotion(new Vector3(0, 0.15, 0)) && abs($attacker->getMotion()->y - 0.15) < 0.001, 'Native motion not applied');
            $attacker->setMotion(Vector3::zero());
            $speed = $attacker->getMovementSpeed();
            $attacker->setMovementSpeed(0.2);
            self::require(abs($attacker->getMovementSpeed() - 0.2) < 0.001, 'Native movement speed not applied');
            $attacker->setMovementSpeed($speed);
            self::require(!$attacker->teleport($position->add(6, 0, 0)), 'Cancelled teleport returned success');
            self::require($this->teleportCancelled && $attacker->getPosition()->distanceSquared($position) < 0.001, 'Cancelled teleport applied');
            $this->setupBed($attacker);
            $this->getLogger()->info('Player motion, speed and teleport cancellation passed');
            $attacker->sendMessage('gameplay-fixture-pvp-ready');
        }), 65);
    }

    private function setupBed(Player $player) : void{
        $server = $this->getServer();
        $server->dispatchCommand($server->getConsoleSender(), 'gamerule domobspawning false');
        $position = $player->getPosition();
        $foot = new Vector3($position->getFloorX() + 2, $position->getFloorY(), $position->getFloorZ());
        $world = $player->getWorld();
        $world->setTime(World::TIME_MIDNIGHT);
        $world->setBlock($foot, Block::fromFalcon('minecraft:bed', '{"direction":0,"head_piece_bit":false,"occupied_bit":false}'));
        $world->setBlock($foot->add(0, 0, 1), Block::fromFalcon('minecraft:bed', '{"direction":0,"head_piece_bit":true,"occupied_bit":false}'));
        $player->sendMessage("gameplay-fixture-bed {$foot->x} {$foot->y} {$foot->z}");
    }

    public function onEnableEvent(PluginEnableEvent $event) : void{
        if($event->getPlugin() === $this){
            $this->getLogger()->info('PluginEnableEvent passed');
        }
    }

    public function onDisableEvent(PluginDisableEvent $event) : void{
        if($event->getPlugin() === $this){
            $this->getLogger()->info('PluginDisableEvent passed');
        }
    }

    public function onBedEnter(PlayerBedEnterEvent $event) : void{
        if($event->getPlayer()->getName() === self::ATTACKER){
            self::require($event->getBed()->getFalconIdentifier() === 'minecraft:bed', 'Bed event block');
            $event->cancel();
            $event->getPlayer()->sendMessage('gameplay-fixture-bed-cancelled');
        }
    }

    public function onJump(PlayerJumpEvent $event) : void{
        $event->getPlayer()->sendMessage('gameplay-fixture-jump');
    }

    public function onMove(PlayerMoveEvent $event) : void{
        if($event->getPlayer()->getName() === self::ATTACKER && $event->getFrom()->distanceSquared($event->getTo()) > 0.0001){
            $event->cancel();
            $event->getPlayer()->sendMessage('gameplay-fixture-move-cancelled');
        }
    }

    public function onSneak(PlayerToggleSneakEvent $event) : void{
        if(!$event->isSneaking()){
            return;
        }
        self::require($event->isSneakPressed(), 'Sneak key state missing');
        $event->cancel();
        $player = $event->getPlayer();
        $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($player) : void{
            self::require(!$player->isSneaking(), 'Cancelled sneak applied');
            $player->sendMessage('gameplay-fixture-sneak-cancelled');
        }), 1);
    }

    public function onGlide(PlayerToggleGlideEvent $event) : void{
        $event->cancel();
        $event->getPlayer()->sendMessage('gameplay-fixture-glide-cancelled');
    }

    public function onSwim(PlayerToggleSwimEvent $event) : void{
        $event->cancel();
        $event->getPlayer()->sendMessage('gameplay-fixture-swim-cancelled');
    }

    public function onMotion(EntityMotionEvent $event) : void{
        if($event->getEntity() instanceof Player && abs($event->getVector()->y - 0.333) < 0.001){
            $this->motionCancelled = true;
            $event->cancel();
        }
    }

    public function onTeleport(EntityTeleportEvent $event) : void{
        if($event->getEntity() instanceof Player && abs($event->getTo()->x - $event->getFrom()->x - 6) < 0.001){
            $this->teleportCancelled = true;
            $event->cancel();
        }
        if($this->redirectWorld !== null && $event->getTo()->x === 42.5){
            $this->destinationObserved = $event->getTo()->getWorld() === $this->redirectWorld;
            $from = $event->getFrom();
            $event->setTo(Position::fromObject($from->add(0, 1, 0), $from->getWorld()));
        }
    }

    public function onTeleportCheck(PlayerChatEvent $event) : void{
        if($event->getMessage() !== 'gameplay-fixture-teleport'){
            return;
        }
        $event->cancel();
        $player = $event->getPlayer();
        $from = $player->getPosition();
        $worlds = $this->getServer()->getWorldManager();
        $settings = WorldCreationOptions::create()->setGeneratorClass(FalconFlatGenerator::class)->setSeed(42);
        self::require($worlds->generateWorld('teleport-fixture', $settings, false), 'Alternate world did not generate');
        $other = $worlds->getWorldByName('teleport-fixture');
        self::require($other !== null, 'Alternate world did not register');
        $this->redirectWorld = $other;
        $requested = new Location(42.5, 10, 42.5, $other, 63, -18);
        self::require($player->teleport($requested), 'Redirected teleport failed');
        $actual = $player->getLocation();
        self::require($this->destinationObserved && $actual->getWorld() === $from->getWorld()
            && $actual->distanceSquared($from->add(0, 1, 0)) < 0.001, 'Teleport world and position redirect failed');
        self::require(abs($actual->yaw - 63) < 0.001 && abs($actual->pitch + 18) < 0.001, 'Teleport facing not applied');
        $this->redirectWorld = null;
        self::require($player->teleport($requested) && $player->getWorld() === $other, 'Cross-world teleport failed');
        $player->sendMessage('gameplay-fixture-teleport-passed');
    }

    public function onFlight(PlayerToggleFlightEvent $event) : void{
        if($event->getPlayer()->getName() === self::ATTACKER && $event->isFlying() && ++$this->toggleRequests === 1){
            $event->cancel();
            $event->getPlayer()->sendMessage('gameplay-fixture-flight-cancelled');
        }
    }

    public function onEmote(PlayerEmoteEvent $event) : void{
        if($event->getEmoteId() === 'fixture-replace'){
            $event->setEmoteId('fixture-replaced');
        }
    }

    public function onDamage(EntityDamageByEntityEvent $event) : void{
        $player = $event->getDamager();
        $target = $event->getEntity();
        if(!$player instanceof Player || !$target instanceof Player
            || $player->getName() !== self::ATTACKER || $target->getName() !== self::TARGET){
            return;
        }
        $health = $target->getHealth();
        $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($player, $target, $health) : void{
            self::require($target->getHealth() < $health, 'Player attack did not do native damage');
            self::require(!$player->isFlying() && !$player->getAllowFlight(), 'BlazinFly combat did not revoke flight');
            $this->getLogger()->info('Native player damage and BlazinFly combat reset passed');
            $player->sendMessage('gameplay-fixture-combat-passed');
        }), 1);
    }

    private static function require(bool $condition, string $message) : void{
        if(!$condition){
            throw new \RuntimeException($message);
        }
    }
}
