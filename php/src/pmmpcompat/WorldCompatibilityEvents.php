<?php

declare(strict_types=1);

namespace pmmpcompat;

use pmmpcompat\world\FalconChunk;
use pocketmine\block\Block;
use pocketmine\block\Campfire;
use pocketmine\block\Chest;
use pocketmine\block\Liquid;
use pocketmine\block\tile\BrewingStand;
use pocketmine\crafting\ExactRecipeIngredient;
use pocketmine\crafting\PotionContainerChangeRecipe;
use pocketmine\crafting\PotionTypeRecipe;
use pocketmine\entity\Living;
use pocketmine\entity\object\AreaEffectCloud;
use pocketmine\event\block\BlockDeathEvent;
use pocketmine\event\block\BlockPreExplodeEvent;
use pocketmine\event\block\BlockTeleportEvent;
use pocketmine\event\block\BlockUpdateEvent;
use pocketmine\event\block\BrewItemEvent;
use pocketmine\event\block\BrewingFuelUseEvent;
use pocketmine\event\block\CampfireCookEvent;
use pocketmine\event\block\ChestPairEvent;
use pocketmine\event\block\FarmlandHydrationChangeEvent;
use pocketmine\event\block\PressurePlateUpdateEvent;
use pocketmine\event\block\StructureGrowEvent;
use pocketmine\event\entity\AreaEffectCloudApplyEvent;
use pocketmine\event\entity\EntityBlockChangeEvent;
use pocketmine\event\entity\EntityFrostWalkerEvent;
use pocketmine\event\entity\EntityTrampleFarmlandEvent;
use pocketmine\event\server\UpdateNotifyEvent;
use pocketmine\event\world\ChunkPopulateEvent;
use pocketmine\event\world\SpawnChangeEvent;
use pocketmine\event\world\WorldDifficultyChangeEvent;
use pocketmine\event\world\WorldInitEvent;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\Server;
use pocketmine\world\BlockTransaction;
use pocketmine\world\Position;
use pocketmine\world\World;

final class WorldCompatibilityEvents{
    private const BLOCK_DEATH = 91;
    private const BLOCK_PRE_EXPLODE = 92;
    private const BLOCK_TELEPORT = 93;
    private const BLOCK_UPDATE = 94;
    private const BREWING_FUEL_USE = 95;
    private const CAMPFIRE_COOK = 96;
    private const CHEST_PAIR = 97;
    private const FARMLAND_HYDRATION_CHANGE = 98;
    private const PRESSURE_PLATE_UPDATE = 99;
    private const STRUCTURE_GROW = 100;
    private const CHUNK_POPULATE = 101;
    private const SPAWN_CHANGE = 102;
    private const WORLD_DIFFICULTY_CHANGE = 103;
    private const WORLD_INIT = 104;
    private const ENTITY_BLOCK_CHANGE = 105;
    private const ENTITY_FROST_WALKER = 106;
    private const ENTITY_TRAMPLE_FARMLAND = 107;
    private const AREA_EFFECT_CLOUD_APPLY = 108;
    private const BREW_ITEM = 110;
    private const UPDATE_NOTIFY = 111;

    public const SOURCES = [
        self::BLOCK_DEATH => [BlockDeathEvent::class],
        self::BLOCK_PRE_EXPLODE => [BlockPreExplodeEvent::class],
        self::BLOCK_TELEPORT => [BlockTeleportEvent::class],
        self::BLOCK_UPDATE => [BlockUpdateEvent::class],
        self::BREWING_FUEL_USE => [BrewingFuelUseEvent::class],
        self::CAMPFIRE_COOK => [CampfireCookEvent::class],
        self::CHEST_PAIR => [ChestPairEvent::class],
        self::FARMLAND_HYDRATION_CHANGE => [FarmlandHydrationChangeEvent::class],
        self::PRESSURE_PLATE_UPDATE => [PressurePlateUpdateEvent::class],
        self::STRUCTURE_GROW => [StructureGrowEvent::class],
        self::CHUNK_POPULATE => [ChunkPopulateEvent::class],
        self::SPAWN_CHANGE => [SpawnChangeEvent::class],
        self::WORLD_DIFFICULTY_CHANGE => [WorldDifficultyChangeEvent::class],
        self::WORLD_INIT => [WorldInitEvent::class],
        self::ENTITY_BLOCK_CHANGE => [EntityBlockChangeEvent::class],
        self::ENTITY_FROST_WALKER => [EntityFrostWalkerEvent::class],
        self::ENTITY_TRAMPLE_FARMLAND => [EntityTrampleFarmlandEvent::class],
        self::AREA_EFFECT_CLOUD_APPLY => [AreaEffectCloudApplyEvent::class],
        self::BREW_ITEM => [BrewItemEvent::class],
        self::UPDATE_NOTIFY => [UpdateNotifyEvent::class],
    ];

    private function __construct(){
    }

    /** @return array<string, mixed>|null */
    public static function create(int $type, int $handle, ?Player $player) : ?array{
        $server = Server::getInstance();
        if($type === self::UPDATE_NOTIFY){
            $updater = $server->getUpdater();
            return $updater->recordRelease(falcon_event_data($handle)) ? ['event' => new UpdateNotifyEvent($updater)] : null;
        }

        $world = $server->getWorldManager()->forLevel(falcon_event_level($handle));
        if($world === null){
            return null;
        }
        switch($type){
            case self::WORLD_INIT:
                return ['event' => new WorldInitEvent($world)];
            case self::SPAWN_CHANGE:
                [$x, $y, $z] = falcon_event_from($handle);
                return ['event' => new SpawnChangeEvent($world, new Position($x, $y, $z, $world))];
            case self::WORLD_DIFFICULTY_CHANGE:
                return ['event' => new WorldDifficultyChangeEvent($world, (int) falcon_event_previous_amount($handle),
                    (int) falcon_event_amount($handle))];
            case self::CHUNK_POPULATE:
                [$x, $z] = falcon_event_chunk($handle);
                return ['event' => new ChunkPopulateEvent($world, $x, $z, new FalconChunk($world, $x, $z))];
        }

        $data = self::readData($handle);
        $actor = falcon_event_entity($handle);
        $entity = $actor === 0 ? null : Entities::get($actor);
        if($type === self::AREA_EFFECT_CLOUD_APPLY){
            if(!$entity instanceof AreaEffectCloud){
                return null;
            }
            $entity->recordApplication($data);
            return ['event' => new AreaEffectCloudApplyEvent($entity,
                array_values(array_filter(self::entities($data['entities']), static fn($target) => $target instanceof Living)))];
        }
        if($type === self::ENTITY_FROST_WALKER){
            $liquid = self::decodeBlock($data['liquid'] ?? []);
            if(!$entity instanceof Living || !$liquid instanceof Liquid){
                return null;
            }
            $block = self::decodeBlock($data['target'] ?? []);
            $radius = (int) falcon_event_amount($handle);
            return ['event' => new EntityFrostWalkerEvent($entity, $radius, $liquid, $block),
                'radius' => $radius, 'liquid' => self::encodeBlock($liquid), 'target' => self::encodeBlock($block)];
        }

        [$x, $y, $z] = falcon_event_block($handle);
        $block = $world->getBlockAt($x, $y, $z);
        switch($type){
            case self::BLOCK_DEATH:
                $newState = self::decodeBlock($data);
                return ['event' => new BlockDeathEvent($block, $newState), 'block' => self::encodeBlock($newState)];
            case self::BLOCK_PRE_EXPLODE:
                $radius = falcon_event_amount($handle);
                $event = new BlockPreExplodeEvent($block, $radius, $player);
                $event->setIncendiary(true);
                return ['event' => $event, 'radius' => $radius, 'breaks' => true, 'fire' => $event->getFireChance()];
            case self::BLOCK_TELEPORT:
                $to = falcon_event_to($handle);
                return ['event' => new BlockTeleportEvent($block, Position::fromObject(new Vector3(...$to), $world)),
                    'to' => $to];
            case self::BLOCK_UPDATE:
                return ['event' => new BlockUpdateEvent($block)];
            case self::CHEST_PAIR:
                $other = $world->getBlock(new Vector3(...falcon_event_to($handle)));
                return $block instanceof Chest && $other instanceof Chest ? ['event' => new ChestPairEvent($block, $other)] : null;
            case self::FARMLAND_HYDRATION_CHANGE:
                $hydration = (int) falcon_event_amount($handle);
                return ['event' => new FarmlandHydrationChangeEvent($block, (int) falcon_event_previous_amount($handle),
                    $hydration), 'amount' => $hydration];
            case self::PRESSURE_PLATE_UPDATE:
                $newState = self::decodeBlock($data['block'] ?? []);
                return ['event' => new PressurePlateUpdateEvent($block, $newState, self::entities($data['entities'] ?? [])),
                    'block' => self::encodeBlock($newState), 'entities' => $data['entities'] ?? []];
            case self::STRUCTURE_GROW:
                $transaction = self::transaction($world, $data);
                return ['event' => new StructureGrowEvent($block, $transaction, $player),
                    'blocks' => self::encodeTransaction($transaction)];
            case self::ENTITY_BLOCK_CHANGE:
                $newState = self::decodeBlock($data);
                return $entity === null ? null : ['event' => new EntityBlockChangeEvent($entity, $block, $newState),
                    'block' => self::encodeBlock($newState)];
            case self::ENTITY_TRAMPLE_FARMLAND:
                return $entity instanceof Living ? ['event' => new EntityTrampleFarmlandEvent($entity, $block)] : null;
            case self::BREWING_FUEL_USE:
                $brewingStand = $world->getTile($block->getPosition());
                if(!$brewingStand instanceof BrewingStand){
                    return null;
                }
                $fuel = (int) falcon_event_amount($handle);
                $event = new BrewingFuelUseEvent($brewingStand);
                $event->setFuelTime($fuel);
                return ['event' => $event, 'amount' => $fuel];
            case self::BREW_ITEM:
            case self::CAMPFIRE_COOK:
                $input = falcon_event_item($handle);
                if($input === null){
                    return null;
                }
                $rawResult = falcon_event_result($handle);
                $result = $rawResult === null ? VanillaItems::AIR() : Item::fromFalcon($rawResult);
                $item = Item::fromFalcon($input);
                $slot = falcon_event_transaction($handle)[2];
                if($type === self::CAMPFIRE_COOK){
                    return $block instanceof Campfire ? ['event' => new CampfireCookEvent($block, $slot, $item, $result),
                        'result' => $result->toFalcon()] : null;
                }

                $brewingStand = $world->getTile($block->getPosition());
                if(!$brewingStand instanceof BrewingStand){
                    return null;
                }
                $ingredient = new ExactRecipeIngredient($brewingStand->getInventory()->getItem(3));
                $inputId = $item->getFalconIdentifier();
                $outputId = $result->getFalconIdentifier();
                $recipe = $inputId === $outputId
                    ? new PotionTypeRecipe(new ExactRecipeIngredient($item), $ingredient, $result)
                    : new PotionContainerChangeRecipe($inputId, $ingredient, $outputId);
                return ['event' => new BrewItemEvent($brewingStand, $slot, $item, $result, $recipe),
                    'result' => $result->toFalcon()];
        }
        return null;
    }

    public static function apply(int $handle, array &$state, bool $last) : void{
        $event = $state['event'];
        if($event instanceof BlockDeathEvent || $event instanceof PressurePlateUpdateEvent || $event instanceof EntityBlockChangeEvent){
            $block = self::encodeBlock($event instanceof EntityBlockChangeEvent ? $event->getTo() : $event->getNewState());
            if($block !== $state['block']){
                falcon_event_set_data($handle, json_encode($event instanceof PressurePlateUpdateEvent ? ['block' => $block, 'entities' => $state['entities']] : $block,
                    JSON_THROW_ON_ERROR));
                $state['block'] = $block;
            }
        }elseif($event instanceof BlockPreExplodeEvent){
            $radius = $event->getRadius();
            if($radius !== $state['radius']){
                falcon_event_set_amount($handle, $radius);
                $state['radius'] = $radius;
            }
            $breaks = $event->isBlockBreaking();
            $fire = $event->getFireChance();
            if($breaks !== $state['breaks'] || $fire !== $state['fire']){
                falcon_event_set_data($handle, json_encode(['breaks' => $breaks, 'fire' => $fire], JSON_THROW_ON_ERROR));
                $state['breaks'] = $breaks;
                $state['fire'] = $fire;
            }
        }elseif($event instanceof BlockTeleportEvent){
            $to = $event->getTo();
            $position = [$to->x, $to->y, $to->z];
            if($position !== $state['to']){
                falcon_event_set_to($handle, ...$position);
                $state['to'] = $position;
            }
        }elseif($event instanceof FarmlandHydrationChangeEvent || $event instanceof BrewingFuelUseEvent){
            $value = $event instanceof FarmlandHydrationChangeEvent ? $event->getNewHydration() : $event->getFuelTime();
            if($value !== $state['amount']){
                falcon_event_set_amount($handle, $value);
                $state['amount'] = $value;
            }
        }elseif($event instanceof BrewItemEvent || $event instanceof CampfireCookEvent){
            $result = $event->getResult()->toFalcon();
            if($result !== $state['result']){
                falcon_event_set_result($handle, $result);
                $state['result'] = $result;
            }
        }elseif($event instanceof StructureGrowEvent){
            $transaction = $event->getTransaction();
            if($last && !$event->isCancelled() && !$transaction->validate()){
                falcon_event_set_cancelled($handle, true);
                return;
            }
            $blocks = self::encodeTransaction($transaction);
            if($blocks !== $state['blocks']){
                falcon_event_set_data($handle, json_encode($blocks, JSON_THROW_ON_ERROR));
                $state['blocks'] = $blocks;
            }
        }elseif($event instanceof EntityFrostWalkerEvent){
            $radius = $event->getRadius();
            if($radius !== $state['radius']){
                falcon_event_set_amount($handle, $radius);
                $state['radius'] = $radius;
            }
            $liquid = self::encodeBlock($event->getLiquid());
            $target = self::encodeBlock($event->getTargetBlock());
            if($liquid !== $state['liquid'] || $target !== $state['target']){
                falcon_event_set_data($handle, json_encode(['liquid' => $liquid, 'target' => $target], JSON_THROW_ON_ERROR));
                $state['liquid'] = $liquid;
                $state['target'] = $target;
            }
        }elseif($last && $event instanceof ChunkPopulateEvent){
            $event->getWorld()->notifyChunkPopulated($event->getChunkX(), $event->getChunkZ(), $event->getChunk());
        }
    }

    private static function readData(int $handle) : array{
        $data = falcon_event_data($handle);
        if($data === ""){
            return [];
        }
        return json_decode($data, true, 512, JSON_THROW_ON_ERROR);
    }

    private static function decodeBlock(array $state) : Block{
        return Block::fromFalcon($state['identifier'], json_encode((object) $state['states'], JSON_THROW_ON_ERROR));
    }

    private static function encodeBlock(Block $block) : array{
        return ['identifier' => $block->getFalconIdentifier(),
            'states' => $block->getFalconStates()];
    }

    private static function entities(array $ids) : array{
        $entities = [];
        foreach($ids as $id){
            $entity = Entities::byId($id);
            if($entity !== null){
                $entities[] = $entity;
            }
        }
        return $entities;
    }

    private static function transaction(World $world, array $values) : BlockTransaction{
        $transaction = new BlockTransaction($world);
        foreach($values as [$x, $y, $z, $state]){
            $transaction->addBlockAt($x, $y, $z, self::decodeBlock($state));
        }
        return $transaction;
    }

    private static function encodeTransaction(BlockTransaction $transaction) : array{
        $blocks = [];
        foreach($transaction->getBlocks() as [$x, $y, $z, $block]){
            $blocks[] = [$x, $y, $z, self::encodeBlock($block)];
        }
        return $blocks;
    }
}
