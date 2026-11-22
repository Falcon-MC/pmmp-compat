<?php

declare(strict_types=1);

namespace pmmpcompat;

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

    public static function get(int $handle) : Player{
        return self::$byHandle[$handle] ??= new Player(Server::getInstance(), $handle);
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
            $players[spl_object_id($player)] = $player;
        }
        return $players;
    }
}
