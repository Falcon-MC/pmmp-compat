<?php

declare(strict_types=1);

namespace pocketmine\network\mcpe;

use pmmpcompat\Packets;
use pocketmine\event\server\DataPacketSendEvent;
use pocketmine\network\mcpe\protocol\ClientboundCloseFormPacket;
use pocketmine\network\mcpe\protocol\ClientboundPacket;
use pocketmine\network\mcpe\protocol\ContainerClosePacket;
use pocketmine\network\mcpe\protocol\ContainerOpenPacket;
use pocketmine\network\mcpe\protocol\ItemComponentPacket;
use pocketmine\player\Player;
use pocketmine\player\PlayerInfo;

/**
 * A player's connection, as plugins reach it for packets: Player::getNetworkSession().
 *
 * Packets go out through Falcon, which writes their header and batches them; see Packets for the game version.
 */
class NetworkSession{
    private ?InventoryManager $invManager = null;

    public function __construct(
        private ?Player $player = null,
        private int $connectingHandle = 0,
        private ?PlayerInfo $playerInfo = null,
        private int $remotePort = 0,
    ){
        if($player !== null){
            $this->invManager = new InventoryManager($player);
        }
    }

    /** @internal */
    public function attachPlayer(Player $player) : void{
        $this->player = $player;
        $this->invManager = new InventoryManager($player);
        $this->connectingHandle = 0;
        $this->playerInfo = null;
    }

    /** @internal */
    public function getFalconHandle() : int{
        return $this->player?->getHandle() ?? $this->connectingHandle;
    }

    /** @internal */
    public function finishConnection() : void{
        $this->connectingHandle = 0;
    }

    public function getPlayerInfo() : ?PlayerInfo{
        return $this->player?->getPlayerInfo() ?? $this->playerInfo;
    }

    public function getInvManager() : ?InventoryManager{
        return $this->player?->isOnline() ? $this->invManager : null;
    }

    public function getPlayer() : ?Player{
        return $this->player?->isOnline() ? $this->player : null;
    }

    public function isConnected() : bool{
        return $this->player?->isOnline() ?? ($this->connectingHandle !== 0);
    }

    public function getPing() : int{
        return $this->player?->getPing() ?? 0;
    }

    public function getDisplayName() : string{
        return $this->player?->getName() ?? $this->playerInfo?->getUsername() ?? "";
    }

    /** the address without its port */
    public function getIp() : string{
        return $this->isConnected() ? falcon_player_address($this->getFalconHandle()) : "";
    }

    public function getPort() : int{
        return $this->remotePort;
    }

    /** the protocol the client speaks, which may be newer than BedrockProtocol's */
    public function getProtocolId() : int{
        return $this->isConnected() ? falcon_player_protocol($this->getFalconHandle()) : 0;
    }

    /** @param bool $immediate kept for PocketMine-MP's signature: Falcon decides when it flushes */
    public function sendDataPacket(ClientboundPacket $packet, bool $immediate = false) : bool{
        if(!$this->player?->isOnline()){
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
        if(!$this->player?->isOnline()){
            return false;
        }
        if($packet instanceof ItemComponentPacket){
            return true;
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
        if($this->player?->isOnline()){
            falcon_player_send_commands($this->player->getHandle());
        }
    }

    /** @internal */
    public function onCloseAllForms() : void{
        $this->sendDataPacket(ClientboundCloseFormPacket::create());
    }
}
