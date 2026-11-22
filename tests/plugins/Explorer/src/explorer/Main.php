<?php

declare(strict_types=1);

namespace explorer;

use pocketmine\block\VanillaBlocks;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntityTeleportEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerDeathEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\item\Item;
use pocketmine\item\StringToItemParser;
use pocketmine\item\VanillaItems;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\ClosureTask;

// worlds, blocks, items, a player's inventory and body, and the events that carry them
final class Main extends PluginBase implements Listener{
    protected function onEnable() : void{
        $logger = $this->getLogger();
        $this->getServer()->getPluginManager()->registerEvents($this, $this);

        $worlds = $this->getServer()->getWorldManager();
        $world = $worlds->getDefaultWorld();
        if($world === null){
            $logger->error("fixture: Explorer found no default world");
            return;
        }
        $logger->info("fixture: Explorer default world " . ($worlds->getWorldByName($world->getFolderName()) === $world ? "found by name" : "lost")
            . ", " . count($worlds->getWorlds()) . " levels");

        $spawn = $world->getSpawnLocation();
        $x = (int) $spawn->x;
        $z = (int) $spawn->z;
        $world->setBlockAt($x, 250, $z, VanillaBlocks::GOLD_BLOCK());
        $block = $world->getBlockAt($x, 250, $z);
        $logger->info("fixture: Explorer placed " . $block->getName() . ($block->isSameType(VanillaBlocks::GOLD_BLOCK()) ? ", read back" : ", lost")
            . " at y " . $block->getPosition()->getFloorY());
        $world->setBlockAt($x, 250, $z, VanillaBlocks::AIR());
        $logger->info("fixture: Explorer cleared it to " . $world->getBlockAt($x, 250, $z)->getName());

        $world->setTime(1000);
        $logger->info("fixture: Explorer time of day " . $world->getTimeOfDay());

        $sword = VanillaItems::DIAMOND_SWORD()->setCustomName("Blade")->setLore(["sharp"]);
        $parsed = StringToItemParser::getInstance()->parse("diamond sword");
        $logger->info("fixture: Explorer parsed " . ($parsed !== null && $parsed->equals(VanillaItems::DIAMOND_SWORD()) ? "the same sword" : "something else"));
        $back = Item::jsonDeserialize($sword->jsonSerialize());
        $logger->info("fixture: Explorer json keeps " . $back->getName() . " with " . implode(",", $back->getLore()));
        try{
            VanillaItems::NOT_AN_ITEM();
            $logger->info("fixture: Explorer made an unknown item");
        }catch(\Error){
            $logger->info("fixture: Explorer refused an unknown item");
        }
    }

    public function onJoin(PlayerJoinEvent $event) : void{
        $player = $event->getPlayer();
        $sword = VanillaItems::DIAMOND_SWORD()->setCustomName("Blade")->setLore(["sharp"]);
        $left = $player->getInventory()->addItem($sword);
        $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($player, $left) : void{
            $held = $player->getInventory()->getItem(0);
            $this->getLogger()->info("fixture: Explorer gave " . (count($left) === 0 ? "it" : "nothing") . ", slot 0 holds "
                . $held->getName() . " (" . implode(",", $held->getLore()) . ")");
            $this->getLogger()->info("fixture: Explorer sees " . $player->getName() . " in " . $player->getWorld()->getFolderName()
                . " with " . $player->getHealth() . " health, " . ($player->getWorld()->getEntity($player->getId()) === $player ? "found by id" : "not found by id"));
            $to = $player->getPosition()->add(3, 0, 0);
            $player->teleport($to);
        }), 2);
    }

    public function onTeleport(EntityTeleportEvent $event) : void{
        $entity = $event->getEntity();
        if($entity instanceof Player){
            $this->getLogger()->info("fixture: Explorer saw " . $entity->getName() . " teleport " . round($event->getTo()->distance($event->getFrom()), 1) . " blocks");
        }
    }

    public function onDamage(EntityDamageEvent $event) : void{
        $entity = $event->getEntity();
        if($entity instanceof Player){
            $this->getLogger()->info("fixture: Explorer saw " . $entity->getName() . " take " . $event->getBaseDamage() . " damage, cause " . $event->getCause());
            $event->setBaseDamage(1.0);
            $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($entity) : void{
                $this->getLogger()->info("fixture: Explorer sees " . $entity->getName() . " at " . $entity->getHealth() . " health");
            }), 1);
        }
    }

    public function onDeath(PlayerDeathEvent $event) : void{
        $this->getLogger()->info("fixture: Explorer saw " . $event->getPlayer()->getName() . " die: " . $event->getDeathMessage());
        $event->setDeathMessage($event->getPlayer()->getName() . " fell asleep");
    }
}
