<?php

declare(strict_types=1);

namespace pocketmine\command\defaults;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\command\utils\InvalidCommandSyntaxException;
use pocketmine\player\Player;

/** The base of PocketMine-MP's built-in commands, which plugins extend for commands of the same kind */
abstract class VanillaCommand extends Command{
    public const MAX_COORD = 30000000;
    public const MIN_COORD = -30000000;

    protected function fetchPermittedPlayerTarget(CommandSender $sender, ?string $target, string $selfPermission, string $otherPermission) : ?Player{
        if($target !== null){
            $player = $sender->getServer()->getPlayerByPrefix($target);
        }elseif($sender instanceof Player){
            $player = $sender;
        }else{
            throw new InvalidCommandSyntaxException();
        }
        if($player === null){
            $sender->sendMessage(\pocketmine\lang\KnownTranslationFactory::commands_generic_player_notFound()->prefix(\pocketmine\utils\TextFormat::RED));
            return null;
        }
        if(($player === $sender && $this->testPermission($sender, $selfPermission)) || ($player !== $sender && $this->testPermission($sender, $otherPermission))){
            return $player;
        }
        return null;
    }

    protected function getInteger(CommandSender $sender, string $value, int $min = self::MIN_COORD, int $max = self::MAX_COORD) : int{
        $i = (int) $value;
        return max($min, min($max, $i));
    }

    protected function getRelativeDouble(float $original, CommandSender $sender, string $input, float $min = self::MIN_COORD, float $max = self::MAX_COORD) : float{
        if($input[0] === "~"){
            $value = $this->getDouble($sender, substr($input, 1));
            return $original + $value;
        }
        return $this->getDouble($sender, $input, $min, $max);
    }

    protected function getDouble(CommandSender $sender, string $value, float $min = self::MIN_COORD, float $max = self::MAX_COORD) : float{
        $i = (float) $value;
        return max($min, min($max, $i));
    }

    protected function getBoundedInt(CommandSender $sender, string $input, int $min, int $max) : ?int{
        if(!is_numeric($input)){
            throw new InvalidCommandSyntaxException();
        }
        $v = (int) $input;
        if($v > $max || $v < $min){
            return null;
        }
        return $v;
    }
}
