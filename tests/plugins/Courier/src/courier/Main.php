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
use pocketmine\network\mcpe\protocol\AddActorPacket;
use pocketmine\network\mcpe\protocol\BlockActorDataPacket;
use pocketmine\network\mcpe\protocol\PacketPool;
use pocketmine\network\mcpe\protocol\SetScorePacket;
use pocketmine\network\mcpe\protocol\types\BlockPosition;
use pocketmine\network\mcpe\protocol\types\CacheableNbt;
use pocketmine\network\mcpe\protocol\types\ScorePacketEntry;
use pocketmine\network\mcpe\protocol\types\entity\PropertySyncData;
use pocketmine\math\Vector3;
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
        $this->checkPluginPackets();
    }

    private function checkPluginPackets() : void{
        $entry = new ScorePacketEntry();
        $entry->scoreboardId = 8;
        $entry->objectiveName = "courier";
        $entry->score = 5;
        $entry->type = ScorePacketEntry::TYPE_FAKE_PLAYER;
        $entry->customName = "Score line";
        $change = SetScorePacket::create(SetScorePacket::TYPE_CHANGE, [$entry]);
        $remove = SetScorePacket::create(type: SetScorePacket::TYPE_REMOVE, entries: [$entry]);
        $changed = Packets::decode(...Packets::encode($change));
        $removed = Packets::decode(...Packets::encode($remove));

        $tile = CompoundTag::create()->setString("id", "Chest")->setString("CustomName", "Courier Chest")
            ->setInt("x", -3)->setFloat("progress", 0.25)->setLong("stamp", 4_000_000_000);
        $blockData = Packets::decode(...Packets::encode(
            BlockActorDataPacket::create(new BlockPosition(-3, 100, 2), new CacheableNbt($tile))
        ));
        $actor = Packets::decode(...Packets::encode(AddActorPacket::create(17, 18, "minecraft:falling_block",
            new Vector3(2, 100, 3), null, 0, 0, 0, 0, [], [], new PropertySyncData([], []), [])));
        if($changed instanceof SetScorePacket && $changed->entries[0]->customName === "Score line"
            && $removed instanceof SetScorePacket && $removed->entries[0]->type === ScorePacketEntry::TYPE_REMOVE
            && $removed->entries[0]->scoreboardId === 8 && $entry->type === ScorePacketEntry::TYPE_FAKE_PLAYER
            && $blockData instanceof BlockActorDataPacket && $blockData->nbt->getRoot()->equals($tile)
            && $actor instanceof AddActorPacket && $actor->actorRuntimeId === 18 && $actor->passengerOfBlockData === null){
            $this->getLogger()->info("fixture: Courier score, block actor NBT and fake actor packets round trip");
        }
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
