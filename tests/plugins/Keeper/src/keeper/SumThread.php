<?php

declare(strict_types=1);

namespace keeper;

use pmmp\thread\ThreadSafeArray;
use pocketmine\snooze\SleeperHandlerEntry;
use pocketmine\snooze\SleeperNotifier;
use pocketmine\thread\Thread;

// a thread of the plugin's own: its classes load there, it fills a shared array and wakes the server
final class SumThread extends Thread{
    public int $sum = 0;

    public function __construct(
        private ThreadSafeArray $numbers,
        private SleeperHandlerEntry|SleeperNotifier $sleeper
    ){}

    protected function onRun() : void{
        $total = 0;
        foreach($this->numbers as $number){
            $total += $number;
        }
        $this->sum = Squarer::square($total);
        $this->numbers[] = -1;
        $notifier = $this->sleeper instanceof SleeperHandlerEntry ? $this->sleeper->createNotifier() : $this->sleeper;
        $notifier->wakeupSleeper();
    }
}
