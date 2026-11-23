<?php

declare(strict_types=1);

namespace commander;

use pocketmine\event\EventPriority;
use pocketmine\event\server\DataPacketSendEvent;
use pocketmine\network\mcpe\protocol\AvailableCommandsPacket;
use pocketmine\network\mcpe\protocol\serializer\AvailableCommandsPacketAssembler;
use pocketmine\network\mcpe\protocol\serializer\AvailableCommandsPacketDisassembler;
use pocketmine\plugin\Plugin;
use pocketmine\Server;

final class PacketHooker{
    private bool $resending = false;

    public function register(Plugin $plugin) : void{
        $manager = $plugin->getServer()->getPluginManager();
        $manager->registerEvent(DataPacketSendEvent::class, $this->onSend(...), EventPriority::NORMAL, $plugin);
    }

    private function onSend(DataPacketSendEvent $event) : void{
        if($this->resending){
            return;
        }
        foreach($event->getPackets() as $packet){
            if(!$packet instanceof AvailableCommandsPacket){
                continue;
            }
            $map = Server::getInstance()->getCommandMap();
            $data = AvailableCommandsPacketDisassembler::disassemble($packet);
            foreach($data->commandData as $command){
                $registered = $map->getCommand($command->getName());
                if($registered instanceof ShopCommand){
                    $command->overloads = $registered->getNetworkOverloads();
                }
            }
            // Commando resends its rebuilt list through the session and cancels the original.
            $this->resending = true;
            try{
                $replacement = AvailableCommandsPacketAssembler::assemble($data->commandData, $data->unusedHardEnums, $data->unusedSoftEnums);
                foreach($event->getTargets() as $target){
                    $target->sendDataPacket($replacement);
                }
            }finally{
                $this->resending = false;
            }
            $event->cancel();
            return;
        }
    }
}
