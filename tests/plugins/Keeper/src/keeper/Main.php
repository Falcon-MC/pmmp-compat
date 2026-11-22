<?php

declare(strict_types=1);

namespace keeper;

use pmmp\thread\ThreadSafeArray;
use pocketmine\plugin\PluginBase;
use pocketmine\promise\PromiseResolver;
use pocketmine\scheduler\ClosureTask;

// databases, threads, the server's sleeper and promises
final class Main extends PluginBase{
    public static ?Main $instance = null;

    protected function onEnable() : void{
        self::$instance = $this;
        $logger = $this->getLogger();
        @mkdir($this->getDataFolder());

        $path = $this->getDataFolder() . "coins.db";
        @unlink($path);
        $db = new \SQLite3($path);
        $db->exec("CREATE TABLE coins (player TEXT, amount INTEGER)");
        $statement = $db->prepare("INSERT INTO coins (player, amount) VALUES (:player, :amount)");
        $statement->bindValue(":player", "Steve");
        $statement->bindValue(":amount", 100, SQLITE3_INTEGER);
        $statement->execute();
        $logger->info("fixture: Keeper sqlite3 reads " . $db->querySingle("SELECT amount FROM coins WHERE player = 'Steve'"));
        $db->close();

        $pdo = new \PDO("sqlite:" . $path);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $query = $pdo->prepare("SELECT COUNT(*) FROM coins WHERE amount > ?");
        $query->execute([50]);
        $logger->info("fixture: Keeper pdo counts " . $query->fetchColumn() . ", drivers " . implode(",", \PDO::getAvailableDrivers()));
        // the statement holds the database open as long as the connection does
        $query = null;
        $pdo = null;

        $logger->info("fixture: Keeper mysqli " . (class_exists(\mysqli::class) ? "ready" : "missing")
            . ", bcmath " . bcadd("0.1", "0.2", 1)
            . ", igbinary " . (igbinary_unserialize(igbinary_serialize(["a" => 1]))["a"] ?? 0)
            . ", sockets " . (function_exists("socket_create") ? "ready" : "missing"));

        $yaml = yaml_parse("decimals: 00\nmode: 010\nname: '010'");
        $logger->info("fixture: Keeper yaml reads " . var_export($yaml["decimals"], true) . ", " . var_export($yaml["mode"], true)
            . ", " . var_export($yaml["name"], true));

        $resolver = new PromiseResolver();
        $resolver->getPromise()->onCompletion(
            fn(string $value) => $logger->info("fixture: Keeper promise resolved with $value"),
            fn() => $logger->error("fixture: Keeper promise rejected")
        );
        $this->getScheduler()->scheduleDelayedTask(new ClosureTask(fn() => $resolver->resolve("gold")), 1);

        $numbers = new ThreadSafeArray();
        foreach([1, 2, 3, 4] as $number){
            $numbers[] = $number;
        }
        $thread = null;
        $entry = $this->getServer()->getTickSleeper()->addNotifier(function() use (&$thread, $logger) : void{
            $logger->info("fixture: Keeper thread woke the server");
            if($thread !== null && !$thread->isJoined()){
                $thread->join();
                $logger->info("fixture: Keeper thread squared the sum: " . $thread->sum);
            }
        });
        $thread = new SumThread($numbers, $entry);
        $thread->start();

        $this->getServer()->getAsyncPool()->submitTask(new SqliteTask($path));
    }
}
