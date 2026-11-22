<?php

declare(strict_types=1);

namespace ticker;

use pocketmine\scheduler\AsyncTask;

final class SumTask extends AsyncTask{
    private string $numbers;

    /**
     * @param int[] $numbers
     */
    public function __construct(array $numbers, \Logger $logger){
        $this->numbers = serialize($numbers);
        $this->storeLocal("logger", $logger);
    }

    public function onRun() : void{
        $this->publishProgress("halfway");
        $this->setResult(array_sum(unserialize($this->numbers)));
    }

    public function onProgressUpdate($progress) : void{
        $this->fetchLocal("logger")->info("fixture: Ticker async progress $progress");
    }

    public function onCompletion() : void{
        $this->fetchLocal("logger")->info("fixture: Ticker async result " . $this->getResult());
    }
}
