<?php

declare(strict_types=1);

namespace pmmpcompat;

use pocketmine\event\Cancellable;
use pocketmine\event\Event;
use pocketmine\event\EventPriority;
use pocketmine\event\HandlerListManager;
use pocketmine\event\player\PlayerChatEvent;
use pocketmine\event\player\PlayerEmoteEvent;
use pocketmine\event\player\PlayerGameModeChangeEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerJumpEvent;
use pocketmine\event\player\PlayerLoginEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\player\PlayerToggleFlightEvent;
use pocketmine\event\player\PlayerToggleSneakEvent;
use pocketmine\event\player\PlayerToggleSprintEvent;
use pocketmine\event\server\CommandEvent;
use pocketmine\event\inventory\InventoryCloseEvent;
use pocketmine\event\inventory\InventoryOpenEvent;
use pocketmine\event\inventory\InventoryTransactionEvent;
use pocketmine\inventory\Inventory;
use pocketmine\inventory\transaction\action\DestroyItemAction;
use pocketmine\inventory\transaction\action\DropItemAction;
use pocketmine\inventory\transaction\action\SlotChangeAction;
use pocketmine\inventory\transaction\InventoryTransaction;
use pocketmine\item\VanillaItems;
use pmmpcompat\inventory\FalconBlockInventory;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\event\server\DataPacketSendEvent;
use pocketmine\network\mcpe\protocol\ServerboundPacket;
use pocketmine\network\mcpe\protocol\ClientboundPacket;
use pocketmine\block\BaseSign;
use pocketmine\block\Block;
use pocketmine\block\utils\SignText;
use pocketmine\entity\Living;
use pocketmine\entity\Location;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\block\BlockPlaceEvent;
use pocketmine\event\block\SignChangeEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntityDeathEvent;
use pocketmine\event\entity\EntityTeleportEvent;
use pocketmine\event\player\PlayerDeathEvent;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\player\PlayerItemUseEvent;
use pocketmine\event\player\PlayerMoveEvent;
use pocketmine\event\player\PlayerRespawnEvent;
use pocketmine\item\Item;
use pocketmine\player\Player;
use pocketmine\world\BlockTransaction;
use pocketmine\world\Position;
use pocketmine\player\GameMode;
use pocketmine\lang\Translatable;
use pocketmine\player\chat\StandardChatFormatter;
use pocketmine\Server;

/**
 * Where Falcon's events and commands come in.
 *
 * Falcon calls once for each of its priorities, lowest first, and the listeners of the matching PocketMine-MP
 * priority run then; MONITOR listeners run right after HIGHEST, since Falcon lets nothing change past that. The event
 * object lives from the LOWEST call to the HIGHEST one, and what listeners change goes back to Falcon at each step,
 * so Falcon's other plugins see it.
 */
final class Bridge{
    // the event types of falcon_api.h
    private const PLAYER_JOIN = 1;
    private const PLAYER_QUIT = 2;
    private const PLAYER_CHAT = 3;
    private const PLAYER_COMMAND = 21;
    private const SERVER_COMMAND = 22;
    private const PLAYER_GAME_MODE_CHANGE = 24;
    private const PLAYER_PRE_LOGIN = 31;
    private const PLAYER_TOGGLE_SNEAK = 32;
    private const PLAYER_TOGGLE_SPRINT = 33;
    private const PLAYER_TOGGLE_FLIGHT = 34;
    private const PLAYER_JUMP = 39;
    private const PLAYER_EMOTE = 76;
    private const BLOCK_BREAK = 4;
    private const BLOCK_PLACE = 5;
    private const PLAYER_INTERACT_BLOCK = 6;
    private const ENTITY_DAMAGE = 7;
    private const ENTITY_DEATH = 8;
    private const PLAYER_DEATH = 9;
    private const PLAYER_RESPAWN = 10;
    private const PLAYER_MOVE = 11;
    private const SIGN_CHANGE = 54;
    private const PLAYER_TELEPORT = 70;
    private const PLAYER_ITEM_USE = 71;
    private const ENTITY_TELEPORT = 83;
    private const DATA_PACKET_RECEIVE = 14;
    private const DATA_PACKET_SEND = 15;
    private const PLAYER_FORM_RESPONSE = 69;
    private const INVENTORY_OPEN = 17;
    private const INVENTORY_CLOSE = 18;
    private const INVENTORY_TRANSACTION = 19;

    /** what the runtime always listens to: it keeps its players, their forms, and whether they sneak, sprint or fly */
    private const ALWAYS = [self::PLAYER_JOIN, self::PLAYER_QUIT, self::PLAYER_FORM_RESPONSE, self::INVENTORY_TRANSACTION,
        self::PLAYER_TOGGLE_SNEAK, self::PLAYER_TOGGLE_SPRINT, self::PLAYER_TOGGLE_FLIGHT];

    /** the PocketMine-MP events each Falcon event becomes; Falcon passes it once a listener wants one of them */
    private const SOURCES = [
        self::PLAYER_CHAT => [PlayerChatEvent::class],
        self::PLAYER_COMMAND => [CommandEvent::class],
        self::SERVER_COMMAND => [CommandEvent::class],
        self::PLAYER_GAME_MODE_CHANGE => [PlayerGameModeChangeEvent::class],
        self::PLAYER_PRE_LOGIN => [PlayerLoginEvent::class],
        self::PLAYER_TOGGLE_SNEAK => [PlayerToggleSneakEvent::class],
        self::PLAYER_TOGGLE_SPRINT => [PlayerToggleSprintEvent::class],
        self::PLAYER_TOGGLE_FLIGHT => [PlayerToggleFlightEvent::class],
        self::PLAYER_JUMP => [PlayerJumpEvent::class],
        self::PLAYER_EMOTE => [PlayerEmoteEvent::class],
        self::BLOCK_BREAK => [BlockBreakEvent::class],
        self::BLOCK_PLACE => [BlockPlaceEvent::class],
        self::PLAYER_INTERACT_BLOCK => [PlayerInteractEvent::class],
        self::ENTITY_DAMAGE => [EntityDamageEvent::class, EntityDamageByEntityEvent::class],
        self::ENTITY_DEATH => [EntityDeathEvent::class],
        self::PLAYER_DEATH => [PlayerDeathEvent::class],
        self::PLAYER_RESPAWN => [PlayerRespawnEvent::class],
        self::PLAYER_MOVE => [PlayerMoveEvent::class],
        self::SIGN_CHANGE => [SignChangeEvent::class],
        self::PLAYER_TELEPORT => [EntityTeleportEvent::class],
        self::PLAYER_ITEM_USE => [PlayerItemUseEvent::class],
        self::ENTITY_TELEPORT => [EntityTeleportEvent::class],
        self::DATA_PACKET_RECEIVE => [DataPacketReceiveEvent::class],
        self::DATA_PACKET_SEND => [DataPacketSendEvent::class],
        // a container block's window: both ends, the current window follows them
        self::INVENTORY_OPEN => [InventoryOpenEvent::class, InventoryCloseEvent::class],
        self::INVENTORY_CLOSE => [InventoryOpenEvent::class, InventoryCloseEvent::class],
    ];

    /** @var array<int, true> the Falcon events passed here so far */
    private static array $listening = [];

    /** @var array<int, array{event: Event, message: string, recipients: object[], sender: ?object}> by handle */
    private static array $inFlight = [];

    private function __construct(){
    }

    /** @internal at startup: what the runtime itself needs */
    public static function listen() : void{
        foreach(self::ALWAYS as $type){
            self::listenTo($type);
        }
    }

    /**
     * @internal a listener for that event class was registered: the Falcon events it can come from are passed from
     * now on, a listener for a parent class such as PlayerEvent taking all of its children's
     */
    public static function listenFor(string $eventClass) : void{
        foreach(self::SOURCES + WorldEvents::SOURCES as $type => $classes){
            foreach($classes as $class){
                if(is_a($class, $eventClass, true)){
                    self::listenTo($type);
                }
            }
        }
    }

    private static function listenTo(int $type) : void{
        if(!isset(self::$listening[$type])){
            self::$listening[$type] = true;
            falcon_listen($type);
        }
    }

    /** @internal an entity a plugin made exists: hits on it have to reach its attack() */
    public static function listenForDrawnEntities() : void{
        self::listenTo(self::ENTITY_DAMAGE);
    }

    /** @internal a player clicked an entity a plugin made */
    public static function entityInteract(int $entityHandle, int $playerHandle) : bool{
        try{
            $entity = Entities::drawnBy($entityHandle);
            if($entity === null || $entity->isClosed()){
                return false;
            }
            return $entity->onInteract(Players::get($playerHandle), $entity->getPosition());
        }catch(\Throwable $e){
            Log::critical('An entity of a plugin could not take a click: ' . $e->getMessage());
            Log::exception($e);
            return false;
        }
    }

    /** @internal a plugin window closed, whoever closed it */
    public static function windowClosed(int $playerHandle, int $windowId) : void{
        try{
            Players::get($playerHandle)->getNetworkSession()->getInvManager()?->onClosedByFalcon($windowId);
        }catch(\Throwable $e){
            Log::critical('Could not close a window: ' . $e->getMessage());
            Log::exception($e);
        }
    }

    /** @internal each of Falcon's ticks */
    public static function tick() : void{
        try{
            foreach(Players::online() as $player){
                $player->getNetworkSession()->getInvManager()?->takeMoves();
            }
            Server::getInstance()->tick();
            Entities::tickDrawn(Server::getInstance()->getTick());
        }catch(\Throwable $e){
            Log::critical('A tick of the PocketMine-MP plugins failed: ' . $e->getMessage());
            Log::exception($e);
        }
    }

    public static function event(int $type, int $priority, int $handle) : void{
        try{
            if($priority === EventPriority::LOWEST){
                $event = self::create($type, $handle);
                if($event === null){
                    return;
                }
                self::$inFlight[$handle] = $event;
            }
            if(!isset(self::$inFlight[$handle])){
                return;
            }
            $event = self::$inFlight[$handle]['event'];
            self::callListeners($event, $priority);
            if($priority === EventPriority::HIGHEST){
                self::callListeners($event, EventPriority::MONITOR);
            }
            self::applyToFalcon($type, $handle, $priority === EventPriority::HIGHEST);
        }catch(\Throwable $e){
            Log::critical('Could not pass an event to the PocketMine-MP plugins: ' . $e->getMessage());
            Log::exception($e);
        }finally{
            if($priority === EventPriority::HIGHEST){
                unset(self::$inFlight[$handle]);
                if($type === self::PLAYER_QUIT){
                    Players::leave(falcon_event_player($handle));
                }
            }
        }
    }

    /** @return array{event: Event, message: string, recipients: object[], sender: ?object}|null */
    private static function create(int $type, int $handle) : ?array{
        $server = Server::getInstance();
        $message = falcon_event_message($handle);
        switch($type){
            case self::PLAYER_JOIN:
                $player = Players::get(falcon_event_player($handle));
                return ['event' => new PlayerJoinEvent($player, $message), 'message' => $message, 'recipients' => [],
                    'sender' => null];
            case self::PLAYER_QUIT:
                $player = Players::get(falcon_event_player($handle));
                $quitMessage = $player->takeQuitMessage();
                $event = new PlayerQuitEvent($player, $quitMessage ?? $message, falcon_event_cause($handle));
                // a kick that named a leave message: it is the starting one, and Falcon has to know
                return ['event' => $event, 'message' => $quitMessage === null ? $message : "\0", 'recipients' => [],
                    'sender' => null];
            case self::PLAYER_CHAT:
                $player = Players::get(falcon_event_player($handle));
                $recipients = $server->getBroadcastChannelSubscribers(Server::BROADCAST_CHANNEL_USERS);
                $event = new PlayerChatEvent($player, $message, $recipients, new StandardChatFormatter());
                if(falcon_event_is_cancelled($handle)){
                    $event->cancel();
                }
                return ['event' => $event, 'message' => $message, 'recipients' => $recipients, 'sender' => $player];
            case self::PLAYER_COMMAND:
            case self::SERVER_COMMAND:
                $player = falcon_event_player($handle);
                $sender = $player !== 0 ? Players::get($player) : $server->getConsoleSender();
                return self::state(new CommandEvent($sender, $message), $message, $handle);
            case self::PLAYER_PRE_LOGIN:
                // Falcon's last word before the player is let in, as PocketMine-MP's login event
                return self::state(new PlayerLoginEvent(Players::get(falcon_event_player($handle)), $message), $message, $handle);
            case self::PLAYER_GAME_MODE_CHANGE:
                $gameMode = self::gameMode(falcon_event_game_mode($handle));
                if($gameMode === null){
                    return null;
                }
                return self::state(new PlayerGameModeChangeEvent(Players::get(falcon_event_player($handle)), $gameMode), $message, $handle);
            case self::PLAYER_TOGGLE_SNEAK:
                return self::state(new PlayerToggleSneakEvent(Players::get(falcon_event_player($handle)), falcon_event_state($handle)), $message, $handle);
            case self::PLAYER_TOGGLE_SPRINT:
                return self::state(new PlayerToggleSprintEvent(Players::get(falcon_event_player($handle)), falcon_event_state($handle)), $message, $handle);
            case self::PLAYER_TOGGLE_FLIGHT:
                return self::state(new PlayerToggleFlightEvent(Players::get(falcon_event_player($handle)), falcon_event_state($handle)), $message, $handle);
            case self::PLAYER_JUMP:
                return self::state(new PlayerJumpEvent(Players::get(falcon_event_player($handle))), $message, $handle);
            case self::PLAYER_EMOTE:
                return self::state(new PlayerEmoteEvent(Players::get(falcon_event_player($handle)), $message), $message, $handle);
            default:
                return self::createWorldEvent($type, $handle, $message);
        }
    }

    /** the events that carry blocks, items and entities */
    private static function createWorldEvent(int $type, int $handle, string $message) : ?array{
        $playerHandle = falcon_event_player($handle);
        $player = $playerHandle !== 0 ? Players::get($playerHandle) : null;
        switch($type){
            case self::PLAYER_MOVE:
                if($player === null){
                    return null;
                }
                $from = $player->getLocation();
                [$x, $y, $z] = falcon_event_from($handle);
                [$toX, $toY, $toZ] = falcon_event_to($handle);
                $world = $from->isValid() ? $from->getWorld() : null;
                $event = new PlayerMoveEvent($player, new Location($x, $y, $z, $world, $from->yaw, $from->pitch),
                    new Location($toX, $toY, $toZ, $world, $from->yaw, $from->pitch));
                return self::state($event, $message, $handle) + ['to' => [$toX, $toY, $toZ]];
            case self::PLAYER_TELEPORT:
            case self::ENTITY_TELEPORT:
                $entity = $player ?? self::eventEntity($handle);
                if($entity === null){
                    return null;
                }
                $world = self::eventWorld($handle) ?? $entity->getWorld();
                [$x, $y, $z] = falcon_event_from($handle);
                [$toX, $toY, $toZ] = falcon_event_to($handle);
                $event = new EntityTeleportEvent($entity, new Position($x, $y, $z, $entity->getWorld()),
                    new Position($toX, $toY, $toZ, $world));
                return self::state($event, $message, $handle) + ['to' => [$toX, $toY, $toZ]];
            case self::ENTITY_DAMAGE:
                $entity = self::eventEntity($handle);
                if($entity === null){
                    return null;
                }
                $cause = DamageCauses::fromFalcon(falcon_event_cause($handle));
                $amount = falcon_event_amount($handle);
                $attackerHandle = falcon_event_attacker($handle);
                $knockback = falcon_event_knockback($handle);
                $event = $attackerHandle !== 0
                    ? new EntityDamageByEntityEvent(Entities::get($attackerHandle), $entity, $cause, $amount, [],
                        $knockback[0] ?? Living::DEFAULT_KNOCKBACK_FORCE, $knockback[1] ?? Living::DEFAULT_KNOCKBACK_VERTICAL_LIMIT)
                    : new EntityDamageEvent($entity, $cause, $amount);
                if($entity->isDrawnByPlugin()){
                    // PHP hurts it, as on PocketMine-MP: its attack() decides, Falcon's stand-in takes nothing
                    $refused = falcon_event_is_cancelled($handle);
                    falcon_event_set_cancelled($handle, true);
                    if(!$refused){
                        $entity->attack($event);
                    }
                    return null;
                }
                $entity->setLastDamageCause($event);
                return self::state($event, $message, $handle) + ['amount' => $amount, 'knockback' => $knockback];
            case self::ENTITY_DEATH:
                $entity = self::eventEntity($handle);
                if(!$entity instanceof Living || $entity instanceof Player || $entity->isDrawnByPlugin()){
                    return null;
                }
                [$drops, $xp] = self::drops($handle);
                return self::state(new EntityDeathEvent($entity, $drops, $xp), $message, $handle) + self::dropState($handle);
            case self::PLAYER_DEATH:
                if($player === null){
                    return null;
                }
                [$drops, $xp] = self::drops($handle);
                $event = new PlayerDeathEvent($player, $drops, $xp, $message);
                [$keepInventory, $keepXp] = falcon_event_keep($handle);
                $event->setKeepInventory($keepInventory);
                $event->setKeepXp($keepXp);
                return self::state($event, $message, $handle) + self::dropState($handle) + ['keep' => [$keepInventory, $keepXp]];
            case self::PLAYER_RESPAWN:
                if($player === null){
                    return null;
                }
                [$x, $y, $z] = falcon_event_to($handle);
                $world = $player->getWorld();
                return self::state(new PlayerRespawnEvent($player, new Position($x, $y, $z, $world)), $message, $handle)
                    + ['to' => [$x, $y, $z]];
            case self::PLAYER_INTERACT_BLOCK:
                if($player === null){
                    return null;
                }
                $block = self::eventBlock($handle, $player);
                $face = falcon_event_block($handle)[3];
                return self::state(new PlayerInteractEvent($player, $player->getInventory()->getItemInHand(), $block, null,
                    $face, PlayerInteractEvent::RIGHT_CLICK_BLOCK), $message, $handle);
            case self::PLAYER_ITEM_USE:
                if($player === null){
                    return null;
                }
                $data = falcon_event_item($handle);
                $item = $data !== null ? Item::fromFalcon($data) : $player->getInventory()->getItemInHand();
                return self::state(new PlayerItemUseEvent($player, $item, $player->getDirectionVector()), $message, $handle);
            case self::BLOCK_BREAK:
                if($player === null){
                    return null;
                }
                $block = self::eventBlock($handle, $player, true);
                [$drops, $xp] = self::drops($handle);
                return self::state(new BlockBreakEvent($player, $block, $player->getInventory()->getItemInHand(),
                    $player->isCreative(), $drops, $xp), $message, $handle) + self::dropState($handle);
            case self::BLOCK_PLACE:
                if($player === null){
                    return null;
                }
                $placed = self::eventBlock($handle, $player, true);
                $world = $player->getWorld();
                $position = $placed->getPosition();
                $transaction = (new BlockTransaction($world))->addBlock($position, $placed);
                $against = $world->getBlock($position);
                return self::state(new BlockPlaceEvent($player, $transaction, $against,
                    $player->getInventory()->getItemInHand()), $message, $handle);
            case self::PLAYER_FORM_RESPONSE:
                // not an event in PocketMine-MP: the form the player answered gets the answer, once
                if($player !== null){
                    [$formId, $response] = falcon_event_form($handle);
                    $player->onFormSubmit($formId, json_decode($response, true));
                }
                return null;
            case self::DATA_PACKET_RECEIVE:
            case self::DATA_PACKET_SEND:
                // decoded only for listeners: most packets pass with nobody looking
                $receiving = $type === self::DATA_PACKET_RECEIVE;
                if($player === null
                    || !($receiving ? DataPacketReceiveEvent::hasHandlers() : DataPacketSendEvent::hasHandlers())){
                    return null;
                }
                [$packetId, $payload] = falcon_event_packet($handle);
                $packet = Packets::decode($packetId, $payload);
                if($receiving){
                    return $packet instanceof ServerboundPacket
                        ? self::state(new DataPacketReceiveEvent($player->getNetworkSession(), $packet), $message, $handle) : null;
                }
                return $packet instanceof ClientboundPacket
                    ? self::state(new DataPacketSendEvent([$player->getNetworkSession()], [$packet]), $message, $handle)
                        + ['packets' => [$packet], 'packetId' => $packetId, 'payload' => $payload] : null;
            case self::INVENTORY_OPEN:
                if($player === null){
                    return null;
                }
                $position = self::eventBlock($handle, $player)->getPosition();
                $inventory = FalconBlockInventory::at($position);
                return self::state(new InventoryOpenEvent($inventory, $player), $message, $handle) + ['inventory' => $inventory];
            case self::INVENTORY_CLOSE:
                // the window of a container block closed: the player's current window goes, InventoryCloseEvent with it
                if($player !== null && $player->getCurrentWindow() instanceof FalconBlockInventory){
                    $player->removeCurrentWindow();
                }
                return null;
            case self::INVENTORY_TRANSACTION:
                return $player === null ? null : self::createTransaction($handle, $player, $message);
            case self::SIGN_CHANGE:
                if($player === null){
                    return null;
                }
                $sign = self::eventBlock($handle, $player);
                $sign = $sign instanceof BaseSign ? $sign : (new BaseSign("minecraft:oak_sign"))->withPosition(
                    $sign->getPosition()->getWorld(), (int) $sign->getPosition()->x, (int) $sign->getPosition()->y,
                    (int) $sign->getPosition()->z);
                $text = new SignText(explode("\n", $message));
                return self::state(new SignChangeEvent($sign, $player, $text, falcon_event_state($handle)), $message, $handle);
        }
        $created = WorldEvents::create($type, $handle, $player);
        return $created === null ? null : self::state($created['event'], $message, $handle) + $created;
    }

    /**
     * One move of an item stack request, as PocketMine-MP's transaction of slot changes; Falcon asks before it
     * applies the request, so cancelling refuses the whole of it.
     */
    private static function createTransaction(int $handle, Player $player, string $message) : ?array{
        [$action, $source, $sourceSlot, $destination, $destinationSlot, $count] = falcon_event_transaction($handle);
        $manager = $player->getNetworkSession()->getInvManager();
        if($manager !== null && $manager->getCurrentWindowId() !== 0 && ($source === "container" || $destination === "container")){
            $manager->onMoved();
        }
        if(!InventoryTransactionEvent::hasHandlers()){
            return null;
        }
        $data = falcon_event_item($handle);
        $item = $data !== null ? Item::fromFalcon($data) : VanillaItems::AIR();
        $moved = (clone $item)->setCount(max(0, min($count, $item->getCount())));
        $left = (clone $item)->setCount($item->getCount() - $moved->getCount());

        $actions = [];
        $from = self::inventoryOf($player, $source);
        if($from !== null && $sourceSlot >= 0){
            $actions[] = new SlotChangeAction($from, $sourceSlot, $item, $action === "swap"
                ? (self::inventoryOf($player, $destination)?->getItem($destinationSlot) ?? VanillaItems::AIR()) : $left);
        }
        if($action === "drop"){
            $actions[] = new DropItemAction($moved);
        }elseif($action === "destroy"){
            $actions[] = new DestroyItemAction($moved);
        }elseif($destination !== ""){
            $to = self::inventoryOf($player, $destination);
            if($to !== null && $destinationSlot >= 0){
                $before = $to->getItem($destinationSlot);
                $after = $action === "swap" ? $item : ($before->canStackWith($moved)
                    ? (clone $before)->setCount($before->getCount() + $moved->getCount()) : $moved);
                $actions[] = new SlotChangeAction($to, $destinationSlot, $before, $after);
            }
        }
        if(count($actions) === 0){
            return null;
        }
        return self::state(new InventoryTransactionEvent(new InventoryTransaction($player, $actions)), $message, $handle);
    }

    /** the PocketMine-MP inventory a Falcon container name stands for */
    private static function inventoryOf(Player $player, string $container) : ?Inventory{
        return match($container){
            "hotbar", "inventory", "hotbar_and_inventory" => $player->getInventory(),
            "armor" => $player->getArmorInventory(),
            "offhand" => $player->getOffHandInventory(),
            "cursor" => $player->getCursorInventory(),
            "container" => $player->getCurrentWindow(),
            default => null,
        };
    }

    private static function eventEntity(int $handle) : ?\pocketmine\entity\Entity{
        $entity = falcon_event_entity($handle);
        return $entity === 0 ? null : Entities::get($entity);
    }

    private static function eventWorld(int $handle) : ?\pocketmine\world\World{
        return Server::getInstance()->getWorldManager()->forLevel(falcon_event_level($handle));
    }

    /**
     * The event's block where it is; named by the event when Falcon names it, read from the world otherwise.
     */
    private static function eventBlock(int $handle, Player $player, bool $named = false) : Block{
        [$x, $y, $z, , $name] = falcon_event_block($handle);
        $world = self::eventWorld($handle) ?? $player->getWorld();
        if($named && $name !== ""){
            return Block::fromFalcon($name, "{}")->withPosition($world, $x, $y, $z);
        }
        return $world->getBlockAt($x, $y, $z);
    }

    /**
     * What the event drops, as items, and its experience.
     * @return array{Item[], int}
     */
    private static function drops(int $handle) : array{
        $drops = array_map(static fn(array $data) : Item => Item::fromFalcon($data), falcon_event_drops($handle) ?? []);
        return [$drops, falcon_event_experience($handle)];
    }

    /** what the drops were as Falcon gave them, to tell a change from none */
    private static function dropState(int $handle) : array{
        return ['drops' => falcon_event_drops($handle), 'xp' => falcon_event_experience($handle)];
    }

    /** the drops and experience a listener set go back to Falcon */
    private static function applyDrops(int $handle, array &$state, array $drops, int $xp) : void{
        if($state['drops'] !== null){
            $raw = array_values(array_map(static fn(Item $item) : array => $item->toFalcon(), array_filter($drops, static fn(Item $item) : bool => !$item->isNull())));
            if($raw !== $state['drops']){
                falcon_event_set_drops($handle, $raw);
                $state['drops'] = $raw;
            }
        }
        if($xp !== $state['xp']){
            falcon_event_set_experience($handle, $xp);
            $state['xp'] = $xp;
        }
    }

    /** @return array{event: Event, message: string, recipients: object[], sender: ?object} */
    private static function state(Event $event, string $message, int $handle) : array{
        // cancelled already when a Falcon plugin listening below LOWEST did
        if($event instanceof Cancellable && falcon_event_is_cancelled($handle)){
            $event->cancel();
        }
        return ['event' => $event, 'message' => $message, 'recipients' => [], 'sender' => null];
    }

    /** Falcon's game mode numbers, as falcon_api.h gives them */
    public static function gameMode(int $falcon) : ?GameMode{
        return match($falcon){
            0 => GameMode::SURVIVAL,
            1 => GameMode::CREATIVE,
            2 => GameMode::ADVENTURE,
            6 => GameMode::SPECTATOR,
            default => null,
        };
    }

    public static function falconGameMode(GameMode $gameMode) : int{
        return match($gameMode){
            GameMode::SURVIVAL => 0,
            GameMode::CREATIVE => 1,
            GameMode::ADVENTURE => 2,
            GameMode::SPECTATOR => 6,
        };
    }

    /**
     * a listener changed the packet itself, as Commando-style hooks edit AvailableCommandsPacket: Falcon sends the
     * changed one. A packet BedrockProtocol does not write back byte for byte is compared with a fresh read of the
     * original, so only a real change replaces Falcon's
     */
    private static function applyPacketChange(int $handle, array $state) : void{
        [, $payload] = Packets::encode($state['packets'][0]);
        if($payload === $state['payload']){
            return;
        }
        $original = Packets::decode($state['packetId'], $state['payload']);
        if($original === null || Packets::encode($original)[1] !== $payload){
            falcon_event_set_packet($handle, $payload);
        }
    }

    private static function callListeners(Event $event, int $priority) : void{
        foreach(HandlerListManager::global()->getHandlersFor($event::class) as $listener){
            if($listener->getPriority() !== $priority){
                continue;
            }
            try{
                if(!falcon_guard(static fn() => $listener->callEvent($event))){
                    Log::critical('A listener of ' . $event->getEventName() . ' from '
                        . $listener->getPlugin()->getName() . ' stopped on a fatal error or exit(), see above');
                }
            }catch(\Throwable $e){
                Log::critical('A listener of ' . $event->getEventName() . ' from ' . $listener->getPlugin()->getName()
                    . ' failed: ' . $e->getMessage());
                Log::exception($e);
            }
        }
    }

    private static function text(Translatable|string $message) : string{
        return $message instanceof Translatable ? Server::getInstance()->getLanguage()->translate($message) : $message;
    }

    private static function applyToFalcon(int $type, int $handle, bool $last) : void{
        $state = &self::$inFlight[$handle];
        $event = $state['event'];
        if($event instanceof Cancellable){
            falcon_event_set_cancelled($handle, $event->isCancelled());
        }
        if(isset(WorldEvents::SOURCES[$type])){
            WorldEvents::apply($handle, $state, $last);
            return;
        }
        if($last && !($event instanceof Cancellable && $event->isCancelled())){
            if($event instanceof PlayerToggleSneakEvent){
                $event->getPlayer()->onToggledByFalcon(sneaking: $event->isSneaking());
            }elseif($event instanceof PlayerToggleSprintEvent){
                $event->getPlayer()->onToggledByFalcon(sprinting: $event->isSprinting());
            }elseif($event instanceof PlayerToggleFlightEvent){
                $event->getPlayer()->onToggledByFalcon(flying: $event->isFlying());
            }
        }
        if($event instanceof PlayerJoinEvent){
            self::applyMessage($handle, $state, self::text($event->getJoinMessage()));
        }elseif($event instanceof PlayerQuitEvent){
            self::applyMessage($handle, $state, self::text($event->getQuitMessage()));
        }elseif($event instanceof CommandEvent){
            self::applyMessage($handle, $state, $event->getCommand());
        }elseif($event instanceof PlayerLoginEvent){
            self::applyMessage($handle, $state, self::text($event->getKickMessage()));
            if($last && $event->isCancelled()){
                Players::leave(falcon_event_player($handle));
            }
        }elseif($event instanceof PlayerEmoteEvent){
            self::applyMessage($handle, $state, $event->getEmoteId());
        }elseif($event instanceof PlayerMoveEvent || $event instanceof EntityTeleportEvent || $event instanceof PlayerRespawnEvent){
            $to = $event instanceof PlayerRespawnEvent ? $event->getRespawnPosition() : $event->getTo();
            if([$to->x, $to->y, $to->z] !== $state['to']){
                falcon_event_set_to($handle, $to->x, $to->y, $to->z);
                $state['to'] = [$to->x, $to->y, $to->z];
            }
        }elseif($event instanceof EntityDamageEvent){
            $amount = $event->getFinalDamage();
            if($amount !== $state['amount']){
                falcon_event_set_amount($handle, $amount);
                $state['amount'] = $amount;
            }
            if($event instanceof EntityDamageByEntityEvent && $state['knockback'] !== null){
                $knockback = [$event->getKnockBack(), $event->getVerticalKnockBackLimit()];
                if($knockback !== $state['knockback']){
                    falcon_event_set_knockback($handle, $knockback[0], $knockback[1]);
                    $state['knockback'] = $knockback;
                }
            }
        }elseif($event instanceof PlayerDeathEvent){
            self::applyMessage($handle, $state, self::text($event->getDeathMessage()));
            self::applyDrops($handle, $state, $event->getDrops(), $event->getXpDropAmount());
            $keep = [$event->getKeepInventory(), $event->getKeepXp()];
            if($keep !== $state['keep']){
                falcon_event_set_keep($handle, $keep[0], $keep[1]);
                $state['keep'] = $keep;
            }
        }elseif($event instanceof EntityDeathEvent){
            self::applyDrops($handle, $state, $event->getDrops(), $event->getXpDropAmount());
        }elseif($event instanceof BlockBreakEvent){
            self::applyDrops($handle, $state, $event->getDrops(), $event->getXpDropAmount());
        }elseif($event instanceof DataPacketSendEvent && $last && !$event->isCancelled() && $event->getPackets() !== $state['packets']){
            // other packets in its place: they go out here, the one Falcon held does not
            falcon_event_set_cancelled($handle, true);
            foreach($event->getTargets() as $target){
                foreach($event->getPackets() as $packet){
                    $target->sendWithoutEvent($packet);
                }
            }
        }elseif($event instanceof DataPacketSendEvent && $last && !$event->isCancelled()){
            self::applyPacketChange($handle, $state);
        }elseif($event instanceof InventoryOpenEvent && $last && !$event->isCancelled() && isset($state['inventory'])){
            $event->getPlayer()->onWindowOpenedByFalcon($state['inventory']);
        }elseif($event instanceof SignChangeEvent){
            self::applyMessage($handle, $state, implode("\n", $event->getNewText()->getLines()));
        }elseif($event instanceof PlayerChatEvent){
            if($event->getMessage() !== $state['message']){
                falcon_event_set_message($handle, $event->getMessage());
                $state['message'] = $event->getMessage();
            }
            if($last && !$event->isCancelled() && self::needsOwnBroadcast($event, $state)){
                // a format, a name or recipients Falcon's chat would not give: the message goes out from here
                falcon_event_set_cancelled($handle, true);
                $line = $event->getFormatter()->format($event->getPlayer()->getDisplayName(), $event->getMessage());
                Server::getInstance()->broadcastMessage($line, $event->getRecipients());
            }
        }
    }

    /** @param array{message: string} $state */
    private static function applyMessage(int $handle, array &$state, string $message) : void{
        if($message !== $state['message']){
            falcon_event_set_message($handle, $message);
            $state['message'] = $message;
        }
    }

    /** @param array{recipients: object[], sender: ?object} $state */
    private static function needsOwnBroadcast(PlayerChatEvent $event, array $state) : bool{
        $player = $event->getPlayer();
        if(get_class($event->getFormatter()) !== StandardChatFormatter::class || $player !== $state['sender']
            || $player->getDisplayName() !== $player->getName()){
            return true;
        }
        $recipients = $event->getRecipients();
        if(count($recipients) !== count($state['recipients'])){
            return true;
        }
        foreach($recipients as $recipient){
            if(!in_array($recipient, $state['recipients'], true)){
                return true;
            }
        }
        return false;
    }

    /**
     * A PocketMine-MP command Falcon received, under the label it was registered with.
     *
     * @param string[] $args
     */
    public static function command(string $label, int $sender, array $args) : bool{
        try{
            $server = Server::getInstance();
            $command = $server->getCommandMap()->getCommand($label);
            if($command === null){
                return false;
            }
            $player = falcon_sender_player($sender);
            $source = $player !== 0 ? Players::get($player) : $server->getConsoleSender();
            $server->getCommandMap()->execute($source, $command, $label, $args);
            return true;
        }catch(\Throwable $e){
            Log::critical("Could not run /$label: " . $e->getMessage());
            Log::exception($e);
            return true;
        }
    }
}
