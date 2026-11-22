<?php

declare(strict_types=1);

namespace ticker;

use pocketmine\event\Listener;
use pocketmine\event\player\PlayerGameModeChangeEvent;
use pocketmine\event\player\PlayerLoginEvent;
use pocketmine\event\server\CommandEvent;
use pocketmine\player\GameMode;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\CancelTaskException;
use pocketmine\scheduler\ClosureTask;
use pocketmine\utils\Binary;
use pocketmine\utils\Internet;
use pocketmine\utils\Random;
use pocketmine\VersionInfo;

// the scheduler, async tasks and the small utilities plugins lean on
final class Main extends PluginBase implements Listener{
    protected function onEnable() : void{
        $logger = $this->getLogger();
        $this->getServer()->getPluginManager()->registerEvents($this, $this);

        $logger->info("fixture: Ticker singleton " . (Registry::getInstance() === Registry::getInstance() ? "kept" : "lost"));
        $logger->info("fixture: Ticker random " . ((new Random(42))->nextInt() === (new Random(42))->nextInt() ? "repeats" : "differs"));
        $logger->info("fixture: Ticker binary " . (Binary::readInt(Binary::writeInt(-123456)) === -123456 ? "round trips" : "breaks"));
        $logger->info("fixture: Ticker curl " . (extension_loaded("curl") && class_exists(Internet::class) ? "ready" : "missing")
            . " for " . VersionInfo::NAME . " " . VersionInfo::VERSION()->getFullVersion());

        $scheduler = $this->getScheduler();
        $scheduler->scheduleDelayedTask(new ClosureTask(function() use ($logger) : void{
            $logger->info("fixture: Ticker delayed task ran at tick " . $this->getServer()->getTick());
        }), 5);
        $runs = 0;
        $scheduler->scheduleRepeatingTask(new ClosureTask(function() use (&$runs, $logger) : void{
            if(++$runs === 3){
                $logger->info("fixture: Ticker repeating task stopped after $runs runs");
                throw new CancelTaskException();
            }
        }), 2);
        $scheduler->scheduleDelayedTask(new ClosureTask(function() : void{
            throw new \RuntimeException("a task that fails on purpose");
        }), 3);

        $pool = $this->getServer()->getAsyncPool();
        $pool->submitTask(new SumTask([1, 2, 3], $logger));
        $pool->submitTask(new FailingTask($logger));
    }

    public function onCommandEvent(CommandEvent $event) : void{
        $this->getLogger()->info("fixture: Ticker saw command \"" . $event->getCommand() . "\" from " . $event->getSender()->getName());
        if(str_starts_with($event->getCommand(), "blocked")){
            $event->cancel();
        }
    }

    public function onLogin(PlayerLoginEvent $event) : void{
        $this->getLogger()->info("fixture: Ticker saw " . $event->getPlayer()->getName() . " log in");
    }

    public function onGameMode(PlayerGameModeChangeEvent $event) : void{
        $player = $event->getPlayer();
        $mode = $event->getNewGamemode();
        $this->getLogger()->info("fixture: Ticker saw " . $player->getName() . " ask for " . $mode->name);
        if($mode === GameMode::ADVENTURE){
            $event->cancel();
        }
        $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($player) : void{
            $this->getLogger()->info("fixture: Ticker sees " . $player->getName() . " in " . $player->getGamemode()->name);
        }), 1);
    }
}
