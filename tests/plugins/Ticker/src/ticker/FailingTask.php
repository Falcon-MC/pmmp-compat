<?php

declare(strict_types=1);

namespace ticker;

use pocketmine\scheduler\AsyncTask;

final class FailingTask extends AsyncTask{
    public function __construct(\Logger $logger){
        $this->storeLocal("logger", $logger);
    }

    public function onRun() : void{
        throw new \RuntimeException("an async task that fails on purpose");
    }

    public function onError() : void{
        $this->fetchLocal("logger")->info("fixture: Ticker async error reported");
    }
}
