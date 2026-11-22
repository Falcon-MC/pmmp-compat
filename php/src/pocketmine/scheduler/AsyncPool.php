<?php

declare(strict_types=1);

namespace pocketmine\scheduler;

use pmmpcompat\Log;

/**
 * Where plugins submit AsyncTasks: Server::getAsyncPool().
 *
 * The tasks run on the server thread, one tick after they are submitted (see AsyncTask). The worker counts and hooks
 * are kept, so plugins that size or watch the pool keep working, but no worker thread starts.
 */
class AsyncPool{
    /** @var AsyncTask[] in submission order */
    private array $queue = [];
    /** @var \Closure[] */
    private array $workerStartHooks = [];

    public function __construct(
        private int $size = 2
    ){
    }

    public function getSize() : int{
        return $this->size;
    }

    public function increaseSize(int $newSize) : void{
        if($newSize > $this->size){
            $this->size = $newSize;
        }
    }

    /**
     * @phpstan-param \Closure(int $workerId) : void $hook
     */
    public function addWorkerStartHook(\Closure $hook) : void{
        $this->workerStartHooks[spl_object_id($hook)] = $hook;
    }

    public function removeWorkerStartHook(\Closure $hook) : void{
        unset($this->workerStartHooks[spl_object_id($hook)]);
    }

    /** @return int[] none: no worker thread runs */
    public function getRunningWorkers() : array{
        return [];
    }

    public function submitTaskToWorker(AsyncTask $task, int $worker) : void{
        if($worker < 0 || $worker >= $this->size){
            throw new \InvalidArgumentException("Invalid worker $worker");
        }
        $this->submitTask($task);
    }

    public function selectWorker() : int{
        return 0;
    }

    public function submitTask(AsyncTask $task) : int{
        if($task->isSubmitted()){
            throw new \InvalidArgumentException("Cannot submit the same AsyncTask instance more than once");
        }
        $task->setSubmitted();
        $this->queue[] = $task;
        return 0;
    }

    /**
     * Runs what was submitted before this tick: onRun(), the progress updates, then onCompletion() or onError().
     *
     * @return bool whether tasks are still waiting
     */
    public function collectTasks() : bool{
        $tasks = $this->queue;
        $this->queue = [];
        foreach($tasks as $task){
            self::runTask($task);
        }
        return count($this->queue) > 0;
    }

    public function collectTasksFromWorker(int $worker) : bool{
        return $this->collectTasks();
    }

    private static function runTask(AsyncTask $task) : void{
        $name = $task::class;
        try{
            if(!falcon_guard(static fn() => $task->run())){
                Log::critical("AsyncTask $name stopped on a fatal error or exit(), see above");
                $task->markCrashed();
            }
        }catch(\Throwable $e){
            Log::critical("AsyncTask $name failed: " . $e->getMessage());
            Log::exception($e);
            $task->markCrashed();
        }

        try{
            $completed = falcon_guard(static function() use ($task) : void{
                $task->checkProgressUpdates();
                if($task->isCrashed()){
                    $task->onError();
                }else{
                    $task->onCompletion();
                }
            });
            if(!$completed){
                Log::critical("The completion of AsyncTask $name stopped on a fatal error or exit(), see above");
            }
        }catch(\Throwable $e){
            Log::critical("The completion of AsyncTask $name failed: " . $e->getMessage());
            Log::exception($e);
        }
    }

    /** @return int[] by worker */
    public function getTaskQueueSizes() : array{
        return [count($this->queue)];
    }

    public function shutdownUnusedWorkers() : int{
        return 0;
    }

    /** what is still waiting runs now, as the workers would finish it before PocketMine-MP stops */
    public function shutdown() : void{
        $this->collectTasks();
    }
}
