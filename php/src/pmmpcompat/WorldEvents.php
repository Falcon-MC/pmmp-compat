<?php

declare(strict_types=1);

namespace pmmpcompat;

use pmmpcompat\world\FalconChunk;
use pocketmine\block\Block;
use pocketmine\block\tile\Furnace;
use pocketmine\block\VanillaBlocks;
use pocketmine\data\bedrock\EffectIdMap;
use pocketmine\entity\effect\EffectInstance;
use pocketmine\entity\effect\EffectManager;
use pocketmine\entity\Entity;
use pocketmine\entity\Human;
use pocketmine\entity\Living;
use pocketmine\entity\object\ItemEntity;
use pocketmine\entity\projectile\Projectile;
use pocketmine\entity\utils\ExperienceUtils;
use pocketmine\event\block\BaseBlockChangeEvent;
use pocketmine\event\block\BlockBurnEvent;
use pocketmine\event\block\BlockExplodeEvent;
use pocketmine\event\block\BlockFormEvent;
use pocketmine\event\block\BlockGrowEvent;
use pocketmine\event\block\BlockMeltEvent;
use pocketmine\event\block\BlockSpreadEvent;
use pocketmine\event\block\LeavesDecayEvent;
use pocketmine\event\entity\EntityEffectAddEvent;
use pocketmine\event\entity\EntityExplodeEvent;
use pocketmine\event\entity\EntityItemPickupEvent;
use pocketmine\event\entity\EntityRegainHealthEvent;
use pocketmine\event\entity\EntitySpawnEvent;
use pocketmine\event\entity\ItemSpawnEvent;
use pocketmine\event\entity\ProjectileHitBlockEvent;
use pocketmine\event\entity\ProjectileHitEntityEvent;
use pocketmine\event\entity\ProjectileLaunchEvent;
use pocketmine\event\Event;
use pocketmine\event\inventory\FurnaceBurnEvent;
use pocketmine\event\inventory\FurnaceSmeltEvent;
use pocketmine\event\player\PlayerBedEnterEvent;
use pocketmine\event\player\PlayerBedLeaveEvent;
use pocketmine\event\player\PlayerBucketEmptyEvent;
use pocketmine\event\player\PlayerBucketFillEvent;
use pocketmine\event\player\PlayerDropItemEvent;
use pocketmine\event\player\PlayerEntityInteractEvent;
use pocketmine\event\player\PlayerExperienceChangeEvent;
use pocketmine\event\player\PlayerItemConsumeEvent;
use pocketmine\event\player\PlayerItemHeldEvent;
use pocketmine\event\world\ChunkLoadEvent;
use pocketmine\event\world\ChunkUnloadEvent;
use pocketmine\event\world\WorldLoadEvent;
use pocketmine\event\world\WorldSaveEvent;
use pocketmine\event\world\WorldUnloadEvent;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\RayTraceResult;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\Server;
use pocketmine\world\Position;
use pocketmine\world\World;

/**
 * The events of the world around the players: items dropped and picked up, projectiles, explosions, growth, worlds
 * and chunks, effects, healing and experience. Bridge passes them here, and asks here what goes back to Falcon.
 */
final class WorldEvents{
    public const PLAYER_DROP_ITEM = 12;
    public const PLAYER_PICKUP_ITEM = 13;
    public const PLAYER_INTERACT_ENTITY = 16;
    public const PROJECTILE_HIT = 26;
    public const EXPLOSION = 27;
    public const BLOCK_BURN = 29;
    public const PLAYER_ITEM_HELD = 35;
    public const PLAYER_ITEM_CONSUME = 36;
    public const PLAYER_BED_ENTER = 37;
    public const PLAYER_BED_LEAVE = 38;
    public const PLAYER_EXPERIENCE_CHANGE = 41;
    public const ENTITY_SPAWN = 42;
    public const PROJECTILE_LAUNCH = 45;
    public const BLOCK_GROW = 46;
    public const BLOCK_SPREAD = 47;
    public const BLOCK_FORM = 48;
    public const BLOCK_FADE = 49;
    public const LEAVES_DECAY = 55;
    public const CHUNK_LOAD = 58;
    public const CHUNK_UNLOAD = 59;
    public const FURNACE_SMELT = 60;
    public const FURNACE_BURN = 61;
    public const WORLD_LOAD = 65;
    public const WORLD_UNLOAD = 66;
    public const PLAYER_BUCKET_FILL = 72;
    public const PLAYER_BUCKET_EMPTY = 73;
    public const ENTITY_EFFECT_ADD = 77;
    public const ENTITY_HEAL = 78;
    public const ITEM_SPAWN = 88;
    public const WORLD_SAVE = 90;

    /** the PocketMine-MP events each Falcon event can become */
    public const SOURCES = [
        self::PLAYER_DROP_ITEM => [PlayerDropItemEvent::class],
        self::PLAYER_PICKUP_ITEM => [EntityItemPickupEvent::class],
        self::PLAYER_INTERACT_ENTITY => [PlayerEntityInteractEvent::class],
        self::PROJECTILE_HIT => [ProjectileHitEntityEvent::class, ProjectileHitBlockEvent::class],
        self::EXPLOSION => [EntityExplodeEvent::class, BlockExplodeEvent::class],
        self::BLOCK_BURN => [BlockBurnEvent::class],
        self::PLAYER_ITEM_HELD => [PlayerItemHeldEvent::class],
        self::PLAYER_ITEM_CONSUME => [PlayerItemConsumeEvent::class],
        self::PLAYER_BED_ENTER => [PlayerBedEnterEvent::class],
        self::PLAYER_BED_LEAVE => [PlayerBedLeaveEvent::class],
        self::PLAYER_EXPERIENCE_CHANGE => [PlayerExperienceChangeEvent::class],
        self::ENTITY_SPAWN => [EntitySpawnEvent::class, ItemSpawnEvent::class],
        self::PROJECTILE_LAUNCH => [ProjectileLaunchEvent::class],
        self::BLOCK_GROW => [BlockGrowEvent::class],
        self::BLOCK_SPREAD => [BlockSpreadEvent::class],
        self::BLOCK_FORM => [BlockFormEvent::class],
        self::BLOCK_FADE => [BlockMeltEvent::class],
        self::LEAVES_DECAY => [LeavesDecayEvent::class],
        self::CHUNK_LOAD => [ChunkLoadEvent::class],
        self::CHUNK_UNLOAD => [ChunkUnloadEvent::class],
        self::FURNACE_SMELT => [FurnaceSmeltEvent::class],
        self::FURNACE_BURN => [FurnaceBurnEvent::class],
        self::WORLD_LOAD => [WorldLoadEvent::class],
        self::WORLD_UNLOAD => [WorldUnloadEvent::class],
        self::PLAYER_BUCKET_FILL => [PlayerBucketFillEvent::class],
        self::PLAYER_BUCKET_EMPTY => [PlayerBucketEmptyEvent::class],
        self::ENTITY_EFFECT_ADD => [EntityEffectAddEvent::class],
        self::ENTITY_HEAL => [EntityRegainHealthEvent::class],
        self::WORLD_SAVE => [WorldSaveEvent::class],
    ];

    /** Falcon's healing causes */
    private const HEAL_CAUSES = [
        "heal" => EntityRegainHealthEvent::CAUSE_MAGIC,
        "regeneration" => EntityRegainHealthEvent::CAUSE_REGEN,
        "self_heal" => EntityRegainHealthEvent::CAUSE_SATURATION,
        "totem" => EntityRegainHealthEvent::CAUSE_CUSTOM,
    ];

    private function __construct(){
    }

    /**
     * The PocketMine-MP event for one of Falcon's, with what applyToFalcon() compares against.
     * @return array<string, mixed>|null the event under "event", null when none stands for it
     */
    public static function create(int $type, int $handle, ?Player $player) : ?array{
        switch($type){
            case self::PLAYER_DROP_ITEM:
                $item = self::item($handle);
                return $player === null || $item === null ? null : ['event' => new PlayerDropItemEvent($player, $item)];
            case self::PLAYER_PICKUP_ITEM:
                $item = self::item($handle);
                if($player === null || $item === null){
                    return null;
                }
                $origin = new ItemEntity(0);
                $origin->setItemFromFalcon($item);
                return ['event' => new EntityItemPickupEvent($player, $origin, $item, $player->getInventory())];
            case self::PLAYER_INTERACT_ENTITY:
                $entity = self::entity($handle);
                return $player === null || $entity === null ? null
                    : ['event' => new PlayerEntityInteractEvent($player, $entity, $entity->getPosition()->asVector3())];
            case self::PROJECTILE_HIT:
                $projectile = self::entity($handle);
                if(!$projectile instanceof Projectile){
                    return null;
                }
                [$x, $y, $z] = falcon_event_position($handle);
                $hit = new Vector3($x, $y, $z);
                $result = new RayTraceResult(new AxisAlignedBB($x, $y, $z, $x, $y, $z), 0, $hit);
                $target = falcon_event_target($handle);
                if($target !== 0){
                    return ['event' => new ProjectileHitEntityEvent($projectile, $result, Entities::get($target))];
                }
                $block = self::block($handle);
                return $block === null ? null : ['event' => new ProjectileHitBlockEvent($projectile, $result, $block)];
            case self::EXPLOSION:
                return self::explosion($handle);
            case self::BLOCK_BURN:
                $block = self::block($handle);
                return $block === null ? null : ['event' => new BlockBurnEvent($block, VanillaBlocks::FIRE())];
            case self::PLAYER_ITEM_HELD:
                if($player === null){
                    return null;
                }
                $slot = falcon_event_transaction($handle)[4];
                $item = self::item($handle) ?? $player->getInventory()->getItem($slot);
                return ['event' => new PlayerItemHeldEvent($player, $item, $slot)];
            case self::PLAYER_ITEM_CONSUME:
                $item = self::item($handle);
                return $player === null || $item === null ? null : ['event' => new PlayerItemConsumeEvent($player, $item)];
            case self::PLAYER_BED_ENTER:
            case self::PLAYER_BED_LEAVE:
                $bed = self::block($handle, $player?->getWorld());
                if($player === null || $bed === null){
                    return null;
                }
                return ['event' => $type === self::PLAYER_BED_ENTER ? new PlayerBedEnterEvent($player, $bed) : new PlayerBedLeaveEvent($player, $bed)];
            case self::PLAYER_EXPERIENCE_CHANGE:
                if($player === null){
                    return null;
                }
                $xp = $player->getXpManager();
                $total = $xp->getCurrentTotalXp();
                $amount = (int) falcon_event_amount($handle);
                $after = ExperienceUtils::getLevelFromXp(max(0, $total + $amount));
                return ['event' => new PlayerExperienceChangeEvent($player, $xp->getXpLevel(), $xp->getXpProgress(),
                    (int) $after, $after - (int) $after), 'total' => $total, 'amount' => $amount];
            case self::ENTITY_SPAWN:
                $entity = self::entity($handle);
                if($entity === null){
                    return null;
                }
                return ['event' => $entity instanceof ItemEntity ? new ItemSpawnEvent($entity) : new EntitySpawnEvent($entity)];
            case self::PROJECTILE_LAUNCH:
                $projectile = self::entity($handle);
                if(!$projectile instanceof Projectile){
                    return null;
                }
                $shooter = falcon_event_attacker($handle);
                $projectile->setOwnerFromFalcon($shooter === 0 ? null : Entities::get($shooter));
                return ['event' => new ProjectileLaunchEvent($projectile)];
            case self::BLOCK_GROW:
            case self::BLOCK_SPREAD:
            case self::BLOCK_FORM:
            case self::BLOCK_FADE:
                return self::blockChange($type, $handle);
            case self::LEAVES_DECAY:
                $block = self::block($handle);
                return $block === null ? null : ['event' => new LeavesDecayEvent($block)];
            case self::CHUNK_LOAD:
            case self::CHUNK_UNLOAD:
                $world = self::world($handle);
                if($world === null){
                    return null;
                }
                [$chunkX, $chunkZ] = falcon_event_chunk($handle);
                $chunk = new FalconChunk($world, $chunkX, $chunkZ);
                return ['event' => $type === self::CHUNK_LOAD
                    ? new ChunkLoadEvent($world, $chunkX, $chunkZ, $chunk, falcon_event_state($handle))
                    : new ChunkUnloadEvent($world, $chunkX, $chunkZ, $chunk)];
            case self::FURNACE_SMELT:
            case self::FURNACE_BURN:
                $world = self::world($handle);
                $item = self::item($handle);
                $block = self::block($handle, $world);
                $furnace = $block?->getPosition()->getWorld()->getTile($block->getPosition());
                if(!$furnace instanceof Furnace || $item === null){
                    return null;
                }
                if($type === self::FURNACE_BURN){
                    return ['event' => new FurnaceBurnEvent($furnace, $item, (int) falcon_event_amount($handle))];
                }
                $result = falcon_event_result($handle);
                return $result === null ? null : ['event' => new FurnaceSmeltEvent($furnace, $item, Item::fromFalcon($result))];
            case self::WORLD_LOAD:
            case self::WORLD_UNLOAD:
            case self::WORLD_SAVE:
                $world = self::world($handle) ?? Server::getInstance()->getWorldManager()->getWorldByName(falcon_event_world_name($handle));
                if($world === null){
                    return null;
                }
                if($type === self::WORLD_UNLOAD){
                    return ['event' => new WorldUnloadEvent($world), 'world' => $world];
                }
                return ['event' => $type === self::WORLD_LOAD ? new WorldLoadEvent($world) : new WorldSaveEvent($world)];
            case self::PLAYER_BUCKET_FILL:
            case self::PLAYER_BUCKET_EMPTY:
                $block = self::block($handle, $player?->getWorld());
                $bucket = self::item($handle);
                if($player === null || $block === null || $bucket === null){
                    return null;
                }
                $face = falcon_event_block($handle)[3];
                $hand = $player->getInventory()->getItemInHand();
                return ['event' => $type === self::PLAYER_BUCKET_FILL
                    ? new PlayerBucketFillEvent($player, $block, $face, $bucket, $hand)
                    : new PlayerBucketEmptyEvent($player, $block, $face, $bucket, $hand)];
            case self::ENTITY_EFFECT_ADD:
                // an effect this runtime asked for already called its event
                $entity = self::entity($handle);
                $effect = falcon_event_effect($handle);
                if(EffectManager::$applying || !$entity instanceof Living || $effect === null){
                    return null;
                }
                [$id, $duration, $amplifier, $particles, $ambient] = $effect;
                $kind = EffectIdMap::getInstance()->fromId($id);
                if($kind === null){
                    return null;
                }
                $instance = new EffectInstance($kind, $duration < 0 ? 0 : $duration, $amplifier, $particles, $ambient, null, $duration < 0);
                return ['event' => new EntityEffectAddEvent($entity, $instance, $entity->getEffects()->get($kind))];
            case self::ENTITY_HEAL:
                $entity = self::entity($handle);
                if($entity === null){
                    return null;
                }
                $amount = falcon_event_amount($handle);
                $cause = self::HEAL_CAUSES[falcon_event_cause($handle)] ?? EntityRegainHealthEvent::CAUSE_CUSTOM;
                return ['event' => new EntityRegainHealthEvent($entity, $amount, $cause), 'amount' => $amount];
        }
        return null;
    }

    /** what the listeners changed goes back to Falcon */
    public static function apply(int $handle, array &$state, bool $last) : void{
        $event = $state['event'];
        if($event instanceof EntityRegainHealthEvent){
            if($event->getAmount() !== $state['amount']){
                falcon_event_set_amount($handle, $event->getAmount());
                $state['amount'] = $event->getAmount();
            }
        }elseif($event instanceof PlayerExperienceChangeEvent){
            $level = $event->getNewLevel();
            $progress = $event->getNewProgress();
            if($level !== null && $progress !== null){
                $amount = (int) round(ExperienceUtils::getXpToReachLevel($level)
                    + ExperienceUtils::getXpToCompleteLevel($level) * $progress) - $state['total'];
                if($amount !== $state['amount']){
                    falcon_event_set_amount($handle, $amount);
                    $state['amount'] = $amount;
                }
            }
        }elseif($event instanceof EntityExplodeEvent || $event instanceof BlockExplodeEvent){
            $blocks = array_map(static fn(Block $block) : array => [
                $block->getPosition()->getFloorX(), $block->getPosition()->getFloorY(), $block->getPosition()->getFloorZ(),
            ], $event->getBlockList());
            if($blocks !== $state['blocks']){
                falcon_event_set_blocks($handle, $blocks);
                $state['blocks'] = $blocks;
            }
        }elseif($event instanceof FurnaceSmeltEvent){
            $result = $event->getResult()->toFalcon();
            if($result !== $state['result']){
                falcon_event_set_result($handle, $result);
                $state['result'] = $result;
            }
        }elseif($last && $event instanceof ChunkLoadEvent){
            $event->getWorld()->notifyChunkListeners($event->getChunkX(), $event->getChunkZ(), $event->getChunk(), true);
        }elseif($last && $event instanceof ChunkUnloadEvent && !$event->isCancelled()){
            $event->getWorld()->notifyChunkListeners($event->getChunkX(), $event->getChunkZ(), $event->getChunk(), false);
        }elseif($event instanceof WorldUnloadEvent && $last && !$event->isCancelled()){
            $state['world']->onUnload();
        }
    }

    private static function explosion(int $handle) : ?array{
        $world = self::world($handle);
        if($world === null){
            return null;
        }
        [$x, $y, $z] = falcon_event_position($handle);
        $position = new Position($x, $y, $z, $world);
        $positions = falcon_event_blocks($handle);
        $blocks = array_map(static fn(array $at) : Block => $world->getBlockAt($at[0], $at[1], $at[2]), $positions);
        $size = falcon_event_amount($handle);
        $yield = $size > 0 ? min(100, 100 / $size) : 100;
        $entity = self::entity($handle);
        $event = $entity !== null
            ? new EntityExplodeEvent($entity, $position, $blocks, $yield)
            : new BlockExplodeEvent($world->getBlock($position), $position, $blocks, $yield, []);
        return ['event' => $event, 'blocks' => $positions];
    }

    private static function blockChange(int $type, int $handle) : ?array{
        $block = self::block($handle);
        if($block === null){
            return null;
        }
        [$x, $y, $z, , $name] = falcon_event_block($handle);
        $world = $block->getPosition()->getWorld();
        $newState = Block::fromFalcon($name, "{}")->withPosition($world, $x, $y, $z);
        [$fromX, $fromY, $fromZ] = falcon_event_from($handle);
        $source = $world->getBlockAt((int) floor($fromX), (int) floor($fromY), (int) floor($fromZ));
        $event = match($type){
            self::BLOCK_GROW => new BlockGrowEvent($block, $newState),
            self::BLOCK_SPREAD => new BlockSpreadEvent($block, $source, $newState),
            self::BLOCK_FORM => new BlockFormEvent($block, $newState, $source),
            default => new BlockMeltEvent($block, $newState),
        };
        return ['event' => $event];
    }

    private static function item(int $handle) : ?Item{
        $data = falcon_event_item($handle);
        return $data === null ? null : Item::fromFalcon($data);
    }

    private static function entity(int $handle) : ?Entity{
        $entity = falcon_event_entity($handle);
        return $entity === 0 ? null : Entities::get($entity);
    }

    private static function world(int $handle) : ?World{
        return Server::getInstance()->getWorldManager()->forLevel(falcon_event_level($handle));
    }

    /** the event's block as the world has it now */
    private static function block(int $handle, ?World $fallback = null) : ?Block{
        $world = self::world($handle) ?? $fallback;
        if($world === null){
            return null;
        }
        [$x, $y, $z] = falcon_event_block($handle);
        return $world->getBlockAt($x, $y, $z);
    }
}
