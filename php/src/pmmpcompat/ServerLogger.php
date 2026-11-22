<?php

declare(strict_types=1);

namespace pmmpcompat;

use pocketmine\utils\Utils;

/**
 * The server's logger, what PocketMine-MP calls the main logger: it writes to Falcon's log.
 *
 * Falcon has no debug level, so debug messages only reach the attachments, as PocketMine-MP hides them by default.
 */
final class ServerLogger extends \SimpleLogger implements \AttachableLogger{
    /** @var \Closure[] */
    private array $attachments = [];

    public function log($level, $message){
        $message = (string) $message;
        match($level){
            \LogLevel::EMERGENCY, \LogLevel::ALERT, \LogLevel::CRITICAL, \LogLevel::ERROR => Log::critical($message),
            \LogLevel::WARNING => Log::warning($message),
            \LogLevel::NOTICE, \LogLevel::INFO => Log::info($message),
            default => null,
        };
        foreach($this->attachments as $attachment){
            $attachment($level, $message);
        }
    }

    public function logException(\Throwable $e, $trace = null){
        $this->critical(implode("\n", Utils::printableExceptionInfo($e, $trace)));
    }

    public function addAttachment(\Closure $attachment){
        $this->attachments[spl_object_id($attachment)] = $attachment;
    }

    public function removeAttachment(\Closure $attachment){
        unset($this->attachments[spl_object_id($attachment)]);
    }

    public function removeAttachments(){
        $this->attachments = [];
    }

    public function getAttachments(){
        return $this->attachments;
    }
}
