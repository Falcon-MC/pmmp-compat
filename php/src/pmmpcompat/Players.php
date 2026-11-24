<?php

declare(strict_types=1);

namespace pmmpcompat;

use pocketmine\entity\Location;
use pocketmine\event\player\PlayerCreationEvent;
use pocketmine\network\mcpe\NetworkSession;
use pocketmine\player\Player;
use pocketmine\Server;

/**
 * One Player object for each of Falcon's players, from the moment the runtime first sees them until they leave.
 *
 * Falcon names a player by a handle that is only valid while they are online, so the handle is dropped on leave.
 */
final class Players{
    /** @var Player[] by Falcon handle */
    private static array $byHandle = [];

    private function __construct(){
    }

    public static function get(int $handle, int $remotePort = 0) : Player{
        $player = self::$byHandle[$handle] ?? null;
        // a handle Falcon reused for someone else: the one who had it left without a leave event
        if($player !== null && $player->getName() !== falcon_player_name($handle)){
            $player->onLeave(false);
            $player = null;
        }
        if($player !== null){
            return $player;
        }
        $server = Server::getInstance();
        if(!PlayerCreationEvent::hasHandlers()){
            return self::$byHandle[$handle] = new Player($server, new NetworkSession(connectingHandle: $handle, remotePort: $remotePort));
        }

        $info = PlayerCompatibilityEvents::playerInfo($handle);
        $session = new NetworkSession(connectingHandle: $handle, playerInfo: $info, remotePort: $remotePort);
        $event = new PlayerCreationEvent($session);
        Bridge::callListeners($event);
        $class = $event->getPlayerClass();
        $parameters = (new \ReflectionClass($class))->getConstructor()?->getParameters() ?? [];
        $connectionType = isset($parameters[1]) ? $parameters[1]->getType() : null;
        if($connectionType instanceof \ReflectionNamedType && $connectionType->getName() === 'int'){
            $player = new $class($server, $handle);
            $player->onSessionCreated($session);
        }else{
            $entity = falcon_entity_info(falcon_player_entity($handle));
            [$x, $y, $z] = $entity['position'];
            [$pitch, $yaw] = $entity['rotation'];
            $worlds = $server->getWorldManager();
            $world = $worlds->forLevel($entity['level']) ?? $worlds->getDefaultWorld();
            $location = new Location($x, $y, $z, $world, $yaw, $pitch);
            $player = new $class($server, $session, $info, falcon_player_xuid($handle) !== '', $location,
                $server->getOfflinePlayerData($info->getUsername()));
        }
        return self::$byHandle[$handle] = $player;
    }

    /** the player left: the object stays with whoever kept it, offline */
    public static function leave(int $handle) : void{
        if(isset(self::$byHandle[$handle])){
            self::$byHandle[$handle]->onLeave();
            unset(self::$byHandle[$handle]);
        }
    }

    /**
     * Falcon's players, in Falcon's order; a player the runtime had not met yet gets its object now.
     *
     * @return Player[]
     */
    public static function online() : array{
        $players = [];
        foreach(falcon_online_players() as $handle){
            $player = self::get($handle);
            $player->spawned = true;
            $players[spl_object_id($player)] = $player;
        }
        return $players;
    }
}
