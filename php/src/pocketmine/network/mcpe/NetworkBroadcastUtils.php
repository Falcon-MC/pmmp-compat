<?php

declare(strict_types=1);

namespace pocketmine\network\mcpe;

use pocketmine\event\server\DataPacketSendEvent;
use pocketmine\network\mcpe\protocol\ClientboundPacket;
use pocketmine\player\Player;

/** Packets to several players at once; Falcon batches what goes to each */
final class NetworkBroadcastUtils{
    private function __construct(){
    }

    /**
     * as in PocketMine-MP, one DataPacketSendEvent sees the packets for all the recipients first
     *
     * @param Player[]            $recipients
     * @param ClientboundPacket[] $packets
     */
    public static function broadcastPackets(array $recipients, array $packets) : bool{
        if(count($packets) === 0){
            throw new \InvalidArgumentException("Cannot broadcast empty list of packets");
        }
        $sessions = [];
        foreach($recipients as $player){
            if($player->isConnected()){
                $sessions[] = $player->getNetworkSession();
            }
        }
        if(count($sessions) === 0){
            return false;
        }
        if(DataPacketSendEvent::hasHandlers()){
            $event = new DataPacketSendEvent($sessions, $packets);
            $event->call();
            if($event->isCancelled()){
                return false;
            }
            $sessions = $event->getTargets();
            $packets = $event->getPackets();
        }
        $sent = false;
        foreach($sessions as $session){
            foreach($packets as $packet){
                $sent = $session->sendWithoutEvent($packet) || $sent;
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
