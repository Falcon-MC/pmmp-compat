<?php
declare(strict_types=1);
namespace stepteleven;

use jp\mcbe\fuyutsuki\Texter\text\FloatingTextCluster;
use jp\mcbe\fuyutsuki\Texter\text\SendType;
use muqsit\invmenu\InvMenu;
use muqsit\invmenu\InvMenuHandler;
use pmmpcompat\Packets;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerLoginEvent;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\event\server\DataPacketSendEvent;
use pocketmine\event\inventory\InventoryTransactionEvent;
use pocketmine\inventory\transaction\InventoryTransaction;
use pocketmine\inventory\transaction\action\SlotChangeAction;
use pocketmine\item\VanillaItems;
use pocketmine\network\mcpe\protocol\ContainerOpenPacket;
use pocketmine\network\mcpe\protocol\NetworkStackLatencyPacket;
use pocketmine\network\mcpe\protocol\PacketViolationWarningPacket;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\ClosureTask;

final class Main extends PluginBase implements Listener{
    protected function onEnable() : void{
        InvMenuHandler::register($this);
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
    }

    public function onJoin(PlayerJoinEvent $event) : void{
        // The simulated player has no network login handshake.
        if(InvMenuHandler::getPlayerManager()->getNullable($event->getPlayer()) === null){
            (new PlayerLoginEvent($event->getPlayer(), ""))->call();
        }
        $this->getScheduler()->scheduleDelayedTask(new ClosureTask(fn() => $this->test($event->getPlayer(), (int) getenv("STEP11_MENU"))), 3);
        $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($event) : void{
            $player = $event->getPlayer();
            $text = new FloatingTextCluster($player->getPosition()->up(), "stage11", null, ["Proof"]);
            foreach([SendType::ADD, SendType::EDIT, SendType::REMOVE] as $operation){
                $text->sendToPlayer($player, $operation);
            }
        }), 5);
    }

    public function onPacket(DataPacketSendEvent $event) : void{
        foreach($event->getPackets() as $packet){
            if(!$packet instanceof NetworkStackLatencyPacket && !$packet instanceof ContainerOpenPacket){
                if(preg_match('/SetScore|Objective|Actor|Block/', get_class($packet))){
                    [$id, $payload] = Packets::encode($packet);
                    if(Packets::decode($id, $payload) === null){
                        throw new \RuntimeException("Packet decode failed " . get_class($packet));
                    }
                    $this->getLogger()->info("fixture wire " . (new \ReflectionClass($packet))->getShortName());
                }
                continue;
            }
            foreach($event->getTargets() as $session){
                if($packet instanceof NetworkStackLatencyPacket && $packet->needResponse){
                    $response = NetworkStackLatencyPacket::response($packet->timestamp * 1000000);
                }elseif($packet instanceof ContainerOpenPacket){
                    if($packet->windowId === 0){
                        throw new \RuntimeException("The opening packet used the placeholder window ID");
                    }
                    $response = PacketViolationWarningPacket::create(-1, PacketViolationWarningPacket::SEVERITY_WARNING,
                        PacketViolationWarningPacket::NETWORK_ID, "");
                }else{
                    continue;
                }
                $this->getScheduler()->scheduleDelayedTask(new ClosureTask(
                    fn() => (new DataPacketReceiveEvent($session, $response))->call()
                ), 1);
            }
        }
    }

    private function test(Player $player, int $index) : void{
        $types = [InvMenu::TYPE_CHEST, InvMenu::TYPE_DOUBLE_CHEST, InvMenu::TYPE_HOPPER];
        if(!isset($types[$index])){
            return;
        }
        $size = [27, 54, 5][$index];
        $menu = InvMenu::create($types[$index]);
        $inventory = $menu->getInventory();
        $inventory->setItem($size - 1, VanillaItems::EMERALD());
        $menu->setListener(fn($transaction) => $transaction->discard());
        $menu->setInventoryCloseListener(fn() => $this->getLogger()->info("fixture close $size"));
        $menu->send($player, "Packet menu", function(bool $ok) use ($inventory, $size, $player) : void{
            if(!$ok){
                throw new \RuntimeException("failed to open $size");
            }
            $session = $player->getNetworkSession();
            $id = $session->getInvManager()->getCurrentWindowId();
            $slot = $size - 1;
            $nativeItem = falcon_player_window_item($player->getHandle(), $id, $slot);
            if($id === 0 || ($nativeItem["id"] ?? null) !== "minecraft:emerald"){
                throw new \RuntimeException("item not synchronized $size: " . json_encode($nativeItem));
            }
            $event = new InventoryTransactionEvent(new InventoryTransaction($player, [
                new SlotChangeAction($inventory, $slot, $inventory->getItem($slot), VanillaItems::AIR())
            ]));
            $event->call();
            if(!$event->isCancelled()){
                throw new \RuntimeException("read-only listener did not cancel");
            }
            $incoming = VanillaItems::DIAMOND()->setCount(3);
            falcon_player_set_window_item($player->getHandle(), $id, $slot, $incoming->toFalcon());
            $manager = $session->getInvManager();
            $manager->onMoved($slot);
            $manager->takeMoves();
            if(!$inventory->getItem($slot)->equalsExact($incoming)){
                throw new \RuntimeException("committed move not synchronized");
            }
            $this->getLogger()->info("fixture passed $size");
            $this->getScheduler()->scheduleDelayedTask(new ClosureTask($player->removeCurrentWindow(...)), 1);
        });
    }
}
