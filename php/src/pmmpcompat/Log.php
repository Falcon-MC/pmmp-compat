<?php

declare(strict_types=1);

namespace pmmpcompat;

/** The server log, through Falcon's logger for this plugin. */
final class Log{
    private const INFO = 0;
    private const WARNING = 1;
    private const ERROR = 2;

    private function __construct(){
    }

    public static function info(string $message) : void{
        self::write($message, self::INFO);
    }

    public static function warning(string $message) : void{
        self::write($message, self::WARNING);
    }

    public static function critical(string $message) : void{
        self::write($message, self::ERROR);
    }

    // a line at a time, so each one carries the time and level
    private static function write(string $message, int $level) : void{
        foreach(explode("\n", str_replace("\r\n", "\n", $message)) as $line){
            falcon_log($line, $level);
        }
    }

    public static function exception(\Throwable $error) : void{
        do{
            self::critical(get_class($error) . ': ' . $error->getMessage() . ' in ' . $error->getFile() . ':'
                . $error->getLine());
            foreach(explode("\n", $error->getTraceAsString()) as $line){
                self::critical('  ' . $line);
            }
            $error = $error->getPrevious();
        }while($error !== null);
    }
}
