<?php

declare(strict_types=1);

namespace pocketmine\network\mcpe;

use pmmpcompat\Packets;
use pocketmine\event\server\DataPacketSendEvent;
use pocketmine\network\mcpe\protocol\ClientboundCloseFormPacket;
use pocketmine\network\mcpe\protocol\ClientboundPacket;
use pocketmine\network\mcpe\protocol\ContainerClosePacket;
use pocketmine\network\mcpe\protocol\ContainerOpenPacket;
use pocketmine\player\Player;

/**
 * A player's connection, as plugins reach it for packets: Player::getNetworkSession().
 *
 * Packets go out through Falcon, which writes their header and batches them; see Packets for the game version.
 */
class NetworkSession{
    private InventoryManager $invManager;

    public function __construct(
        private Player $player
    ){
        $this->invManager = new InventoryManager($player);
    }

    public function getInvManager() : ?InventoryManager{
        return $this->player->isOnline() ? $this->invManager : null;
    }

    public function getPlayer() : ?Player{
        return $this->player->isOnline() ? $this->player : null;
    }

    public function isConnected() : bool{
        return $this->player->isOnline();
    }

    public function getPing() : int{
        return $this->player->getPing() ?? 0;
    }

    public function getDisplayName() : string{
        return $this->player->getName();
    }

    /** the address without its port */
    public function getIp() : string{
        $address = $this->player->isOnline() ? falcon_player_address($this->player->getHandle()) : "";
        $colon = strrpos($address, ":");
        return $colon === false ? $address : trim(substr($address, 0, $colon), "[]");
    }

    public function getPort() : int{
        $address = $this->player->isOnline() ? falcon_player_address($this->player->getHandle()) : "";
        $colon = strrpos($address, ":");
        return $colon === false ? 0 : (int) substr($address, $colon + 1);
    }

    /** the protocol the client speaks, which may be newer than BedrockProtocol's */
    public function getProtocolId() : int{
        return $this->player->isOnline() ? falcon_player_protocol($this->player->getHandle()) : 0;
    }

    /** @param bool $immediate kept for PocketMine-MP's signature: Falcon decides when it flushes */
    public function sendDataPacket(ClientboundPacket $packet, bool $immediate = false) : bool{
        if(!$this->player->isOnline()){
            return false;
        }
        if(!DataPacketSendEvent::hasHandlers()){
            return $this->sendWithoutEvent($packet);
        }
        $event = new DataPacketSendEvent([$this], [$this->withWindowId($packet)]);
        $event->call();
        if($event->isCancelled()){
            return false;
        }
        $sent = false;
        foreach($event->getPackets() as $sending){
            $sent = $this->sendWithoutEvent($sending) || $sent;
        }
        return $sent;
    }

    /** @internal a packet DataPacketSendEvent already saw */
    public function sendWithoutEvent(ClientboundPacket $packet) : bool{
        if(!$this->player->isOnline()){
            return false;
        }
        [$id, $payload] = Packets::encode($this->withWindowId($packet));
        return falcon_player_send_packet($this->player->getHandle(), $id, $payload);
    }

    private function withWindowId(ClientboundPacket $packet) : ClientboundPacket{
        if($packet instanceof ContainerOpenPacket || ($packet instanceof ContainerClosePacket && !$packet->server)){
            $windowId = $this->invManager->resolveWindowId($packet->windowId);
            if($windowId !== $packet->windowId){
                $packet = clone $packet;
                $packet->windowId = $windowId;
            }
        }
        return $packet;
    }

    public function getEntityEventBroadcaster() : EntityEventBroadcaster{
        return StandardEntityEventBroadcaster::shared();
    }

    public function getTypeConverter() : \pocketmine\network\mcpe\convert\TypeConverter{
        return \pocketmine\network\mcpe\convert\TypeConverter::getInstance();
    }

    /**
     * the commands the player may run, sent again: Falcon builds the list, and DataPacketSendEvent sees it go out as
     * PocketMine-MP's own AvailableCommandsPacket
     */
    public function syncAvailableCommands() : void{
        if($this->player->isOnline()){
            falcon_player_send_commands($this->player->getHandle());
        }
    }

    /** @internal */
    public function onCloseAllForms() : void{
        $this->sendDataPacket(ClientboundCloseFormPacket::create());
    }
}
