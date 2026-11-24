<?php

declare(strict_types=1);

namespace pocketmine\player;

use pocketmine\command\CommandSender;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Human;
use pocketmine\entity\Location;
use pocketmine\event\inventory\InventoryCloseEvent;
use pocketmine\event\inventory\InventoryOpenEvent;
use pocketmine\event\player\PlayerDisplayNameChangeEvent;
use pocketmine\event\player\PlayerKickEvent;
use pocketmine\form\Form;
use pocketmine\form\FormValidationException;
use pocketmine\inventory\ArmorInventory;
use pocketmine\inventory\Inventory;
use pocketmine\inventory\PlayerCursorInventory;
use pocketmine\inventory\PlayerEnderInventory;
use pocketmine\inventory\PlayerInventory;
use pocketmine\inventory\PlayerOffHandInventory;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\NetworkSession;
use pocketmine\network\mcpe\protocol\SetActorDataPacket;
use pocketmine\network\mcpe\protocol\SetTitlePacket;
use pocketmine\network\mcpe\protocol\TextPacket;
use pocketmine\network\mcpe\protocol\ToastRequestPacket;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\network\mcpe\protocol\types\entity\PropertySyncData;
use pocketmine\network\mcpe\protocol\types\entity\StringMetadataProperty;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use pocketmine\lang\KnownTranslationFactory;
use pocketmine\lang\Language;
use pocketmine\lang\Translatable;
use pocketmine\permission\DefaultPermissions;
use pocketmine\permission\PermissibleBase;
use pocketmine\permission\PermissibleDelegateTrait;
use pocketmine\permission\Permission;
use pocketmine\Server;
use pocketmine\utils\TextFormat;
use pocketmine\world\Position;
use pocketmine\world\World;

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

    private const MAX_FORM_RESPONSE_SIZE = 10 * 1024;
    private const MAX_FORM_RESPONSE_DEPTH = 2;

    /** Whether the player has entered the world, as expected by older plugins. */
    public bool $spawned = false;
    private bool $online = true;
    private string $username;
    private string $displayName;
    private string $playerNameTag;
    private ?Location $offlineLocation = null;
    private GameMode $offlineGameMode = GameMode::SURVIVAL;
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
    private int $handle;
    /** @var Form[] by Falcon's form id */
    private array $forms = [];
    /** @var array<string, true> by target UUID bytes */
    private array $hiddenPlayers = [];
    /** @var array<string, true> Visibility cleared while the target was offline. */
    private array $showOnJoin = [];

    /** @internal the runtime makes one for each of Falcon's players */
    public function __construct(
        Server $server,
        int|NetworkSession $connection,
        ?PlayerInfo $playerInfo = null,
        bool $authenticated = false,
        ?Location $location = null,
        ?CompoundTag $offlinePlayerData = null,
    ){
        $handle = $connection instanceof NetworkSession ? $connection->getFalconHandle() : $connection;
        $this->handle = $handle;
        parent::__construct(falcon_entity_runtime_id(falcon_player_entity($handle)));
        $this->server = $server;
        $this->inventory = new PlayerInventory($this);
        $this->armorInventory = new ArmorInventory($this);
        $this->offHandInventory = new PlayerOffHandInventory($this);
        $this->enderInventory = new PlayerEnderInventory($this);
        $this->cursorInventory = new PlayerCursorInventory($this);
        $this->networkSession = $connection instanceof NetworkSession ? $connection : new NetworkSession($this);
        $this->username = $playerInfo?->getUsername() ?? falcon_player_name($handle);
        $this->displayName = $this->username;
        $this->playerNameTag = $this->username;
        $uuid = falcon_player_uuid($handle);
        $this->uuid = Uuid::isValid($uuid) ? Uuid::fromString($uuid) : Uuid::uuid3(Uuid::NIL, $this->username);
        $this->xuid = falcon_player_xuid($handle);
        if($connection instanceof NetworkSession){
            $connection->attachPlayer($this);
        }
        $this->perm = new PermissibleBase([DefaultPermissions::ROOT_USER => true]);
        $this->syncOperator();
        // as in PocketMine-MP: a change of permissions sends the commands again
        $this->getPermissionRecalculationCallbacks()->add(function(array $changed) : void{
            $this->networkSession->syncAvailableCommands();
        });
    }

    /** @internal */
    public function onSessionCreated(NetworkSession $session) : void{
        if($this->networkSession !== $session){
            $this->networkSession = $session;
            $session->attachPlayer($this);
        }
    }

    /** @internal the player left: nothing reaches Falcon's player from now on */
    public function onLeave(bool $captureState = true) : void{
        if($captureState && $this->online){
            $location = parent::getLocation();
            if($location->isValid()){
                $this->offlineLocation = $location;
            }
            $this->offlineGameMode = $this->getGamemode();
            $this->flying = $this->isFlying();
            $this->allowFlight = $this->getAllowFlight();
            parent::setScale($this->getScale());
        }else{
            $this->online = false;
        }
        if($this->currentWindow !== null){
            $this->currentWindow->onClose($this);
            $this->currentWindow = null;
        }
        $this->online = false;
        $this->spawned = false;
        $this->forms = [];
        $this->hiddenPlayers = [];
        $this->showOnJoin = [];
    }

    /** @internal the Falcon handle while online */
    public function getHandle() : int{
        return $this->handle;
    }

    /** @internal a player's body is theirs while they are online */
    public function getEntityHandle() : int{
        return $this->online ? falcon_player_entity($this->handle) : 0;
    }

    public function canSee(Player $player) : bool{
        return !isset($this->hiddenPlayers[$player->getUniqueId()->getBytes()]);
    }

    public function hidePlayer(Player $player) : void{
        if($player === $this){
            return;
        }
        $uuid = $player->getUniqueId()->getBytes();
        $this->hiddenPlayers[$uuid] = true;
        unset($this->showOnJoin[$uuid]);
        if($this->isOnline() && $player->isOnline()){
            falcon_player_set_visible_to($player->handle, $this->handle, false);
        }
    }

    public function showPlayer(Player $player) : void{
        if($player === $this){
            return;
        }
        $uuid = $player->getUniqueId()->getBytes();
        $wasHidden = isset($this->hiddenPlayers[$uuid]);
        unset($this->hiddenPlayers[$uuid]);
        if($this->isOnline() && $player->isOnline()){
            falcon_player_set_visible_to($player->handle, $this->handle, true);
        }elseif($wasHidden){
            $this->showOnJoin[$uuid] = true;
        }
    }

    /** @internal Reapply visibility decided while the target was offline. */
    public function syncJoinVisibility(Player $player) : void{
        $uuid = $player->getUniqueId()->getBytes();
        if(isset($this->hiddenPlayers[$uuid])){
            falcon_player_set_visible_to($player->handle, $this->handle, false);
        }elseif(isset($this->showOnJoin[$uuid])){
            falcon_player_set_visible_to($player->handle, $this->handle, true);
            unset($this->showOnJoin[$uuid]);
        }
    }

    public function spawnTo(Player $player) : void{
        if($player !== $this && $this->isOnline() && $player->isOnline() && $player->canSee($this)
            && $this->isAlive() && $player->isAlive() && !$this->isSpectator()){
            falcon_player_spawn_to($this->handle, $player->handle);
        }
    }

    public function despawnFrom(Player $player, bool $send = true) : void{
        if($player !== $this && $this->isOnline() && $player->isOnline()){
            falcon_player_despawn_from($this->handle, $player->handle, $send);
        }
    }

    public function spawnToAll() : void{
        if($this->isOnline()){
            foreach($this->getWorld()->getViewersForPosition($this->getPosition()) as $viewer){
                $this->spawnTo($viewer);
            }
        }
    }

    public function despawnFromAll() : void{
        foreach($this->getViewers() as $viewer){
            $this->despawnFrom($viewer);
        }
    }

    public function respawnToAll() : void{
        foreach($this->getViewers() as $viewer){
            $this->despawnFrom($viewer);
            $this->spawnTo($viewer);
        }
    }

    /** @return Player[] */
    public function getViewers() : array{
        if(!$this->isOnline()){
            return [];
        }
        return array_filter(parent::getViewers(), fn(Player $viewer) => $viewer !== $this && $viewer->canSee($this)
            && falcon_player_is_spawned_to($this->handle, $viewer->handle));
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

    public function getPlayerInfo() : PlayerInfo{
        return new PlayerInfo($this->getName(), $this->getUniqueId(), $this->getSkin(), $this->getLocale(),
            ["DeviceOS" => $this->getDeviceOS()]);
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
        if(!$this->networkSession->getInvManager()->onCurrentWindowChange($inventory)){
            return false;
        }
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
        $this->currentWindow = null;
        $window->onClose($this);
        $this->networkSession->getInvManager()?->onCurrentWindowRemove();
        (new InventoryCloseEvent($window, $this))->call();
    }

    /** whether the client holds that chunk of the player's world, so entities in it can be shown */
    /** @internal Falcon streams requested and changed columns continuously. */
    public function doChunkRequests() : void{
    }

    public function hasReceivedChunk(int $chunkX, int $chunkZ) : bool{
        return $this->online && falcon_player_has_chunk($this->handle, $chunkX, $chunkZ);
    }

    public function isUsingChunk(int $chunkX, int $chunkZ) : bool{
        return $this->hasReceivedChunk($chunkX, $chunkZ);
    }

    public function getNetworkSession() : NetworkSession{
        return $this->networkSession;
    }

    public function getUniqueId() : UuidInterface{
        return $this->uuid;
    }

    /** shows the form; Falcon numbers it, and its answer comes back to handleResponse() */
    public function sendForm(Form $form) : void{
        if(!$this->online){
            return;
        }
        $json = json_encode($form, JSON_THROW_ON_ERROR);
        if(!$this->online){
            return;
        }
        $id = falcon_player_send_form($this->handle, $json);
        if($id !== 0){
            $this->forms[$id] = $form;
        }
    }

    /** @internal Falcon distinguishes a cancellation from an answer containing JSON */
    public function onFalconFormResponse(int $formId, string $json, bool $cancelled) : void{
        if(!$this->hasPendingForm($formId)){
            return;
        }
        if(!$cancelled && strlen($json) > self::MAX_FORM_RESPONSE_SIZE){
            unset($this->forms[$formId]);
            $this->getServer()->getLogger()->warning("Response for form $formId exceeds the maximum size");
            return;
        }
        try{
            $response = $cancelled ? null : json_decode($json, true, self::MAX_FORM_RESPONSE_DEPTH, JSON_THROW_ON_ERROR);
        }catch(\JsonException $e){
            unset($this->forms[$formId]);
            $this->getServer()->getLogger()->warning("Invalid JSON for form $formId: " . $e->getMessage());
            return;
        }
        $this->onFormSubmit($formId, $response);
    }

    /** @internal the player answered or closed a form */
    public function onFormSubmit(int $formId, mixed $responseData) : bool{
        if(!isset($this->forms[$formId])){
            return false;
        }
        $form = $this->forms[$formId];
        unset($this->forms[$formId]);
        try{
            $form->handleResponse($this, $responseData);
        }catch(FormValidationException $e){
            $this->getServer()->getLogger()->critical("Failed to validate form " . get_class($form) . ": " . $e->getMessage());
            $this->getServer()->getLogger()->logException($e);
        }
        return true;
    }

    public function hasPendingForm(int $formId) : bool{
        return isset($this->forms[$formId]);
    }

    public function closeAllForms() : void{
        if($this->online && !falcon_player_close_forms($this->handle)){
            $this->networkSession->onCloseAllForms();
        }
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
        $event = new PlayerDisplayNameChangeEvent($this, $this->displayName, $name);
        $event->call();
        $this->displayName = $event->getNewName();
    }

    public function getNameTag() : string{
        return $this->playerNameTag;
    }

    public function setNameTag(string $name) : void{
        $this->playerNameTag = $name;
        if(!$this->online || falcon_player_set_name_tag($this->handle, $name)){
            return;
        }
        $packet = SetActorDataPacket::create($this->getId(), [
            EntityMetadataProperties::NAMETAG => new StringMetadataProperty($name)
        ], new PropertySyncData([], []), $this->server->getTick());
        foreach($this->getViewers() as $viewer){
            if($viewer !== $this){
                $viewer->getNetworkSession()->sendDataPacket($packet);
            }
        }
    }

    public function getLocation() : Location{
        return $this->offlineLocation?->asLocation() ?? parent::getLocation();
    }

    public function getPosition() : Position{
        return $this->offlineLocation?->asPosition() ?? parent::getPosition();
    }

    public function getWorld() : World{
        return $this->offlineLocation?->getWorld() ?? parent::getWorld();
    }

    public function getGamemode() : GameMode{
        if(!$this->online){
            return $this->offlineGameMode;
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
    private ?bool $flying = null;
    private ?bool $allowFlight = null;
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
        if($this->online && function_exists('falcon_player_is_flying')){
            $flying = falcon_player_is_flying($this->handle);
            if($flying !== null){
                return $flying;
            }
        }
        return $this->flying ?? $this->isSpectator();
    }

    public function setFlying(bool $flying) : void{
        $this->flying = $flying;
        if($this->online && function_exists('falcon_player_set_flying')){
            falcon_player_set_flying($this->handle, $flying);
        }
    }

    public function getAllowFlight() : bool{
        if($this->online && function_exists('falcon_player_allow_flight')){
            $allowed = falcon_player_allow_flight($this->handle);
            if($allowed !== null){
                return $allowed;
            }
        }
        return $this->allowFlight ?? ($this->isCreative() || $this->isSpectator());
    }

    public function setAllowFlight(bool $value) : void{
        $this->allowFlight = $value;
        if($this->online && function_exists('falcon_player_set_allow_flight')){
            falcon_player_set_allow_flight($this->handle, $value);
        }
    }

    public function getScale() : float{
        return ($this->online && function_exists('falcon_player_scale') ? falcon_player_scale($this->handle) : null)
            ?? parent::getScale();
    }

    public function setScale(float $value) : void{
        if($this->online && function_exists('falcon_player_set_scale') && falcon_player_set_scale($this->handle, $value) === false){
            throw new \InvalidArgumentException('Invalid native player scale');
        }
        parent::setScale($value);
    }

    public function getSize() : EntitySizeInfo{
        $scale = $this->getScale();
        $size = $this->getInitialSizeInfo();
        if($this->isSwimming() || $this->isGliding()){
            $width = $size->getWidth();
            $size = new EntitySizeInfo($width, $width, $width * 0.9);
        }elseif($this->isSneaking()){
            $size = new EntitySizeInfo(1.49, $size->getWidth(), $size->getEyeHeight() - 0.35);
        }
        return $size->scale($scale);
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
        if($ev->isCancelled() || !$this->online || $ev->getPort() < 1 || $ev->getPort() > 65535){
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
        return $this->online ? falcon_player_ping($this->handle) : null;
    }

    public function getFirstPlayed() : ?int{
        return \pmmpcompat\PlayerHistory::session($this)["first"] ?? null;
    }

    public function getLastPlayed() : ?int{
        return \pmmpcompat\PlayerHistory::session($this)["last"] ?? null;
    }

    public function hasPlayedBefore() : bool{
        return \pmmpcompat\PlayerHistory::session($this)["returning"] ?? false;
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
            $this->syncOperator();
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
        $node = $name instanceof Permission ? $name->getName() : $name;
        $native = $this->nativePermissionOverride($node);
        if($native === -1){
            return false;
        }
        $local = $this->getEffectivePermissions()[$node] ?? null;
        if($local?->getAttachment() !== null){
            return $local->getValue();
        }
        if($native === 1){
            return true;
        }
        if($this->permissibleIsPermissionSet($name)){
            return $this->permissibleHasPermission($name);
        }
        return $native === null && $this->legacyFalconPermission($node);
    }

    public function isPermissionSet(Permission|string $name) : bool{
        $this->syncOperator();
        $node = $name instanceof Permission ? $name->getName() : $name;
        $native = $this->nativePermissionOverride($node);
        return $this->permissibleIsPermissionSet($name)
            || ($native === null ? $this->legacyFalconPermission($node) : $native !== 0);
    }

    private function nativePermissionOverride(string $node) : ?int{
        return $this->online && $node !== "" && !str_contains($node, "\0")
            ? falcon_player_permission_override($this->handle, $node) : 0;
    }

    private function legacyFalconPermission(string $node) : bool{
        return !$this->operator && falcon_player_has_permission($this->handle, $node);
    }

    private function text(Translatable|string $message) : string{
        return $message instanceof Translatable ? $this->getLanguage()->translate($message) : $message;
    }

    public function sendMessage(Translatable|string $message) : void{
        if(!$this->online){
            return;
        }
        if($message instanceof Translatable && !$this->server->isLanguageForced()){
            $language = $this->getLanguage();
            $parameters = array_map(fn(Translatable|string $parameter) => $this->text($parameter), $message->getParameters());
            $parameterCount = 0;
            $key = $language->translateString($message->getText(), $parameters, "pocketmine.", $parameterCount);
            $packet = TextPacket::translation($key, array_slice($parameters, 0, $parameterCount));
        }else{
            $packet = TextPacket::raw($this->text($message));
        }
        $this->networkSession->sendDataPacket($packet);
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

    public function sendTitle(string $title, string $subtitle = "", int $fadeIn = -1, int $stay = -1, int $fadeOut = -1) : void{
        $this->setTitleDuration($fadeIn, $stay, $fadeOut);
        if($subtitle !== ""){
            $this->sendSubTitle($subtitle);
        }
        $this->networkSession->sendDataPacket(SetTitlePacket::title($title));
    }

    public function sendSubTitle(string $subtitle) : void{
        $this->networkSession->sendDataPacket(SetTitlePacket::subtitle($subtitle));
    }

    public function setTitleDuration(int $fadeIn, int $stay, int $fadeOut) : void{
        if($fadeIn >= 0 && $stay >= 0 && $fadeOut >= 0){
            $this->networkSession->sendDataPacket(SetTitlePacket::setAnimationTimes($fadeIn, $stay, $fadeOut));
        }
    }

    public function removeTitles() : void{
        $this->networkSession->sendDataPacket(SetTitlePacket::clearTitle());
    }

    public function resetTitles() : void{
        $this->networkSession->sendDataPacket(SetTitlePacket::resetTitleOptions());
    }

    public function sendActionBarMessage(string $message) : void{
        $this->networkSession->sendDataPacket(SetTitlePacket::actionBarMessage($message));
    }

    public function sendTip(string $message) : void{
        $this->networkSession->sendDataPacket(TextPacket::tip($message));
    }

    public function sendPopup(string $message) : void{
        $this->networkSession->sendDataPacket(TextPacket::popup($message));
    }

    public function sendToastNotification(string $title, string $body) : void{
        $this->networkSession->sendDataPacket(ToastRequestPacket::create($title, $body));
    }

    /**
     * @param Translatable|string|null $quitMessage the leave message everyone sees, null for the usual one
     */
    public function kick(Translatable|string $reason = "", Translatable|string|null $quitMessage = null, Translatable|string|null $disconnectScreenMessage = null) : bool{
        if(!$this->online){
            return false;
        }
        $defaultQuit = KnownTranslationFactory::multiplayer_player_left($this->getDisplayName());
        $event = new PlayerKickEvent($this, $reason, $quitMessage ?? $defaultQuit, $disconnectScreenMessage);
        $event->call();
        if($event->isCancelled() || !$this->online){
            return false;
        }
        $screenMessage = $event->getDisconnectScreenMessage();
        $this->disconnect(
            $event->getDisconnectReason(),
            $event->getQuitMessage() === $defaultQuit ? null : $event->getQuitMessage(),
            $screenMessage === "" ? KnownTranslationFactory::disconnectionScreen_noReason() : $screenMessage
        );
        return true;
    }

    public function disconnect(Translatable|string $reason, Translatable|string|null $quitMessage = null, Translatable|string|null $disconnectScreenMessage = null) : void{
        if($this->online){
            $this->quitMessage = $quitMessage;
            falcon_player_kick($this->handle, $this->text($disconnectScreenMessage ?? $reason));
        }
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
