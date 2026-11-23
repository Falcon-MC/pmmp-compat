<?php

declare(strict_types=1);

namespace pluginlifecycle;

use pocketmine\event\Listener;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\server\DataPacketSendEvent;
use pocketmine\network\mcpe\protocol\RemoveObjectivePacket;
use pocketmine\network\mcpe\protocol\SetDisplayObjectivePacket;
use pocketmine\network\mcpe\protocol\SetScorePacket;
use pocketmine\network\mcpe\protocol\types\ScorePacketEntry;
use pocketmine\plugin\PluginBase;

final class Main extends PluginBase implements Listener{
    protected function onEnable() : void{
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
    }

    /** @priority MONITOR */
    public function onJoin(PlayerJoinEvent $event) : void{
        $player = $event->getPlayer();
        $inventory = $player->getInventory();
        $names = [];
        foreach($inventory->getContents() as $item){
            if($item->hasCustomName()){
                $names[$item->getCustomName()] = $item->getCount();
            }
        }
        $previous = $player->hasPlayedBefore();
        $expected = ["StarterSword" => 1, "StarterAxe" => 1, "StarterPickaxe" => 1,
            "StarterShovel" => 1, "StarterPorkchop" => 32, "StarterDiamond" => 8];
        $played = $this->getServer()->getOfflinePlayerData(strtoupper($player->getName()));
        if($played === null || $played->getLong("firstPlayed") !== $player->getFirstPlayed()
            || $played->getLong("lastPlayed") !== $player->getLastPlayed()){
            throw new \RuntimeException("Player history is missing or differs from the join snapshot");
        }
        if(!$previous && $names == $expected){
            $this->getLogger()->info("fixture: StarterKit granted the first-join kit");
            $inventory->clearAll();
        }elseif($previous && array_intersect_key($names, $expected) === []){
            $this->getLogger()->info("fixture: StarterKit did not grant a second kit");
        }else{
            throw new \RuntimeException("Unexpected StarterKit inventory: " . json_encode($names));
        }
    }

    public function onSend(DataPacketSendEvent $event) : void{
        foreach($event->getPackets() as $packet){
            if($packet instanceof SetDisplayObjectivePacket && $packet->objectiveName === "objective"){
                $this->getLogger()->info("fixture: ScoreHud displayed the objective");
            }elseif($packet instanceof SetScorePacket && count($packet->entries) > 0){
                $type = $packet->type;
                $entry = reset($packet->entries);
                if($type === SetScorePacket::TYPE_REMOVE || ($entry->type ?? null) === ScorePacketEntry::TYPE_REMOVE){
                    $this->getLogger()->info("fixture: ScoreHud removed a score line");
                }else{
                    $this->getLogger()->info("fixture: ScoreHud updated a score line");
                }
            }elseif($packet instanceof RemoveObjectivePacket && $packet->objectiveName === "objective"){
                $this->getLogger()->info("fixture: ScoreHud removed the objective");
            }
        }
    }
}
