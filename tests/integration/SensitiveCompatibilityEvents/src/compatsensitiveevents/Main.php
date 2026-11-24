<?php

declare(strict_types=1);

namespace compatsensitiveevents;

use pocketmine\block\Block;
use pocketmine\block\inventory\BlockInventory;
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\Entity;
use pocketmine\entity\object\ItemEntity;
use pocketmine\entity\object\PrimedTNT;
use pocketmine\entity\projectile\Arrow;
use pocketmine\entity\projectile\Snowball;
use pocketmine\event\block\BlockGrowEvent;
use pocketmine\event\entity\EntityDamageByChildEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntityExplodeEvent;
use pocketmine\event\entity\EntityPreExplodeEvent;
use pocketmine\event\entity\EntityShootBowEvent;
use pocketmine\event\entity\ProjectileLaunchEvent;
use pocketmine\event\inventory\CraftItemEvent;
use pocketmine\event\inventory\InventoryCloseEvent;
use pocketmine\event\inventory\InventoryOpenEvent;
use pocketmine\event\inventory\InventoryTransactionEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerBlockPickEvent;
use pocketmine\event\player\PlayerChatEvent;
use pocketmine\event\player\PlayerEnchantingOptionsRequestEvent;
use pocketmine\event\player\PlayerEntityPickEvent;
use pocketmine\event\player\PlayerItemEnchantEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerMissSwingEvent;
use pocketmine\event\player\PlayerRespawnAnchorUseEvent;
use pocketmine\event\server\DataPacketDecodeEvent;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\event\server\DataPacketSendEvent;
use pocketmine\item\enchantment\EnchantmentInstance;
use pocketmine\item\enchantment\EnchantingOption;
use pocketmine\item\enchantment\VanillaEnchantments;
use pocketmine\item\VanillaItems;
use pocketmine\inventory\transaction\action\SlotChangeAction;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\BlockPickRequestPacket;
use pocketmine\network\mcpe\protocol\TextPacket;
use pocketmine\player\GameMode;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\ClosureTask;

final class Main extends PluginBase implements Listener{
    private int $bows = 0;
    private int $launches = 0;
    private ?Snowball $replacement = null;
    private bool $replacementLaunched = false;
    private ?Snowball $cancelledReplacement = null;
    private int $crafts = 0;
    private int $enchants = 0;
    private int $explosions = 0;
    private int $decodePicks = 0;
    private int $receivePicks = 0;
    private int $blockPicks = 0;
    private int $entityPicks = 0;
    private int $misses = 0;
    private int $fertilizations = 0;
    private int $craftInputMoves = 0;
    private int $enchantOpens = 0;
    private int $enchantCloses = 0;
    private ?Arrow $damageArrow = null;
    private ?Entity $arrowVictim = null;
    private ?ItemEntity $pickTarget = null;
    private ?Vector3 $anchor = null;
    private ?Vector3 $crop = null;
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
        $inventory->setItem(1, VanillaItems::ARROW()->setCount(3));
        $inventory->setItem(2, VanillaBlocks::OAK_LOG()->asItem());
        $inventory->setItem(3, VanillaItems::BOOK());
        $inventory->setItem(4, VanillaItems::LAPIS_LAZULI()->setCount(3));
        $player->getXpManager()->setXpLevel(10);

        $position = $player->getPosition();
        $world = $player->getWorld();
        $table = new Vector3($position->getFloorX() + 2, $position->getFloorY(), $position->getFloorZ());
        $world->setBlock($table, VanillaBlocks::ENCHANTING_TABLE());
        $player->sendMessage("sensitive-fixture-table {$table->x} {$table->y} {$table->z}");
        $this->prepareInteraction($player, $position);

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

    private function prepareInteraction(Player $player, Vector3 $position) : void{
        $player->setGamemode(GameMode::CREATIVE);
        $world = $player->getWorld();
        $block = $position->floor()->add(0, 0, 2);
        $world->setBlock($block, VanillaBlocks::GOLD());
        $this->anchor = $position->floor()->add(0, 0, -2);
        $world->setBlock($this->anchor, Block::fromFalcon('minecraft:respawn_anchor', '{"respawn_anchor_charge":1}'));
        $this->crop = $position->floor()->add(-2, 0, 0);
        $world->setBlock($this->crop->subtract(0, 1, 0), VanillaBlocks::FARMLAND());
        $world->setBlock($this->crop, Block::fromFalcon('minecraft:wheat', '{"growth":0}'));
        self::expect(($world->getBlock($this->crop)->getStates()['growth'] ?? null) === 0, 'placed wheat at age zero');
        $player->getInventory()->setItem(6, VanillaItems::BONE_MEAL()->setCount(2));
        $this->pickTarget = $world->dropItem($position->add(3, 1, 0), VanillaItems::DIAMOND(), Vector3::zero(), 200);
        self::expect($this->pickTarget !== null, 'spawned item actor to pick');
        $player->sendMessage("sensitive-fixture-interaction {$block->x} {$block->y} {$block->z} " .
            "{$this->anchor->x} {$this->anchor->y} {$this->anchor->z} {$this->crop->x} {$this->crop->y} {$this->crop->z} " .
            "{$this->pickTarget->getId()} " .
            "{$position->x} {$position->y} {$position->z}");
    }

    public function onDecode(DataPacketDecodeEvent $event) : void{
        if($event->getPacketId() === BlockPickRequestPacket::NETWORK_ID && ++$this->decodePicks === 1){
            self::expect($event->getPacketBuffer() !== '', 'pre-decode bytes');
            $event->cancel();
        }
    }

    public function onReceive(DataPacketReceiveEvent $event) : void{
        if($event->getPacket() instanceof BlockPickRequestPacket && ++$this->receivePicks === 1){
            $event->cancel();
        }
    }

    public function onSend(DataPacketSendEvent $event) : void{
        foreach($event->getPackets() as $packet){
            if($packet instanceof TextPacket && $packet->message === 'sensitive-fixture-interaction-raw'){
                $targets = $event->getTargets();
                self::expect(count($targets) === 1 && reset($targets)->getPlayer()?->getName() === 'EventFixture',
                    'outgoing packet target');
                $packet->message = 'sensitive-fixture-interaction-ok';
            }
        }
    }

    public function onBlockPick(PlayerBlockPickEvent $event) : void{
        self::expect($event->getBlock()->getFalconIdentifier() === 'minecraft:gold_block', 'picked native block');
        $event->getResultItem()->setCustomName('Fixture block pick');
        ++$this->blockPicks;
    }

    public function onEntityPick(PlayerEntityPickEvent $event) : void{
        self::expect($event->getEntity() === $this->pickTarget &&
            $this->pickTarget->getItem()->getFalconIdentifier() === 'minecraft:diamond', 'picked native item actor');
        $event->getResultItem()->setCustomName('Fixture entity pick');
        ++$this->entityPicks;
    }

    public function onMissSwing(PlayerMissSwingEvent $event) : void{
        ++$this->misses;
        $event->getPlayer()->sendMessage('sensitive-fixture-miss');
    }

    public function onAnchor(PlayerRespawnAnchorUseEvent $event) : void{
        self::expect($event->getBlock()->getPosition()->equals($this->anchor) &&
            $event->getAction() === PlayerRespawnAnchorUseEvent::ACTION_EXPLODE, 'overworld anchor action');
        $event->setAction(PlayerRespawnAnchorUseEvent::ACTION_SET_SPAWN);
    }

    public function onGrow(BlockGrowEvent $event) : void{
        if($this->crop === null || !$event->getBlock()->getPosition()->equals($this->crop)){
            return;
        }
        self::expect($event->getPlayer()?->getName() === 'EventFixture' &&
            (json_decode($event->getBlock()->getFalconStates(), true)['growth'] ?? -1) === 0 &&
            (json_decode($event->getNewState()->getFalconStates(), true)['growth'] ?? 0) > 0,
            'bone meal exposes its player and proposed block state');
        if(++$this->fertilizations === 1){
            $event->cancel();
            $event->getPlayer()->sendMessage('sensitive-fixture-growth-cancelled');
        }
    }

    public function onBow(EntityShootBowEvent $event) : void{
        self::expect($event->getBow()->getFalconIdentifier() === 'minecraft:bow' && $event->getForce() > 0,
            'bow payload');
        if(++$this->bows === 1){
            $event->cancel();
        }elseif($this->bows === 2){
            $shooter = $event->getEntity();
            self::expect($shooter instanceof Player, 'bow shooter');
            $inventory = $shooter->getInventory();
            self::expect($inventory->getItem(1)->getCount() === 3 && $inventory->getItem(0)->getDamage() === 0,
                'cancelled shot spent ammo or durability');
            $event->setForce(0.25);
        }else{
            $original = $event->getProjectile();
            $replacement = new Snowball($original->getLocation(), $event->getEntity());
            $replacement->setMotion($original->getMotion()->normalize());
            $event->setProjectile($replacement);
            $event->setForce(0.35);
            if($this->bows === 3){
                $this->replacement = $replacement;
            }else{
                $this->cancelledReplacement = $replacement;
            }
        }
    }

    public function onLaunch(ProjectileLaunchEvent $event) : void{
        if($event->getEntity() instanceof Snowball){
            if($event->getEntity() === $this->cancelledReplacement){
                $event->cancel();
                return;
            }
            self::expect($event->getEntity() === $this->replacement && $this->replacement->getOwningEntity() instanceof Player,
                'replacement snowball and owner');
            self::expect(abs($this->replacement->getMotion()->length() - 0.35) < 0.001, 'replacement snowball velocity');
            $this->replacementLaunched = true;
            return;
        }
        if(!$event->getEntity() instanceof Arrow){
            return;
        }
        self::expect($this->bows === 2, 'cancelled arrow did not launch');
        $arrow = $event->getEntity();
        self::expect(abs($arrow->getMotion()->length() - 0.25) < 0.001, 'modified arrow velocity');
        $this->damageArrow = $arrow;
        $world = $arrow->getWorld();
        $target = $arrow->getPosition()->add(0, -1.0, 1.2);
        $world->setBlock($target->subtract(0, 1, 0), VanillaBlocks::STONE());
        $world->setBlock($target, VanillaBlocks::AIR());
        $world->setBlock($target->add(0, 1, 0), VanillaBlocks::AIR());
        $server = $this->getServer();
        self::expect($server->dispatchCommand($server->getConsoleSender(), "summon cow {$target->x} {$target->y} {$target->z}"),
            'spawned a native projectile victim');
        $arrow->setMotion(new Vector3(0, 0, 0.25));
        ++$this->launches;
    }

    public function onChildDamage(EntityDamageByChildEntityEvent $event) : void{
        if($event->getChild() !== $this->damageArrow){
            return;
        }
        self::expect($event->getDamager() === $this->damageArrow->getOwningEntity() &&
            $event->getEntity()->getFalconTypeId() === 'minecraft:cow' && $event->getCause() === EntityDamageEvent::CAUSE_PROJECTILE,
            'arrow, shooter and victim identities');
        $this->arrowVictim = $event->getEntity();
        $event->setBaseDamage(0.5);
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

    public function onInventoryTransaction(InventoryTransactionEvent $event) : void{
        $transaction = $event->getTransaction();
        foreach($transaction->getActions() as $action){
            if($action instanceof SlotChangeAction && $action->getSlot() === 2 &&
                $action->getInventory() === $transaction->getSource()->getInventory() &&
                $action->getSourceItem()->getFalconIdentifier() === 'minecraft:oak_log' && ++$this->craftInputMoves === 1){
                $event->cancel();
            }
        }
    }

    public function onInventoryOpen(InventoryOpenEvent $event) : void{
        $inventory = $event->getInventory();
        if($inventory instanceof BlockInventory &&
            $inventory->getHolder()->getWorld()->getBlock($inventory->getHolder())->getFalconIdentifier() === 'minecraft:enchanting_table'){
            ++$this->enchantOpens;
        }
    }

    public function onInventoryClose(InventoryCloseEvent $event) : void{
        $inventory = $event->getInventory();
        if($inventory instanceof BlockInventory &&
            $inventory->getHolder()->getWorld()->getBlock($inventory->getHolder())->getFalconIdentifier() === 'minecraft:enchanting_table'){
            ++$this->enchantCloses;
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
        if($event->getMessage() === 'sensitive-fixture-projectiles-verify'){
            $event->cancel();
            $player = $event->getPlayer();
            $inventory = $player->getInventory();
            self::expect($this->bows === 4, "bow event count: {$this->bows}");
            self::expect($this->launches === 1, "arrow launch count: {$this->launches}");
            self::expect($this->replacementLaunched, 'replacement snowball was not launched');
            self::expect($this->cancelledReplacement?->isClosed() === true, 'cancelled replacement did not despawn');
            $arrows = $inventory->getItem(1)->getCount();
            $damage = $inventory->getItem(0)->getDamage();
            self::expect($arrows === 1 && $damage === 2, "bow consumption: $arrows arrows left, $damage durability spent");
            $player->sendMessage('sensitive-fixture-projectiles-ok');
            $this->getLogger()->info('bow cancellation, force and replacement launch passed');
            return;
        }
        if($event->getMessage() === 'sensitive-fixture-interaction-verify'){
            $event->cancel();
            $player = $event->getPlayer();
            $inventory = $player->getInventory();
            self::expect($this->decodePicks === 3 && $this->receivePicks === 2 && $this->blockPicks === 1 &&
                $inventory->getItem(7)->getCustomName() === 'Fixture block pick', 'pre-decode cancellation, receive cancellation and block pick');
            self::expect($this->entityPicks === 1 && $inventory->getItem(8)->getCustomName() === 'Fixture entity pick',
                'entity pick result');
            $spawn = $player->getSpawn();
            self::expect($player->hasValidCustomSpawn() && $spawn->getFloorX() === $this->anchor->getFloorX() &&
                $spawn->getFloorZ() === $this->anchor->getFloorZ() &&
                $player->getWorld()->getBlock($this->anchor)->getFalconIdentifier() === 'minecraft:respawn_anchor',
                'anchor sets respawn and survives instead of exploding');
            $growth = $player->getWorld()->getBlock($this->crop)->getStates()['growth'] ?? -1;
            self::expect($this->fertilizations === 2 && $growth > 0,
                "bone meal retry produced $growth after {$this->fertilizations} events");
            $inventory->clear(6);
            $inventory->clear(7);
            $inventory->clear(8);
            $player->setGamemode(GameMode::SURVIVAL);
            $inventory->setHeldItemIndex(0);
            falcon_player_send_message($player->getHandle(), 'sensitive-fixture-interaction-raw');
            return;
        }
        if($event->getMessage() !== 'sensitive-fixture-verify'){
            return;
        }
        $event->cancel();
        $player = $event->getPlayer();
        $inventory = $player->getInventory();
        self::expect($this->misses === 1, 'miss swing from PlayerAuthInput');
        self::expect($this->arrowVictim !== null &&
            abs($this->arrowVictim->getHealth() - ($this->arrowVictim->getMaxHealth() - 0.5)) < 0.001,
            'native projectile applied the child damage listener amount');
        self::expect($this->craftInputMoves === 2, 'native inventory transaction cancellation and retry');
        self::expect($this->enchantOpens === 1 && $this->enchantCloses === 1 && $player->getCurrentWindow() === null,
            'native inventory open and close events');
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
