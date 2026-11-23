<?php

declare(strict_types=1);

namespace pocketmine;

use pocketmine\event\server\LowMemoryEvent;
use pocketmine\utils\Process;

final class MemoryManager{
    private const MEBIBYTE = 1024 * 1024;

    private int $ticks = 0;
    private int $triggerCount = 0;
    private int $repeatTicks = 0;
    private bool $lowMemory = false;
    private int $mainLimit;
    private int $globalLimit;
    private int $checkRate;
    private int $repeatRate;
    private bool $repeat;

    public function __construct(ServerConfigGroup $config){
        $maximumLimit = intdiv(PHP_INT_MAX, self::MEBIBYTE);
        $this->mainLimit = max(0, min($maximumLimit, $config->getPropertyInt("memory.main-limit", 0))) * self::MEBIBYTE;
        $this->globalLimit = max(0, min($maximumLimit, $config->getPropertyInt("memory.global-limit", 0))) * self::MEBIBYTE;
        $this->checkRate = max(1, $config->getPropertyInt("memory.check-rate", 20));
        $this->repeatRate = max(1, $config->getPropertyInt("memory.continuous-trigger-rate", 40));
        $this->repeat = $config->getPropertyBool("memory.continuous-trigger", true);
    }

    public function isLowMemory() : bool{
        return $this->lowMemory;
    }

    public function check() : void{
        if(($this->mainLimit <= 0 && $this->globalLimit <= 0) || ++$this->ticks < $this->checkRate){
            return;
        }
        $this->ticks = 0;
        [$main, $global] = Process::getAdvancedMemoryUsage();
        $mainExceeded = $this->mainLimit > 0 && $main > $this->mainLimit;
        $globalExceeded = $this->globalLimit > 0 && $global > $this->globalLimit;
        if(!$mainExceeded && !$globalExceeded){
            $this->lowMemory = false;
            $this->repeatTicks = 0;
            return;
        }

        if(!$this->lowMemory){
            $this->lowMemory = true;
            $this->triggerCount = 0;
        }else{
            if(!$this->repeat || ++$this->repeatTicks < $this->repeatRate){
                return;
            }
            $this->repeatTicks = 0;
            ++$this->triggerCount;
        }

        $this->trigger($mainExceeded ? $main : $global, $mainExceeded ? $this->mainLimit : $this->globalLimit,
            !$mainExceeded, $this->triggerCount);
    }

    public function trigger(int $memory, int $limit, bool $global = false, int $triggerCount = 0) : void{
        (new LowMemoryEvent($memory, $limit, $global, $triggerCount))->call();
        $this->triggerGarbageCollector();
    }

    public function triggerGarbageCollector() : int{
        gc_mem_caches();
        return gc_collect_cycles();
    }
}
