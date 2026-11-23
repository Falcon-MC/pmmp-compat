<?php

declare(strict_types=1);

namespace commander;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\network\mcpe\protocol\AvailableCommandsPacket;
use pocketmine\network\mcpe\protocol\types\command\CommandEnum;
use pocketmine\network\mcpe\protocol\types\command\CommandOverload;
use pocketmine\network\mcpe\protocol\types\command\CommandParameter;
use pocketmine\network\mcpe\protocol\types\command\CommandSoftEnum;

final class ShopCommand extends Command{
    public function __construct(private CommandSoftEnum $items){
        parent::__construct("shop", "Buys and sells", "/shop <buy|sell>", ["store"]);
    }

    /** @return CommandOverload[] */
    public function getNetworkOverloads() : array{
        return [
            new CommandOverload(false, [
                CommandParameter::enum("buy", new CommandEnum("buy", ["buy"]), 0),
                CommandParameter::enum("item", new CommandEnum("ShopBuyItems", ["apple", "bread"]), 0),
                CommandParameter::standard("amount", AvailableCommandsPacket::ARG_TYPE_INT, 0, true),
            ]),
            new CommandOverload(false, [
                CommandParameter::enum("sell", new CommandEnum("sell", ["sell"]), 0),
                CommandParameter::softEnum("item", $this->items, 0),
            ]),
            new CommandOverload(false, [
                CommandParameter::standard("player", AvailableCommandsPacket::ARG_TYPE_TARGET, 0, true),
            ]),
        ];
    }

    public function execute(CommandSender $sender, string $commandLabel, array $args){
        $sender->sendMessage("ran " . $this->getName() . " " . implode(" ", $args));
    }
}
