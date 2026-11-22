<?php

declare(strict_types=1);

namespace pocketmine\player;

use pocketmine\command\CommandSender;
use pocketmine\entity\Human;
use pocketmine\inventory\PlayerInventory;
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
class Player extends Human implements CommandSender{
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
    private PlayerInventory $inventory;

    /** @internal the runtime makes one for each of Falcon's players */
    public function __construct(
        private Server $server,
        private int $handle
    ){
        parent::__construct(falcon_entity_runtime_id(falcon_player_entity($handle)));
        $this->inventory = new PlayerInventory($this);
        $this->username = falcon_player_name($handle);
        $this->displayName = $this->username;
        $this->xuid = falcon_player_xuid($handle);
        $this->perm = new PermissibleBase([DefaultPermissions::ROOT_USER => true]);
        $this->syncOperator();
    }

    /** @internal the player left: nothing reaches Falcon's player from now on */
    public function onLeave() : void{
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

    public function isSpectator() : bool{
        return $this->getGamemode() === GameMode::SPECTATOR;
    }

    public function getXuid() : string{
        return $this->xuid;
    }

    public function isAuthenticated() : bool{
        return $this->xuid !== "";
    }

    public function getLanguage() : Language{
        return $this->server->getLanguage();
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
