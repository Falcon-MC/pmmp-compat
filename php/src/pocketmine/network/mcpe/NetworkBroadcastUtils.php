<?php

declare(strict_types=1);

namespace pocketmine\network\mcpe;

use pocketmine\network\mcpe\protocol\ClientboundPacket;
use pocketmine\player\Player;

/** Packets to several players at once; Falcon batches what goes to each */
final class NetworkBroadcastUtils{
    private function __construct(){
    }

    /**
     * @param Player[]            $recipients
     * @param ClientboundPacket[] $packets
     */
    public static function broadcastPackets(array $recipients, array $packets) : bool{
        if(count($packets) === 0){
            throw new \InvalidArgumentException("Cannot broadcast empty list of packets");
        }
        $sent = false;
        foreach($recipients as $player){
            if(!$player->isConnected()){
                continue;
            }
            foreach($packets as $packet){
                $sent = $player->getNetworkSession()->sendDataPacket($packet) || $sent;
            }
        }
        return $sent;
    }

    /**
     * @param Player[] $recipients
     * @phpstan-param \Closure(EntityEventBroadcaster, array<int, NetworkSession>) : void $callback
     */
    public static function broadcastEntityEvent(array $recipients, \Closure $callback) : void{
        $sessions = [];
        foreach($recipients as $player){
            if($player->isConnected()){
                $sessions[] = $player->getNetworkSession();
            }
        }
        if(count($sessions) > 0){
            $callback(StandardEntityEventBroadcaster::shared(), $sessions);
        }
    }
}
