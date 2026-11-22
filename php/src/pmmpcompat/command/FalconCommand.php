<?php

declare(strict_types=1);

namespace pmmpcompat\command;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;

/**
 * One of PocketMine-MP's own commands, which Falcon has: plugins that look one up, to take its place or to run it,
 * get this, and running it runs Falcon's.
 */
final class FalconCommand extends Command{
    /** PocketMine-MP's built-in commands, which Falcon answers to under the same names */
    public const NAMES = [
        "ban", "ban-ip", "banlist", "clear", "defaultgamemode", "deop", "difficulty", "dumpmemory", "effect", "enchant",
        "gamemode", "gc", "give", "help", "kick", "kill", "list", "me", "op", "pardon", "pardon-ip", "particle",
        "plugins", "save-all", "save-off", "save-on", "say", "seed", "setworldspawn", "spawnpoint", "status", "stop",
        "teleport", "tell", "time", "timings", "title", "transferserver", "version", "whitelist", "xp",
    ];

    public const ALIASES = ["tp" => "teleport", "msg" => "tell", "w" => "tell", "pl" => "plugins", "ver" => "version",
        "about" => "version", "?" => "help"];

    public function __construct(string $name){
        parent::__construct($name, "", "/$name", array_keys(array_filter(self::ALIASES, static fn(string $to) => $to === $name)));
        $this->setPermission("pocketmine.command." . str_replace("-", "", $name));
    }

    public function execute(CommandSender $sender, string $commandLabel, array $args){
        $line = trim($commandLabel . " " . implode(" ", $args));
        if($sender instanceof Player){
            return $sender->isOnline() && falcon_player_execute_command($sender->getHandle(), $line);
        }
        return falcon_execute_command($line);
    }
}
