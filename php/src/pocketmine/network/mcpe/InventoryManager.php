<?php

declare(strict_types=1);

namespace pocketmine\network\mcpe;

use pmmpcompat\Bridge;
use pmmpcompat\Log;
use pocketmine\block\inventory\BlockInventory;
use pocketmine\block\inventory\HopperInventory;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\inventory\Inventory;
use pocketmine\inventory\SimpleInventory;
use pocketmine\item\Item;
use pocketmine\network\mcpe\protocol\ClientboundPacket;
use pocketmine\network\mcpe\protocol\ContainerOpenPacket;
use pocketmine\network\mcpe\protocol\types\BlockPosition;
use pocketmine\network\mcpe\protocol\types\inventory\WindowTypes;
use pocketmine\player\Player;
use pocketmine\utils\ObjectSet;

/**
 * The windows a player has open, as PocketMine-MP's NetworkSession keeps them: NetworkSession::getInvManager().
 *
 * A window opens as one of Falcon's plugin windows: the container open callbacks give the ContainerOpenPacket that
 * says its type and where the client draws it from, and its slots are the inventory's. What the player moves in it
 * comes back into the inventory at the next tick.
 */
class InventoryManager{
    /** @var ObjectSet<\Closure(int $id, Inventory $inventory) : (list<ClientboundPacket>|null)> */
    private ObjectSet $containerOpenCallbacks;

    private ?Inventory $window = null;
    private int $windowId = 0;
    /** @var array<int, true> */
    private array $movedSlots = [];
    private bool $closedByFalcon = false;

    public function __construct(
        private Player $player
    ){
        $this->containerOpenCallbacks = new ObjectSet();
        $this->containerOpenCallbacks->add(self::createContainerOpen(...));
    }

    /**
     * @return ObjectSet<\Closure(int $id, Inventory $inventory) : (list<ClientboundPacket>|null)>
     */
    public function getContainerOpenCallbacks() : ObjectSet{
        return $this->containerOpenCallbacks;
    }

    /**
     * The default, for the inventories of container blocks.
     *
     * @return list<ClientboundPacket>|null
     */
    private static function createContainerOpen(int $id, Inventory $inventory) : ?array{
        if(!$inventory instanceof BlockInventory){
            return null;
        }
        $type = $inventory instanceof HopperInventory ? WindowTypes::HOPPER : WindowTypes::CONTAINER;
        return [ContainerOpenPacket::blockInv($id, $type, BlockPosition::fromVector3($inventory->getHolder()))];
    }

    public function getWindowId(Inventory $inventory) : ?int{
        return $inventory === $this->window ? $this->windowId : null;
    }

    public function getWindow(int $windowId) : ?Inventory{
        return $windowId === $this->windowId ? $this->window : null;
    }

    public function getCurrentWindowId() : int{
        return $this->windowId;
    }

    /** @internal Player::setCurrentWindow() */
    public function onCurrentWindowChange(Inventory $inventory) : bool{
        $this->onCurrentWindowRemove();
        $packets = null;
        foreach($this->containerOpenCallbacks->toArray() as $callback){
            $packets = $callback(0, $inventory);
            if($packets !== null){
                break;
            }
        }
        if($packets === null){
            throw new \InvalidArgumentException("Unsupported inventory type");
        }
        $open = null;
        foreach($packets as $packet){
            if($packet instanceof ContainerOpenPacket){
                $open ??= $packet;
            }else{
                $this->player->getNetworkSession()->sendDataPacket($packet);
            }
        }
        if($open === null || !$this->player->isOnline()){
            return false;
        }
        $position = $open->blockPosition;
        $id = falcon_player_open_window($this->player->getHandle(), $open->windowType, $position->getX(),
            $position->getY(), $position->getZ(), $open->actorUniqueId, $inventory->getSize());
        if($id === 0){
            Log::warning("A window could not open for " . $this->player->getName() . ": another one is open, or Falcon has no plugin windows");
            return false;
        }
        $this->window = $inventory;
        $this->windowId = $id;
        $this->closedByFalcon = false;
        Bridge::listenFor(DataPacketReceiveEvent::class);
        $this->syncContents($inventory);
        return true;
    }

    /** @internal Resolve the placeholder passed to the packet callback once Falcon allocates the window. */
    public function resolveWindowId(int $id) : int{
        return $id === 0 && $this->window !== null ? $this->windowId : $id;
    }

    public function onClientRemoveWindow(int $windowId) : void{
        if($this->getWindow($this->resolveWindowId($windowId)) !== null){
            $this->player->removeCurrentWindow();
        }
    }

    /** @internal Player::removeCurrentWindow() */
    public function onCurrentWindowRemove() : void{
        if($this->window === null){
            return;
        }
        $this->takeMoves();
        $id = $this->windowId;
        $this->window = null;
        $this->windowId = 0;
        if(!$this->closedByFalcon && $this->player->isOnline()){
            falcon_player_close_window($this->player->getHandle(), $id);
        }
    }

    /** @internal Falcon closed the window: the client did, or the server did */
    public function onClosedByFalcon(int $windowId) : void{
        if($windowId !== $this->windowId || $this->window === null){
            return;
        }
        $this->closedByFalcon = true;
        $this->player->removeCurrentWindow();
    }

    /** @internal the player moved something in the window */
    public function onMoved(int $slot) : void{
        if($this->window !== null && $this->window->slotExists($slot)){
            $this->movedSlots[$slot] = true;
        }
    }

    /** @internal what the player moved, into the inventory; at each tick and before the window closes */
    public function takeMoves() : void{
        if($this->movedSlots === []){
            return;
        }
        $slots = $this->movedSlots;
        $this->movedSlots = [];
        $window = $this->window;
        if(!$window instanceof SimpleInventory || !$this->player->isOnline()){
            return;
        }
        foreach($slots as $slot => $_){
            $data = falcon_player_window_item($this->player->getHandle(), $this->windowId, $slot);
            if($data !== null){
                $window->takeFromWindow($slot, Item::fromFalcon($data), $this->player);
            }
        }
    }

    public function onSlotChange(Inventory $inventory, int $slot) : void{
        if($inventory === $this->window && $this->player->isOnline()){
            falcon_player_set_window_item($this->player->getHandle(), $this->windowId, $slot, $inventory->getItem($slot)->toFalcon());
        }
    }

    public function syncSlot(Inventory $inventory, int $slot, mixed $itemStack = null) : void{
        $this->onSlotChange($inventory, $slot);
    }

    public function syncContents(Inventory $inventory) : void{
        if($inventory !== $this->window || !$this->player->isOnline()){
            return;
        }
        for($slot = 0, $size = $inventory->getSize(); $slot < $size; ++$slot){
            $this->onSlotChange($inventory, $slot);
        }
    }

    public function syncAll() : void{
        if($this->window !== null){
            $this->syncContents($this->window);
        }
    }
}
