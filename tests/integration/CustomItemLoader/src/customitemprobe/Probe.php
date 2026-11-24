<?php

declare(strict_types=1);

namespace customitemprobe;

use pocketmine\event\Listener;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\item\Item;
use pocketmine\item\StringToItemParser;
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\ClosureTask;

final class Probe extends PluginBase implements Listener{
    private const IDENTIFIER = "fixture:pulse_fruit";

    protected function onEnable() : void{
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
    }

    public function onJoin(PlayerJoinEvent $event) : void{
        $item = StringToItemParser::getInstance()->parse("Pulse Fruit");
        self::check($item !== null && $item->getFalconIdentifier() === self::IDENTIFIER, "plugin item alias");
        self::check(Item::nbtDeserialize($item->nbtSerialize())->equals($item), "item NBT round trip");
        $converter = TypeConverter::getInstance();
        $nativeId = $converter->coreItemStackToNet($item)->getId();
        self::check($nativeId !== 0 && $converter->getItemTypeDictionary()->fromStringId(self::IDENTIFIER) === $nativeId,
            "the PHP dictionary uses Falcon's ID");
        $player = $event->getPlayer();
        $inventory = $player->getInventory();
        $marker = $this->getDataFolder() . "consumed";
        if(is_file($marker)){
            $saved = $inventory->getItem(0);
            self::check($saved->getFalconIdentifier() === self::IDENTIFIER && $saved->getCount() === 1
                && $saved->getCustomName() === "Persisted Fruit", "custom item persisted through restart");
            $this->getLogger()->info("CustomItemLoader item persisted");
            $player->sendMessage("custom-item-persisted");
            return;
        }

        $inventory->setItem(0, $item->setCount(2)->setCustomName("Persisted Fruit"));
        $player->getHungerManager()->setFood(12);
        $player->sendMessage("custom-item-ready");
        $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($player, $inventory, $marker) : void{
            $remaining = $inventory->getItem(0);
            self::check($remaining->getFalconIdentifier() === self::IDENTIFIER && $remaining->getCount() === 1,
                "Falcon consumed one custom item");
            self::check(abs($player->getHungerManager()->getFood() - 16) < 0.01, "custom food restored four hunger points");
            file_put_contents($marker, "consumed");
            $this->getLogger()->info("CustomItemLoader item consumed");
            $player->sendMessage("custom-item-consumed");
        }), 50);
    }

    private static function check(bool $ok, string $message) : void{
        if(!$ok){
            throw new \RuntimeException($message);
        }
    }
}
