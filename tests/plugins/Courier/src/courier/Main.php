<?php

declare(strict_types=1);

namespace courier;

use pmmpcompat\Packets;
use pocketmine\entity\EntityDataHelper;
use pocketmine\entity\EntityFactory;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\server\DataPacketSendEvent;
use pocketmine\form\Form;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\PacketPool;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\network\mcpe\protocol\TextPacket;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\world\World;

// packets, forms, a player's connection and the entity register
final class Main extends PluginBase implements Listener{
    private bool $sawSend = false;

    protected function onEnable() : void{
        $logger = $this->getLogger();
        $this->getServer()->getPluginManager()->registerEvents($this, $this);

        EntityFactory::getInstance()->register(Npc::class, function(World $world, CompoundTag $nbt) : Npc{
            return new Npc(EntityDataHelper::parseLocation($nbt, $world), $nbt);
        }, ["courier:npc"]);
        $logger->info("fixture: Courier registered its npc as " . EntityFactory::getInstance()->getSaveId(Npc::class));

        $packet = TextPacket::raw("hello through the protocol");
        [$id, $payload] = Packets::encode($packet);
        $back = Packets::decode($id, $payload);
        $logger->info("fixture: Courier packet " . ($back instanceof TextPacket && $back->message === "hello through the protocol" ? "round trips" : "breaks")
            . ", protocol " . ProtocolInfo::MINECRAFT_VERSION_NETWORK . ", pool " . (PacketPool::getInstance()->getPacketById(ProtocolInfo::TEXT_PACKET) !== null ? "ready" : "empty"));
    }

    public function onJoin(PlayerJoinEvent $event) : void{
        $player = $event->getPlayer();
        $session = $player->getNetworkSession();
        $this->getLogger()->info("fixture: Courier sees " . $player->getName() . " with uuid " . (strlen($player->getUniqueId()->toString()) === 36 ? "set" : "missing")
            . " and protocol " . ($session->getProtocolId() > 0 ? "known" : "unknown"));
        $player->sendForm(new Question());
        $this->getLogger()->info("fixture: Courier sent a form and a packet: " . ($session->sendDataPacket(TextPacket::raw("a packet from a plugin")) ? "sent" : "refused"));
    }

    public function onSend(DataPacketSendEvent $event) : void{
        if(!$this->sawSend){
            $this->sawSend = true;
            $this->getLogger()->info("fixture: Courier saw a packet go out to " . count($event->getTargets()) . " player");
        }
    }
}
