<?php

declare(strict_types=1);

namespace pocketmine\timings;

use pocketmine\event\Event;

/** The timers PocketMine-MP keeps; see TimingsHandler for why they measure nothing here. */
abstract class Timings{
    public const GROUP_MINECRAFT = "Minecraft";
    public const GROUP_BREAKDOWN = "Minecraft - Breakdown";

    public static TimingsHandler $permissibleCalculation;
    public static TimingsHandler $permissibleCalculationDiff;
    public static TimingsHandler $permissibleCalculationCallback;
    public static TimingsHandler $playerChunkSend;

    /** @var TimingsHandler[] by event class */
    private static array $events = [];

    public static function init() : void{
        self::$permissibleCalculation = new TimingsHandler("Permissible Calculation");
        self::$permissibleCalculationDiff = new TimingsHandler("Permissible Calculation - Diff", self::$permissibleCalculation);
        self::$permissibleCalculationCallback = new TimingsHandler("Permissible Calculation - Callbacks", self::$permissibleCalculation);
        self::$playerChunkSend = new TimingsHandler("Player Network Send - Chunks");
    }

    public static function getEventTimings(Event $event) : TimingsHandler{
        return self::$events[$event::class] ??= new TimingsHandler("Event: " . $event::class, group: self::GROUP_BREAKDOWN);
    }

    public static function getEventHandlerTimings(string $event, string $handlerName, string $group) : TimingsHandler{
        return new TimingsHandler($event . " " . $handlerName, group: $group);
    }

    public static function getScheduledTaskTimings(\pocketmine\scheduler\TaskHandler $task, int $period) : TimingsHandler{
        return new TimingsHandler("Task: " . $task->getTaskName() . ($period > 0 ? " (interval:$period)" : " (Single)"),
            group: $task->getOwnerName());
    }

    public static function getAsyncTaskRunTimings(\pocketmine\scheduler\AsyncTask $task) : TimingsHandler{
        return new TimingsHandler("AsyncTask: " . $task::class, group: self::GROUP_BREAKDOWN);
    }
}
