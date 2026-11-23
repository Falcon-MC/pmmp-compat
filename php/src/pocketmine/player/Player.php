<?php

declare(strict_types=1);

namespace pocketmine\player;

use pocketmine\command\CommandSender;
use pocketmine\entity\Human;
use pocketmine\form\Form;
use pocketmine\form\FormValidationException;
use pocketmine\event\inventory\InventoryCloseEvent;
use pocketmine\event\inventory\InventoryOpenEvent;
use pocketmine\inventory\ArmorInventory;
use pocketmine\inventory\Inventory;
use pocketmine\inventory\PlayerCursorInventory;
use pocketmine\inventory\PlayerEnderInventory;
use pocketmine\inventory\PlayerInventory;
use pocketmine\inventory\PlayerOffHandInventory;
use pocketmine\network\mcpe\NetworkSession;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use pocketmine\lang\Language;
use pocketmine\lang\Translatable;
use pocketmine\permission\DefaultPermissions;
use pocketmine\permission\PermissibleBase;
use pocketmine\permission\PermissibleDelegateTrait;
use pocketmine\permission\Permission;
use pocketmine\Server;
use pocketmine\utils\TextFormat;

/**
 * A player on the server, standing for one of Falcon's players.
 *
 * The object lives on after the player leaves, as in PocketMine-MP: isOnline() turns false and what would reach the
 * player is dropped. Operators get the operator permissions, as op status gives them in PocketMine-MP.
 */
class Player extends Human implements CommandSender, IPlayer{
    use PermissibleDelegateTrait{
        hasPermission as private permissibleHasPermission;
        isPermissionSet as private permissibleIsPermissionSet;
    }

    private bool $online = true;
    private string $username;
    private string $displayName;
    private string $xuid;
    private bool $operator = false;
    private ?int $lineHeight = null;
    /** what the next leave message is to be, when a kick names one */
    private Translatable|string|null $quitMessage = null;
    protected PlayerInventory $inventory;
    protected ArmorInventory $armorInventory;
    protected PlayerOffHandInventory $offHandInventory;
    protected PlayerEnderInventory $enderInventory;
    private PlayerCursorInventory $cursorInventory;
    private ?Inventory $currentWindow = null;
    private NetworkSession $networkSession;
    /** @var Form[] by Falcon's form id */
    private array $forms = [];

    /** @internal the runtime makes one for each of Falcon's players */
    public function __construct(
        Server $server,
        private int $handle
    ){
        parent::__construct(falcon_entity_runtime_id(falcon_player_entity($handle)));
        $this->server = $server;
        $this->inventory = new PlayerInventory($this);
        $this->armorInventory = new ArmorInventory($this);
        $this->offHandInventory = new PlayerOffHandInventory($this);
        $this->enderInventory = new PlayerEnderInventory($this);
        $this->cursorInventory = new PlayerCursorInventory($this);
        $this->networkSession = new NetworkSession($this);
        $this->username = falcon_player_name($handle);
        $this->displayName = $this->username;
        $this->xuid = falcon_player_xuid($handle);
        $this->perm = new PermissibleBase([DefaultPermissions::ROOT_USER => true]);
        $this->syncOperator();
    }

    /** @internal the player left: nothing reaches Falcon's player from now on */
    public function onLeave() : void{
        if($this->currentWindow !== null){
            $this->currentWindow->onClose($this);
            $this->currentWindow = null;
        }
        $this->online = false;
    }

    /** @internal the Falcon handle while online */
    public function getHandle() : int{
        return $this->handle;
    }

    /** @internal a player's body is theirs while they are online */
    public function getEntityHandle() : int{
        return $this->online ? falcon_player_entity($this->handle) : 0;
    }

    public function getInventory() : PlayerInventory{
        return $this->inventory;
    }

    public function getArmorInventory() : ArmorInventory{
        return $this->armorInventory;
    }

    public function getOffHandInventory() : PlayerOffHandInventory{
        return $this->offHandInventory;
    }

    public function getEnderInventory() : PlayerEnderInventory{
        return $this->enderInventory;
    }

    public function getCursorInventory() : PlayerCursorInventory{
        return $this->cursorInventory;
    }

    public function getCurrentWindow() : ?Inventory{
        return $this->currentWindow;
    }

    /** opens the inventory as a window; false when an InventoryOpenEvent listener refused it */
    public function setCurrentWindow(Inventory $inventory) : bool{
        if($inventory === $this->currentWindow){
            return true;
        }
        $event = new InventoryOpenEvent($inventory, $this);
        $event->call();
        if($event->isCancelled()){
            return false;
        }
        $this->removeCurrentWindow();
        if(!$this->online){
            throw new \InvalidArgumentException("Player cannot open inventories in this state");
        }
        $this->networkSession->getInvManager()->onCurrentWindowChange($inventory);
        $inventory->onOpen($this);
        $this->currentWindow = $inventory;
        return true;
    }

    /**
     * @internal a window Falcon opened, a container block the player used: it is open already
     */
    public function onWindowOpenedByFalcon(Inventory $inventory) : void{
        $this->currentWindow = $inventory;
        $inventory->onOpen($this);
    }

    public function removeCurrentWindow() : void{
        if($this->currentWindow === null){
            return;
        }
        $window = $this->currentWindow;
        $window->onClose($this);
        $this->networkSession->getInvManager()->onCurrentWindowRemove();
        $this->currentWindow = null;
        (new InventoryCloseEvent($window, $this))->call();
    }

    /** whether the client holds that chunk of the player's world, so entities in it can be shown */
    public function hasReceivedChunk(int $chunkX, int $chunkZ) : bool{
        return $this->online && falcon_player_has_chunk($this->handle, $chunkX, $chunkZ);
    }

    public function getNetworkSession() : NetworkSession{
        return $this->networkSession;
    }

    public function getUniqueId() : UuidInterface{
        $uuid = $this->online ? falcon_player_uuid($this->handle) : "";
        return Uuid::isValid($uuid) ? Uuid::fromString($uuid) : Uuid::uuid3(Uuid::NIL, $this->username);
    }

    /** shows the form; Falcon numbers it, and its answer comes back to handleResponse() */
    public function sendForm(Form $form) : void{
        if(!$this->online){
            return;
        }
        $json = json_encode($form, JSON_THROW_ON_ERROR);
        $id = falcon_player_send_form($this->handle, $json);
        if($id !== 0){
            $this->forms[$id] = $form;
        }
    }

    /** @internal the player answered or closed a form */
    public function onFormSubmit(int $formId, mixed $responseData) : bool{
        if(!isset($this->forms[$formId])){
            return false;
        }
        try{
            $this->forms[$formId]->handleResponse($this, $responseData);
        }catch(FormValidationException $e){
            $this->getServer()->getLogger()->critical("Failed to validate form " . get_class($this->forms[$formId]) . ": " . $e->getMessage());
            $this->getServer()->getLogger()->logException($e);
        }finally{
            unset($this->forms[$formId]);
        }
        return true;
    }

    public function hasPendingForm(int $formId) : bool{
        return isset($this->forms[$formId]);
    }

    public function closeAllForms() : void{
        $this->networkSession->onCloseAllForms();
    }

    /** @internal the leave message a kick asked for, once */
    public function takeQuitMessage() : Translatable|string|null{
        $message = $this->quitMessage;
        $this->quitMessage = null;
        return $message;
    }

    public function isOnline() : bool{
        return $this->online;
    }

    public function isConnected() : bool{
        return $this->online;
    }

    public function getServer() : Server{
        return $this->server;
    }

    public function getName() : string{
        return $this->username;
    }

    public function getDisplayName() : string{
        return $this->displayName;
    }

    public function setDisplayName(string $name) : void{
        $this->displayName = $name;
    }

    public function getGamemode() : GameMode{
        if(!$this->online){
            return GameMode::SURVIVAL;
        }
        return \pmmpcompat\Bridge::gameMode(falcon_player_game_mode($this->handle)) ?? GameMode::SURVIVAL;
    }

    /** false when the player is offline or already in that game mode, as in PocketMine-MP */
    public function setGamemode(GameMode $gameMode) : bool{
        if(!$this->online || $this->getGamemode() === $gameMode){
            return false;
        }
        falcon_player_set_game_mode($this->handle, \pmmpcompat\Bridge::falconGameMode($gameMode));
        return $this->getGamemode() === $gameMode;
    }

    public function isSurvival(bool $literal = false) : bool{
        $mode = $this->getGamemode();
        return $mode === GameMode::SURVIVAL || (!$literal && $mode === GameMode::ADVENTURE);
    }

    public function isCreative(bool $literal = false) : bool{
        $mode = $this->getGamemode();
        return $mode === GameMode::CREATIVE || (!$literal && $mode === GameMode::SPECTATOR);
    }

    public function isAdventure(bool $literal = false) : bool{
        $mode = $this->getGamemode();
        return $mode === GameMode::ADVENTURE || (!$literal && $mode === GameMode::SPECTATOR);
    }

    /** survival and adventure players run out of items; creative and spectator ones do not */
    public function hasFiniteResources() : bool{
        $mode = $this->getGamemode();
        return $mode === GameMode::SURVIVAL || $mode === GameMode::ADVENTURE;
    }

    public function isSpectator() : bool{
        return $this->getGamemode() === GameMode::SPECTATOR;
    }

    public function getXuid() : string{
        return $this->xuid;
    }

    public function isAuthenticated() : bool{
        return $this->xuid !== "";
    }

    protected bool $sneaking = false;
    protected bool $sprinting = false;
    private bool $flying = false;
    private bool $allowFlight = false;
    private int $viewDistance = 8;

    /** @internal what the player toggled, once no listener refused it */
    public function onToggledByFalcon(?bool $sneaking = null, ?bool $sprinting = null, ?bool $flying = null) : void{
        $this->sneaking = $sneaking ?? $this->sneaking;
        $this->sprinting = $sprinting ?? $this->sprinting;
        $this->flying = $flying ?? $this->flying;
    }

    public function isSneaking() : bool{
        return $this->sneaking;
    }

    public function setSneaking(bool $value = true) : void{
        $this->sneaking = $value;
    }

    public function isSprinting() : bool{
        return $this->sprinting;
    }

    public function setSprinting(bool $value = true) : void{
        $this->sprinting = $value;
    }

    public function toggleSprint(bool $sprint) : bool{
        $ev = new \pocketmine\event\player\PlayerToggleSprintEvent($this, $sprint);
        $ev->call();
        if($ev->isCancelled()){
            return false;
        }
        $this->sprinting = $sprint;
        return true;
    }

    public function isFlying() : bool{
        return $this->flying || $this->isSpectator();
    }

    public function setFlying(bool $flying) : void{
        $this->flying = $flying;
    }

    public function getAllowFlight() : bool{
        return $this->allowFlight || $this->isCreative() || $this->isSpectator();
    }

    public function setAllowFlight(bool $value) : void{
        $this->allowFlight = $value;
    }

    public function hasAutoJump() : bool{
        return true;
    }

    public function setAutoJump(bool $value) : void{
    }

    public function getViewDistance() : int{
        return $this->viewDistance;
    }

    public function setViewDistance(int $distance) : void{
        $this->viewDistance = $distance;
    }

    /** the player goes to another server, as /transfer sends them */
    public function transfer(string $address, int $port = 19132, Translatable|string|null $message = null) : bool{
        if(!$this->online){
            return false;
        }
        $ev = new \pocketmine\event\player\PlayerTransferEvent($this, $address, $port, $message ?? "transferred");
        $ev->call();
        if($ev->isCancelled()){
            return false;
        }
        return falcon_player_transfer($this->handle, $ev->getAddress(), $ev->getPort());
    }

    /** where the player respawns: their bed or the point set for them, the world's spawn otherwise */
    public function getSpawn() : \pocketmine\world\Position{
        if($this->online){
            $point = falcon_player_spawn_point($this->handle);
            if($point !== null){
                [$x, $y, $z] = $point;
                return new \pocketmine\world\Position($x + 0.5, $y, $z + 0.5, $this->getWorld());
            }
        }
        return $this->getWorld()->getSpawnLocation();
    }

    public function hasValidCustomSpawn() : bool{
        return $this->online && falcon_player_spawn_point($this->handle) !== null;
    }

    public function setSpawn(?\pocketmine\math\Vector3 $pos) : void{
        if(!$this->online){
            return;
        }
        if($pos === null){
            falcon_player_set_spawn_point($this->handle, 0, 0, 0, 0);
            return;
        }
        $world = $pos instanceof \pocketmine\world\Position && $pos->isValid() ? $pos->getWorld() : $this->getWorld();
        falcon_player_set_spawn_point($this->handle, $world->getHandle(), (int) floor($pos->x), (int) floor($pos->y), (int) floor($pos->z));
    }

    /** the language the player's game is set to, as en_US */
    public function getLocale() : string{
        return $this->online ? falcon_player_language($this->handle) : "en_US";
    }

    /** the device the player plays on, one of DeviceOS's numbers */
    public function getDeviceOS() : int{
        return $this->online ? falcon_player_device_os($this->handle) : -1;
    }

    public function getPing() : ?int{
        return null;
    }

    public function getFirstPlayed() : ?int{
        return null;
    }

    public function getLastPlayed() : ?int{
        return null;
    }

    public function hasPlayedBefore() : bool{
        return false;
    }

    public function getArmorPoints() : int{
        return 0;
    }

    public function sendSound(string $sound, float $volume = 1.0, float $pitch = 1.0) : void{
        if($this->online){
            $pos = $this->getPosition();
            falcon_player_play_sound($this->handle, $sound, $pos->x, $pos->y, $pos->z, $volume, $pitch);
        }
    }

    public function getLanguage() : Language{
        return $this->server->getLanguage();
    }

    public function setOp(bool $value) : void{
        if($this->online){
            falcon_player_set_operator($this->handle, $value);
        }
    }

    public function isOp() : bool{
        $this->syncOperator();
        return $this->operator;
    }

    private function syncOperator() : void{
        if(!$this->online){
            return;
        }
        $operator = falcon_player_is_op($this->handle);
        if($operator !== $this->operator){
            $this->operator = $operator;
            if($operator){
                $this->setBasePermission(DefaultPermissions::ROOT_OPERATOR, true);
            }else{
                $this->unsetBasePermission(DefaultPermissions::ROOT_OPERATOR);
            }
        }
    }

    public function hasPermission(Permission|string $name) : bool{
        $this->syncOperator();
        return $this->permissibleHasPermission($name);
    }

    public function isPermissionSet(Permission|string $name) : bool{
        $this->syncOperator();
        return $this->permissibleIsPermissionSet($name);
    }

    private function text(Translatable|string $message) : string{
        return $message instanceof Translatable ? $this->getLanguage()->translate($message) : $message;
    }

    public function sendMessage(Translatable|string $message) : void{
        if($this->online){
            falcon_player_send_message($this->handle, $this->text($message));
        }
    }

    /**
     * Says what a player would type: a line starting with / runs as a command, the others go through Falcon's
     * chat, so PlayerChatEvent fires as for a typed line.
     */
    public function chat(string $message) : bool{
        if(!$this->online){
            return false;
        }
        $message = TextFormat::clean($message, false);
        foreach(explode("\n", $message) as $messagePart){
            if(trim($messagePart) === "" || strlen($messagePart) > 512){
                continue;
            }
            if(str_starts_with($messagePart, "./")){
                $messagePart = substr($messagePart, 1);
            }
            if(str_starts_with($messagePart, "/")){
                $this->server->dispatchCommand($this, substr($messagePart, 1));
            }else{
                falcon_player_chat($this->handle, $messagePart);
            }
        }
        return true;
    }

    /**
     * @param int $fadeIn ticks, -1 for the client's own; Falcon shows titles with the client's timings
     */
    public function sendTitle(string $title, string $subtitle = "", int $fadeIn = -1, int $stay = -1, int $fadeOut = -1) : void{
        if($this->online){
            falcon_player_send_title($this->handle, $title, $subtitle);
        }
    }

    public function sendSubTitle(string $subtitle) : void{
        $this->sendTitle("", $subtitle);
    }

    public function sendActionBarMessage(string $message) : void{
        if($this->online){
            falcon_player_send_action_bar($this->handle, $message);
        }
    }

    public function sendTip(string $message) : void{
        $this->sendActionBarMessage($message);
    }

    public function sendPopup(string $message) : void{
        $this->sendActionBarMessage($message);
    }

    /**
     * @param Translatable|string|null $quitMessage the leave message everyone sees, null for the usual one
     */
    public function kick(Translatable|string $reason = "", Translatable|string|null $quitMessage = null) : bool{
        if(!$this->online){
            return false;
        }
        $this->quitMessage = $quitMessage;
        falcon_player_kick($this->handle, $this->text($reason));
        return true;
    }

    public function disconnect(Translatable|string $reason, Translatable|string|null $quitMessage = null) : void{
        $this->kick($reason, $quitMessage);
    }

    public function getScreenLineHeight() : int{
        return $this->lineHeight ?? 7;
    }

    public function setScreenLineHeight(?int $height) : void{
        if($height !== null && $height < 1){
            throw new \InvalidArgumentException("Line height must be at least 1");
        }
        $this->lineHeight = $height;
    }

    public function __toString() : string{
        return $this->username;
    }
}
