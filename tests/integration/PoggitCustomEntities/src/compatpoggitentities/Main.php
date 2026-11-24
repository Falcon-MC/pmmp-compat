<?php

declare(strict_types=1);

namespace compatpoggitentities;

use BeeAZ\HumanNPC\HumanNPC;
use BlockHorizons\BlockPets\Loader as BlockPets;
use BlockHorizons\BlockPets\pets\creatures\CowPet;
use diamondgold\MiniBosses\Boss;
use diamondgold\MiniBosses\Main as MiniBosses;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\entity\Entity;
use pocketmine\entity\EntityFactory;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\ClosureTask;

final class Main extends PluginBase implements Listener{
    private bool $npcClicked = false;

    protected function onEnable() : void{
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
    }

    public function onJoin(PlayerJoinEvent $event) : void{
        $player = $event->getPlayer();
        $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($player) : void{
            $player->setOp(true);
            $this->humanNpc($player);
            $this->miniBoss($player);
            $this->blockPet($player);
        }), 5);
    }

    private function humanNpc(Player $player) : void{
        $this->getServer()->dispatchCommand($player, 'hnpc spawn ProbeNPC');
        $npc = $this->find($player, HumanNPC::class);
        self::require($npc instanceof HumanNPC && $npc->getNameTag() === 'ProbeNPC', 'HumanNPC spawn and name');
        self::require($npc->getSkin()->getSkinData() === $player->getSkin()->getSkinData(), 'HumanNPC skin');
        $npc->addCommand($player, 'entityfixtureclick');
        $npc->attack(new EntityDamageByEntityEvent($player, $npc, EntityDamageEvent::CAUSE_ENTITY_ATTACK, 1));
        self::require($this->npcClicked, 'HumanNPC click command');
        $copy = EntityFactory::getInstance()->createFromData($player->getWorld(), $npc->saveNBT());
        self::require($copy instanceof HumanNPC && $copy->getNameTag() === 'ProbeNPC'
            && $copy->getCommands() === ['entityfixtureclick'], 'HumanNPC NBT reload');
        $copy->close();
        $npc->close();
        $this->getLogger()->info('HumanNPC spawn, skin, hit command and NBT passed');
    }

    private function miniBoss(Player $player) : void{
        $server = $this->getServer();
        $plugin = $server->getPluginManager()->getPlugin('MiniBosses');
        self::require($plugin instanceof MiniBosses, 'MiniBosses failed to load');
        $server->dispatchCommand($player, 'minibosses create zombie ProbeBoss');
        $settings = $plugin->data->get('ProbeBoss');
        self::require(is_array($settings) && $settings['networkId'] === 'minecraft:zombie', 'MiniBosses command did not save');
        $settings['enabled'] = true;
        $settings['health'] = 30;
        $plugin->data->set('ProbeBoss', $settings);
        $server->dispatchCommand($player, 'minibosses spawn ProbeBoss');
        $boss = $this->find($player, Boss::class);
        self::require($boss instanceof Boss && $boss->getMaxHealth() === 30 && $boss->isAlive(), 'MiniBosses spawn and max health');
        $boss->attack(new EntityDamageByEntityEvent($player, $boss, EntityDamageEvent::CAUSE_ENTITY_ATTACK, 2));
        self::require($boss->getHealth() === 28.0, 'MiniBosses entity damage');
        $server->dispatchCommand($player, 'minibosses despawn ProbeBoss');
        self::require($boss->isFlaggedForDespawn() || $boss->isClosed(), 'MiniBosses despawn command');
        $this->getLogger()->info('MiniBosses create, spawn, damage and despawn passed');
    }

    private function blockPet(Player $player) : void{
        $server = $this->getServer();
        $plugin = $server->getPluginManager()->getPlugin('BlockPets');
        self::require($plugin instanceof BlockPets, 'BlockPets failed to load');
        $server->dispatchCommand($player, 'spawnpet cow ProbeCow 1.5 true');
        $pet = $plugin->getPetByName('ProbeCow', $player->getName());
        self::require($pet instanceof CowPet && abs($pet->getScale() - 1.5) < 0.001 && $pet->isAlive(), 'BlockPets spawn and scale');
        $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($player, $plugin, $pet) : void{
            self::require($pet->isAlive() && $player->getWorld()->getEntity($pet->getId()) === $pet, 'BlockPets entity tick and lookup');
            $this->getServer()->dispatchCommand($player, 'removepet ProbeCow');
            self::require($plugin->getPetByName('ProbeCow', $player->getName()) === null, 'BlockPets remove command');
            $this->getLogger()->info('BlockPets spawn, scale, ticks and removal passed');
        }), 15);
    }

    /** @param class-string<Entity> $class */
    private function find(Player $player, string $class) : ?Entity{
        foreach($player->getWorld()->getEntities() as $entity){
            if($entity instanceof $class){
                return $entity;
            }
        }
        return null;
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args) : bool{
        $this->npcClicked = true;
        return true;
    }

    private static function require(bool $condition, string $message) : void{
        if(!$condition){
            throw new \RuntimeException($message);
        }
    }
}
