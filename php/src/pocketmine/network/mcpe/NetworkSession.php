<?php

declare(strict_types=1);

namespace pocketmine\network\mcpe;

use pmmpcompat\Packets;
use pocketmine\network\mcpe\protocol\ClientboundCloseFormPacket;
use pocketmine\network\mcpe\protocol\ClientboundPacket;
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
        [$id, $payload] = Packets::encode($packet);
        return falcon_player_send_packet($this->player->getHandle(), $id, $payload);
    }

    public function getEntityEventBroadcaster() : EntityEventBroadcaster{
        return StandardEntityEventBroadcaster::shared();
    }

    public function getTypeConverter() : \pocketmine\network\mcpe\convert\TypeConverter{
        return \pocketmine\network\mcpe\convert\TypeConverter::getInstance();
    }

    /** @internal */
    public function onCloseAllForms() : void{
        $this->sendDataPacket(ClientboundCloseFormPacket::create());
    }
}
