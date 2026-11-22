<?php

declare(strict_types=1);

namespace pocketmine\timings;

/**
 * A named timer, as PocketMine-MP's timings report shows them.
 *
 * Falcon has its own profiler, so these measure nothing: they keep the calls plugins and the API make working.
 */
class TimingsHandler{
    public function __construct(
        private string $name,
        private ?TimingsHandler $parent = null,
        private string $group = Timings::GROUP_MINECRAFT
    ){
    }

    public function getName() : string{
        return $this->name;
    }

    public function getGroup() : string{
        return $this->group;
    }

    public function getParent() : ?TimingsHandler{
        return $this->parent;
    }

    public function startTiming() : void{
    }

    public function stopTiming() : void{
    }

    /**
     * @phpstan-template TClosureReturn
     * @phpstan-param \Closure() : TClosureReturn $closure
     * @phpstan-return TClosureReturn
     */
    public function time(\Closure $closure){
        return $closure();
    }

    public function reset() : void{
    }

    public function destroyCycles() : void{
    }

    public static function isEnabled() : bool{
        return false;
    }

    public static function setEnabled(bool $enable = true) : void{
    }
}
