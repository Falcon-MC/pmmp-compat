<?php

declare(strict_types=1);

namespace greeter;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerChatEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\player\chat\LegacyRawChatFormatter;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;

// what plugins do with players: events at their priorities, commands from plugin.yml, the server's player list
final class Main extends PluginBase implements Listener{
    protected function onEnable() : void{
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->getLogger()->info("fixture: Greeter listens, greet is " . ($this->getCommand("greet") !== null ? "registered" : "missing"));
    }

    public function onJoin(PlayerJoinEvent $event) : void{
        $player = $event->getPlayer();
        $server = $this->getServer();
        $this->getLogger()->info("fixture: Greeter saw " . $player->getName() . " join with \"" . $event->getJoinMessage() . "\"");
        $this->getLogger()->info("fixture: Greeter finds " . ($server->getPlayerExact($player->getName()) === $player ? "the same player" : "another player")
            . ", " . count($server->getOnlinePlayers()) . " online");
        $this->getLogger()->info("fixture: Greeter permissions: greet " . ($player->hasPermission("greeter.command.greet") ? "yes" : "no")
            . ", secret " . ($player->hasPermission("greeter.command.secret") ? "yes" : "no"));
        $event->setJoinMessage("§aWelcome " . $player->getName());
    }

    /**
     * @priority MONITOR
     */
    public function onJoinMonitor(PlayerJoinEvent $event) : void{
        $this->getLogger()->info("fixture: Greeter monitor sees \"" . $event->getJoinMessage() . "\"");
    }

    /**
     * @priority LOWEST
     */
    public function onChatFirst(PlayerChatEvent $event) : void{
        if(str_contains($event->getMessage(), "secret")){
            $event->cancel();
        }
    }

    public function onChat(PlayerChatEvent $event) : void{
        $this->getLogger()->info("fixture: Greeter heard \"" . $event->getMessage() . "\"");
        $event->setMessage($event->getMessage() . "!");
        $event->setFormatter(new LegacyRawChatFormatter("[{%0}] {%1}"));
    }

    /**
     * @priority HIGHEST
     * @handleCancelled
     */
    public function onChatCancelled(PlayerChatEvent $event) : void{
        if($event->isCancelled()){
            $this->getLogger()->info("fixture: Greeter saw the cancelled \"" . $event->getMessage() . "\"");
        }
    }

    public function onQuit(PlayerQuitEvent $event) : void{
        $this->getLogger()->info("fixture: Greeter saw " . $event->getPlayer()->getName() . " leave: " . $event->getQuitReason());
        $event->setQuitMessage("§c" . $event->getPlayer()->getName() . " left us");
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args) : bool{
        if($command->getName() === "secret"){
            $this->getLogger()->info("fixture: Greeter ran secret for " . $sender->getName());
            return true;
        }
        if(count($args) === 0){
            return false;
        }
        if($args[0] === "chat" && $sender instanceof Player){
            $sender->chat("said through Player::chat");
            return true;
        }
        $sender->sendMessage("Hello " . implode(" ", $args));
        $this->getLogger()->info("fixture: Greeter greeted " . $sender->getName() . " as /$label " . implode(" ", $args));
        return true;
    }
}
