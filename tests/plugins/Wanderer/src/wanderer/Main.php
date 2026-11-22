<?php

declare(strict_types=1);

namespace wanderer;

use pocketmine\block\Air;
use pocketmine\block\BlockTypeIds;
use pocketmine\block\Opaque;
use pocketmine\block\tile\Chest;
use pocketmine\block\tile\Sign;
use pocketmine\block\Transparent;
use pocketmine\block\utils\SignText;
use pocketmine\block\VanillaBlocks;
use pocketmine\block\Water;
use pocketmine\entity\effect\EffectInstance;
use pocketmine\entity\effect\VanillaEffects;
use pocketmine\event\entity\EntityEffectAddEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerDeathEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\world\ChunkLoadEvent;
use pocketmine\inventory\ArmorInventory;
use pocketmine\item\Armor;
use pocketmine\item\Food;
use pocketmine\item\ItemBlock;
use pocketmine\item\ItemTypeIds;
use pocketmine\item\Sword;
use pocketmine\item\ToolTier;
use pocketmine\item\VanillaItems;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\ClosureTask;
use pocketmine\world\particle\HeartParticle;
use pocketmine\world\sound\PopSound;

// the rest of the world: typed items and blocks, tiles, effects, hunger, experience, sounds, drops and the server
final class Main extends PluginBase implements Listener{
    private int $passed = 0;
    private int $total = 0;
    private bool $chunkSeen = false;

    private function check(string $what, bool $ok) : void{
        ++$this->total;
        if($ok){
            ++$this->passed;
        }else{
            $this->getLogger()->error("fixture: Wanderer check failed: $what");
        }
    }

    protected function onEnable() : void{
        $this->getServer()->getPluginManager()->registerEvents($this, $this);

        $sword = VanillaItems::DIAMOND_SWORD();
        $this->check("sword", $sword instanceof Sword && $sword->getMaxDurability() === 1561 && $sword->getTier() === ToolTier::DIAMOND
            && $sword->getAttackPoints() === 7 && $sword->getMaxStackSize() === 1);
        $chestplate = VanillaItems::DIAMOND_CHESTPLATE();
        $this->check("armor", $chestplate instanceof Armor && $chestplate->getDefensePoints() === 8
            && $chestplate->getArmorSlot() === ArmorInventory::SLOT_CHEST);
        $apple = VanillaItems::APPLE();
        $this->check("food", $apple instanceof Food && $apple->getFoodRestore() === 4);
        $stoneItem = VanillaBlocks::STONE()->asItem();
        $this->check("item block", $stoneItem instanceof ItemBlock && $stoneItem->getBlock() instanceof Opaque);
        $this->check("blocks", VanillaBlocks::STONE() instanceof Opaque && VanillaBlocks::AIR() instanceof Air
            && VanillaBlocks::GLASS() instanceof Transparent && VanillaBlocks::WATER() instanceof Water
            && VanillaBlocks::STONE()->getBreakInfo()->getHardness() === 1.5);
        $this->check("type ids", BlockTypeIds::STONE === VanillaBlocks::STONE()->getTypeId()
            && ItemTypeIds::DIAMOND_SWORD === $sword->getTypeId());
        $this->check("effects", VanillaEffects::SPEED()->getDefaultDuration() === 600);

        $server = $this->getServer();
        $id = $server->getServerUniqueId();
        $this->check("server id", $id->equals($server->getServerUniqueId()) && strlen($id->toString()) === 36);
        $this->check("resource packs", is_array($server->getResourcePackManager()->getPackIdList()));
        $this->check("slots", $server->getMaxPlayers() > 0);
        $bans = $server->getNameBans();
        $bans->addBan("Nobody", "testing", null, "Wanderer");
        $banned = $bans->isBanned("nobody");
        $bans->remove("Nobody");
        $this->check("bans", $banned && !$bans->isBanned("Nobody"));

        $world = $server->getWorldManager()->getDefaultWorld();
        $spawn = $world->getSpawnLocation();
        $x = (int) $spawn->x;
        $z = (int) $spawn->z;
        $world->setBlockAt($x, 251, $z, VanillaBlocks::CHEST());
        $tile = $world->getTileAt($x, 251, $z);
        if($tile instanceof Chest){
            $tile->getInventory()->setItem(0, VanillaItems::DIAMOND()->setCount(5));
            $this->check("chest tile", $tile->getInventory()->getSize() === 27
                && $world->getTileAt($x, 251, $z)->getInventory()->getItem(0)->getCount() === 5);
            $tile->getInventory()->clearAll();
        }else{
            $this->check("chest tile", false);
        }
        $world->setBlockAt($x, 251, $z, VanillaBlocks::AIR());
        $this->check("tile gone", $world->getTileAt($x, 251, $z) === null);
        $world->setBlockAt($x, 252, $z, VanillaBlocks::OAK_SIGN());
        $sign = $world->getTileAt($x, 252, $z);
        if($sign instanceof Sign){
            $sign->setText(new SignText(["Hello", "from PHP"]));
            $this->check("sign tile", $sign->getText()->getLine(1) === "from PHP");
        }else{
            $this->check("sign tile", false);
        }
        $world->setBlockAt($x, 252, $z, VanillaBlocks::AIR());
        $this->check("weather", is_bool($world->isRaining()));

        $this->getLogger()->info("fixture: Wanderer {$this->passed} of {$this->total} checks passed");
    }

    public function onChunk(ChunkLoadEvent $event) : void{
        if(!$this->chunkSeen){
            $this->chunkSeen = true;
            $this->getLogger()->info("fixture: Wanderer saw a chunk load in " . $event->getWorld()->getFolderName());
        }
    }

    public function onEffect(EntityEffectAddEvent $event) : void{
        if($event->getEntity() instanceof Player){
            $this->getLogger()->info("fixture: Wanderer saw " . $event->getEntity()->getName() . " get effect "
                . $event->getEffect()->getType()->getName()->getText() . " " . $event->getEffect()->getEffectLevel());
        }
    }

    public function onJoin(PlayerJoinEvent $event) : void{
        $player = $event->getPlayer();
        $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($player) : void{
            $logger = $this->getLogger();
            $added = $player->getEffects()->add(new EffectInstance(VanillaEffects::SPEED(), 400, 1));
            $speed = $player->getEffects()->get(VanillaEffects::SPEED());
            $logger->info("fixture: Wanderer gave speed: " . ($added && $speed !== null && $speed->getAmplifier() === 1 && $speed->getDuration() > 0 ? "running" : "missing"));
            $player->getEffects()->remove(VanillaEffects::SPEED());
            $logger->info("fixture: Wanderer took speed away: " . ($player->getEffects()->has(VanillaEffects::SPEED()) ? "still there" : "gone"));

            $hunger = $player->getHungerManager();
            $hunger->setFood(15);
            $logger->info("fixture: Wanderer food " . $hunger->getFood() . " of " . $hunger->getMaxFood());
            $player->getXpManager()->setXpLevel(3);
            $logger->info("fixture: Wanderer xp level " . $player->getXpManager()->getXpLevel());
            $logger->info("fixture: Wanderer skin " . ($player->getSkin()->getSkinId() !== "" ? "known" : "missing"));

            $player->getWorld()->addSound($player->getPosition(), new PopSound());
            $player->getWorld()->addParticle($player->getPosition(), new HeartParticle());
            $logger->info("fixture: Wanderer played a sound and a particle");
        }), 5);
    }

    public function onDeath(PlayerDeathEvent $event) : void{
        $player = $event->getPlayer();
        $this->getLogger()->info("fixture: Wanderer death drops " . count($event->getDrops()) . " stacks, xp " . $event->getXpDropAmount());
        $event->setKeepInventory(true);
        $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($player) : void{
            $this->getLogger()->info("fixture: Wanderer kept " . $player->getInventory()->getItem(0)->getName() . " after death");
        }), 1);
    }
}
