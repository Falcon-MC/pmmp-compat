<?php

declare(strict_types=1);

namespace pmmpcompat;

use pocketmine\nbt\tag\CompoundTag;
use pocketmine\player\Player;

final class PlayerHistory{
    private static ?\SQLite3 $database = null;
    /** @var \WeakMap<Player, array{first: int, last: int, returning: bool}>|null */
    private static ?\WeakMap $sessions = null;

    private static function database() : \SQLite3{
        if(self::$database === null){
            $database = new \SQLite3(dirname(\pocketmine\PLUGIN_PATH) . '/player-history.sqlite');
            $database->enableExceptions(true);
            $database->busyTimeout(1000);
            $database->exec('CREATE TABLE IF NOT EXISTS player_history (
                uuid TEXT PRIMARY KEY, name TEXT NOT NULL COLLATE NOCASE,
                first_played INTEGER NOT NULL, last_played INTEGER NOT NULL
            )');
            $database->exec('CREATE INDEX IF NOT EXISTS player_history_name ON player_history (name, last_played)');
            self::$database = $database;
        }
        return self::$database;
    }

    public static function recordJoin(Player $player) : void{
        self::$sessions ??= new \WeakMap();
        if(isset(self::$sessions[$player])){
            return;
        }
        $now = (int) floor(microtime(true) * 1000);
        $first = $now;
        $returning = false;
        try{
            $database = self::database();
            $uuid = $player->getUniqueId()->toString();
            $find = $database->prepare('SELECT first_played FROM player_history WHERE uuid = :uuid');
            $find->bindValue(':uuid', $uuid, SQLITE3_TEXT);
            $result = $find->execute();
            $previous = $result->fetchArray(SQLITE3_ASSOC);
            $result->finalize();
            if($previous !== false){
                $first = (int) $previous['first_played'];
                $returning = true;
            }
            $save = $database->prepare('INSERT INTO player_history (uuid, name, first_played, last_played)
                VALUES (:uuid, :name, :played, :played)
                ON CONFLICT(uuid) DO UPDATE SET name = excluded.name, last_played = excluded.last_played');
            $save->bindValue(':uuid', $uuid, SQLITE3_TEXT);
            $save->bindValue(':name', $player->getName(), SQLITE3_TEXT);
            $save->bindValue(':played', $now, SQLITE3_INTEGER);
            $save->execute()->finalize();
        }catch(\Throwable $error){
            Log::warning('Could not record player history: ' . $error->getMessage());
        }
        self::$sessions[$player] = ['first' => $first, 'last' => $now, 'returning' => $returning];
    }

    /** @return array{first: int, last: int, returning: bool}|null */
    public static function session(Player $player) : ?array{
        return self::$sessions[$player] ?? null;
    }

    public static function offline(string $name) : ?CompoundTag{
        $find = self::database()->prepare('SELECT first_played, last_played FROM player_history
            WHERE name = :name ORDER BY last_played DESC LIMIT 1');
        $find->bindValue(':name', $name, SQLITE3_TEXT);
        $result = $find->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        $result->finalize();
        return $row === false ? null : CompoundTag::create()
            ->setLong('firstPlayed', $row['first_played'])
            ->setLong('lastPlayed', $row['last_played']);
    }
}
