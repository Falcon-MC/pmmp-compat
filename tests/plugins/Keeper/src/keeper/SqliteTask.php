<?php

declare(strict_types=1);

namespace keeper;

use pocketmine\scheduler\AsyncTask;

// a database written on a worker thread, as libasynql does
final class SqliteTask extends AsyncTask{
    public function __construct(private string $path){
    }

    public function onRun() : void{
        $db = new \SQLite3($this->path);
        $db->busyTimeout(5000);
        $db->exec("INSERT INTO coins (player, amount) VALUES ('worker', 42)");
        $this->setResult((int) $db->querySingle("SELECT SUM(amount) FROM coins"));
        $db->close();
    }

    public function onCompletion() : void{
        Main::$instance?->getLogger()->info("fixture: Keeper worker wrote sqlite, total " . $this->getResult());
    }
}
