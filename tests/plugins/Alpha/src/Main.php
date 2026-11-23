<?php

declare(strict_types=1);

namespace alpha\plugin;

use alpha\plugin\util\Greeter;

final class Main extends \pocketmine\plugin\PluginBase{
    protected function onEnable() : void{
        echo "fixture: Alpha enabled, " . Greeter::greet() . "\n";
        $manager = $this->getServer()->getPluginManager();
        $permission = new \pocketmine\permission\Permission("fixture.alpha.manager");
        $called = false;
        $manager->registerEvent(\pocketmine\event\plugin\PluginEnableEvent::class,
            static function(\pocketmine\event\plugin\PluginEnableEvent $event) use (&$called) : void{
                $called = true;
            }, \pocketmine\event\EventPriority::NORMAL, $this);
        $manager->callEvent(new \pocketmine\event\plugin\PluginEnableEvent($this));
        if($called && $manager->addPermission($permission) && !$manager->addPermission($permission)
            && $manager->getPermission($permission->getName()) === $permission && $manager->getPlugin("Alpha") === $this){
            echo "fixture: Alpha PluginManager dispatch and permission registration work\n";
        }

    }

    protected function onDisable() : void{
        echo "fixture: Alpha disabled\n";
    }
}
