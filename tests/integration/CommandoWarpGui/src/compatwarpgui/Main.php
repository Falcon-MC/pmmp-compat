<?php

declare(strict_types=1);

namespace compatwarpgui;

use ClickedTran\WarpGUI\manager\WarpManager;
use ClickedTran\WarpGUI\WarpGUI;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\plugin\PluginBase;

final class Main extends PluginBase implements Listener{
    protected function onEnable() : void{
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
    }

    public function onJoin(PlayerJoinEvent $event) : void{
        $player = $event->getPlayer();
        $player->setOp(true);
        $server = $this->getServer();
        $warps = class_exists(WarpManager::class) ? WarpManager::getInstance()->getWarp() : WarpGUI::getInstance()->getWarp();
        $server->dispatchCommand($player, 'warp create');
        if($warps->getAll() !== []){
            throw new \RuntimeException('Optional name missing changed warp data');
        }
        $server->dispatchCommand($player, 'warp create "Fixture Harbor"');
        if(!$warps->exists('Fixture Harbor')){
            throw new \RuntimeException('Commando did not pass the quoted argument to WarpGUI create');
        }
        $server->dispatchCommand($player, 'warpgui remove "Fixture Harbor"');
        if($warps->exists('Fixture Harbor')){
            throw new \RuntimeException('WarpGUI remove did not run');
        }
        $this->getLogger()->info('WarpGUI Commando alias, optional and multiword arguments passed');
    }
}
