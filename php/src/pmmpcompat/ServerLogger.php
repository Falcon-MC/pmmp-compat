<?php

declare(strict_types=1);

namespace pmmpcompat;

use pmmp\thread\Thread as NativeThread;
use pmmp\thread\ThreadSafeArray;
use pocketmine\thread\log\AttachableThreadSafeLogger;
use pocketmine\thread\log\ThreadSafeLoggerAttachment;
use pocketmine\utils\Utils;

/**
 * The server's logger, what PocketMine-MP calls the main logger: it writes to Falcon's log.
 *
 * It is thread-safe like PocketMine-MP's, so plugins hand it to their threads and the async workers log with it.
 * A line logged on another thread waits in a queue until the server's next tick writes it, as only the main thread
 * talks to Falcon. Falcon has no debug level, so debug messages only reach the attachments.
 */
final class ServerLogger extends AttachableThreadSafeLogger implements \BufferedLogger{
    /** @var ThreadSafeArray<int, string> level and message, a NUL between them */
    private ThreadSafeArray $pending;

    public function __construct(){
        parent::__construct();
        $this->pending = new ThreadSafeArray();
    }

    public function emergency($message){
        $this->log(\LogLevel::EMERGENCY, $message);
    }

    public function alert($message){
        $this->log(\LogLevel::ALERT, $message);
    }

    public function critical($message){
        $this->log(\LogLevel::CRITICAL, $message);
    }

    public function error($message){
        $this->log(\LogLevel::ERROR, $message);
    }

    public function warning($message){
        $this->log(\LogLevel::WARNING, $message);
    }

    public function notice($message){
        $this->log(\LogLevel::NOTICE, $message);
    }

    public function info($message){
        $this->log(\LogLevel::INFO, $message);
    }

    public function debug($message){
        $this->log(\LogLevel::DEBUG, $message);
    }

    public function log($level, $message){
        $level = (string) $level;
        $message = (string) $message;
        if(NativeThread::getCurrentThread() !== null){
            $this->pending[] = $level . "\0" . $message;
            return;
        }
        $this->write($level, $message);
    }

    private function write(string $level, string $message) : void{
        match($level){
            \LogLevel::EMERGENCY, \LogLevel::ALERT, \LogLevel::CRITICAL, \LogLevel::ERROR => Log::critical($message),
            \LogLevel::WARNING => Log::warning($message),
            \LogLevel::NOTICE, \LogLevel::INFO => Log::info($message),
            default => null,
        };
        foreach($this->attachments as $attachment){
            /** @var ThreadSafeLoggerAttachment $attachment */
            $attachment->log($level, $message);
        }
    }

    /** @internal each tick, on the main thread: the lines other threads logged */
    public function flush() : void{
        while(($line = $this->pending->shift()) !== null){
            [$level, $message] = explode("\0", $line, 2);
            $this->write($level, $message);
        }
    }

    public function logException(\Throwable $e, $trace = null){
        $this->critical(implode("\n", Utils::printableExceptionInfo($e, $trace)));
    }

    public function buffer(\Closure $c) : void{
        $this->synchronized($c);
    }
}
