<?php

declare(strict_types=1);

namespace pocketmine\scheduler;

use pocketmine\timings\Timings;

/**
 * Work to do away from the game, then report back: onRun(), then onCompletion() with the result.
 *
 * PocketMine-MP runs onRun() on a worker thread. This runtime has no threads for PHP yet, so the pool runs it on the
 * server thread, at the tick after it was submitted: the API and the order of the calls are the same, but a task
 * that blocks (a download, a query) holds the tick while it does.
 */
abstract class AsyncTask{
    /** @var array<int, array<string, mixed>> what storeLocal() keeps, by task */
    private static array $threadLocalStorage = [];

    /** @var mixed[] */
    private array $progressUpdates = [];

    private mixed $result = null;

    private bool $submitted = false;
    private bool $finished = false;
    private bool $crashed = false;

    /** @internal the pool runs it */
    public function run() : void{
        $this->result = null;
        $timings = Timings::getAsyncTaskRunTimings($this);
        $timings->startTiming();
        try{
            $this->onRun();
        }finally{
            $timings->stopTiming();
        }
        $this->finished = true;
    }

    /** @internal what the pool tells when onRun() threw or stopped */
    public function markCrashed() : void{
        $this->crashed = true;
    }

    public function isCrashed() : bool{
        return $this->crashed;
    }

    public function isFinished() : bool{
        return $this->finished || $this->crashed;
    }

    public function hasResult() : bool{
        return $this->result !== null;
    }

    /**
     * @return mixed what onRun() passed to setResult()
     */
    public function getResult(){
        return $this->result;
    }

    public function setResult(mixed $result) : void{
        // PocketMine-MP copies a result between threads; a copy here keeps objects from changing under the task
        $this->result = is_object($result) ? unserialize(serialize($result)) : $result;
    }

    public function cancelRun() : void{
    }

    public function hasCancelledRun() : bool{
        return false;
    }

    public function setSubmitted() : void{
        $this->submitted = true;
    }

    public function isSubmitted() : bool{
        return $this->submitted;
    }

    /** the work; on PocketMine-MP it runs on another thread, so it must not touch the server */
    abstract public function onRun() : void;

    /** back on the server thread with the result */
    public function onCompletion() : void{
    }

    /** a progress value onProgressUpdate() gets on the server thread */
    public function publishProgress(mixed $progress) : void{
        $this->progressUpdates[] = serialize($progress);
    }

    /** @internal */
    public function checkProgressUpdates() : void{
        while(($progress = array_shift($this->progressUpdates)) !== null){
            $this->onProgressUpdate(unserialize($progress));
        }
    }

    /**
     * @param mixed $progress
     */
    public function onProgressUpdate($progress) : void{
    }

    /** called instead of onCompletion() when onRun() failed */
    public function onError() : void{
    }

    /** keeps something on the server thread for onCompletion(), which a thread could not carry */
    protected function storeLocal(string $key, mixed $complexData) : void{
        self::$threadLocalStorage[spl_object_id($this)][$key] = $complexData;
    }

    /**
     * @return mixed
     */
    protected function fetchLocal(string $key){
        $id = spl_object_id($this);
        if(!isset(self::$threadLocalStorage[$id]) || !array_key_exists($key, self::$threadLocalStorage[$id])){
            throw new \InvalidArgumentException("No matching thread-local data found on this thread");
        }
        return self::$threadLocalStorage[$id][$key];
    }

    final public function __destruct(){
        $this->reallyDestruct();
        unset(self::$threadLocalStorage[spl_object_id($this)]);
    }

    protected function reallyDestruct() : void{
    }
}
