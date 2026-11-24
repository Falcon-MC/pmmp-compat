<?php

declare(strict_types=1);

namespace compatsensitiveevents;

use pocketmine\block\VanillaBlocks;
use pocketmine\entity\object\PrimedTNT;
use pocketmine\entity\projectile\Arrow;
use pocketmine\event\entity\EntityExplodeEvent;
use pocketmine\event\entity\EntityPreExplodeEvent;
use pocketmine\event\entity\EntityShootBowEvent;
use pocketmine\event\entity\ProjectileLaunchEvent;
use pocketmine\event\inventory\CraftItemEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerChatEvent;
use pocketmine\event\player\PlayerEnchantingOptionsRequestEvent;
use pocketmine\event\player\PlayerItemEnchantEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\item\enchantment\EnchantmentInstance;
use pocketmine\item\enchantment\EnchantingOption;
use pocketmine\item\enchantment\VanillaEnchantments;
use pocketmine\item\VanillaItems;
use pocketmine\math\Vector3;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\ClosureTask;

final class Main extends PluginBase implements Listener{
    private int $bows = 0;
    private int $launches = 0;
    private int $crafts = 0;
    private int $enchants = 0;
    private int $explosions = 0;
    /** @var array<int, int> */
    private array $blastBlockCounts = [];
    /** @var list<Vector3> */
    private array $targets = [];

    protected function onEnable() : void{
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
    }

    public function onJoin(PlayerJoinEvent $event) : void{
        $player = $event->getPlayer();
        $inventory = $player->getInventory();
        $inventory->setItem(0, VanillaItems::BOW());
        $inventory->setItem(1, VanillaItems::ARROW()->setCount(2));
        $inventory->setItem(2, VanillaBlocks::OAK_LOG()->asItem());
        $inventory->setItem(3, VanillaItems::BOOK());
        $inventory->setItem(4, VanillaItems::LAPIS_LAZULI()->setCount(3));
        $player->getXpManager()->setXpLevel(10);

        $position = $player->getPosition();
        $world = $player->getWorld();
        $table = new Vector3($position->getFloorX() + 2, $position->getFloorY(), $position->getFloorZ());
        $world->setBlock($table, VanillaBlocks::ENCHANTING_TABLE());
        $player->sendMessage("sensitive-fixture-table {$table->x} {$table->y} {$table->z}");

        // Keep the test blasts above the player and within their ticking area.
        $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($position, $world) : void{
            $origin = new Vector3($position->getFloorX(), $position->getFloorY() + 20, $position->getFloorZ());
            $server = $this->getServer();
            foreach([0, 10, 20] as $offset){
                $target = $origin->add($offset, 0, 0);
                $world->getBlock($target);
                $world->setBlock($target, VanillaBlocks::DIRT());
                self::expect($world->getBlock($target)->getFalconIdentifier() === 'minecraft:dirt', 'target setup');
                $this->targets[] = $target;
                $x = $target->x + 0.5;
                $y = $target->y + 1;
                $z = $target->z + 0.5;
                self::expect($server->dispatchCommand($server->getConsoleSender(), "summon tnt $x $y $z"), 'summon');
            }
        }), 5);
    }

    public function onBow(EntityShootBowEvent $event) : void{
        self::expect($event->getBow()->getFalconIdentifier() === 'minecraft:bow' && $event->getForce() > 0,
            'bow payload');
        if(++$this->bows === 1){
            $event->cancel();
        }else{
            $event->setForce(0.25);
        }
    }

    public function onLaunch(ProjectileLaunchEvent $event) : void{
        if(!$event->getEntity() instanceof Arrow){
            return;
        }
        self::expect($this->bows === 2, 'cancelled arrow did not launch');
        self::expect(abs($event->getEntity()->getMotion()->length() - 0.25) < 0.001, 'modified arrow velocity');
        ++$this->launches;
    }

    public function onExplosion(EntityPreExplodeEvent $event) : void{
        if(!$event->getEntity() instanceof PrimedTNT){
            return;
        }
        foreach($this->targets as $index => $target){
            if(abs($event->getEntity()->getPosition()->x - $target->x) > 2){
                continue;
            }
            ++$this->explosions;
            if($index === 0){
                $event->cancel();
            }elseif($index === 1){
                $event->setBlockBreaking(false);
            }
            return;
        }
    }

    public function onBlast(EntityExplodeEvent $event) : void{
        foreach($this->targets as $index => $target){
            if(abs($event->getPosition()->x - $target->x) <= 2){
                $this->blastBlockCounts[$index] = count($event->getBlockList());
                return;
            }
        }
    }

    public function onCraft(CraftItemEvent $event) : void{
        self::expect($event->getInputs()[0]->getFalconIdentifier() === 'minecraft:oak_log', 'craft input');
        self::expect($event->getOutputs()[0]->getFalconIdentifier() === 'minecraft:oak_planks' &&
            $event->getOutputs()[0]->getCount() === 4 && $event->getRepetitions() === 1, 'craft output');
        if(++$this->crafts === 1){
            $event->cancel();
        }
    }

    public function onEnchantOptions(PlayerEnchantingOptionsRequestEvent $event) : void{
        if($event->getInventory()->getInput()->getFalconIdentifier() !== 'minecraft:book'){
            return;
        }
        $event->setOptions([new EnchantingOption(4, 'Fixture Sharpness', [
            new EnchantmentInstance(VanillaEnchantments::SHARPNESS(), 2),
        ])]);
    }

    public function onEnchant(PlayerItemEnchantEvent $event) : void{
        self::expect($event->getInputItem()->getFalconIdentifier() === 'minecraft:book' &&
            $event->getOutputItem()->getFalconIdentifier() === 'minecraft:enchanted_book', 'enchant item payload');
        self::expect($event->getOption()->getRequiredXpLevel() === 4 && $event->getCost() === 1 &&
            $event->getOutputItem()->getEnchantmentLevel(VanillaEnchantments::SHARPNESS()) === 2, 'enchant option applied');
        if(++$this->enchants === 1){
            $event->cancel();
        }
    }

    public function onCheck(PlayerChatEvent $event) : void{
        if($event->getMessage() !== 'sensitive-fixture-verify'){
            return;
        }
        $event->cancel();
        $player = $event->getPlayer();
        $inventory = $player->getInventory();
        self::expect($this->bows === 2 && $this->launches === 1 && $inventory->getItem(1)->getCount() === 1 &&
            $inventory->getItem(0)->getDamage() === 1, 'bow cancellation and consumption');
        self::expect($this->crafts === 2 && $inventory->getItem(5)->getFalconIdentifier() === 'minecraft:oak_planks' &&
            $inventory->getItem(5)->getCount() === 4, 'craft rollback and retry');
        self::expect($this->enchants === 2 && $player->getXpManager()->getXpLevel() === 9 && $inventory->getItem(4)->getCount() === 2 &&
            $inventory->getItem(6)->getEnchantmentLevel(VanillaEnchantments::SHARPNESS()) === 2, 'enchant rollback and retry');

        $world = $player->getWorld();
        self::expect($this->explosions === 3 && !isset($this->blastBlockCounts[0]) &&
            ($this->blastBlockCounts[1] ?? null) === 0 && ($this->blastBlockCounts[2] ?? 0) > 0, 'TNT blast dispositions');
        foreach(['minecraft:dirt', 'minecraft:dirt', 'minecraft:air'] as $index => $name){
            self::expect($world->getBlock($this->targets[$index])->getFalconIdentifier() === $name, "explosion block $index");
        }
        $player->sendMessage('sensitive-fixture-ok');
        $this->getLogger()->info('sensitive compatibility events passed');
    }

    private static function expect(bool $condition, string $check) : void{
        if(!$condition){
            throw new \RuntimeException("Sensitive event fixture failed: $check");
        }
    }
}
