<?php

declare(strict_types=1);

namespace pmmpcompat;

use pocketmine\block\Block;
use pocketmine\block\inventory\EnchantInventory;
use pocketmine\data\bedrock\EnchantmentIdMap;
use pocketmine\crafting\CraftingGrid;
use pocketmine\crafting\ExactRecipeIngredient;
use pocketmine\crafting\ShapedRecipe;
use pocketmine\crafting\ShapelessRecipe;
use pocketmine\crafting\ShapelessRecipeType;
use pocketmine\entity\object\ItemEntity;
use pocketmine\entity\Skin;
use pocketmine\entity\Living;
use pocketmine\entity\projectile\Projectile;
use pocketmine\event\entity\EntityCombustByBlockEvent;
use pocketmine\event\entity\EntityCombustByEntityEvent;
use pocketmine\event\entity\EntityPreExplodeEvent;
use pocketmine\event\entity\EntityShootBowEvent;
use pocketmine\event\entity\ItemDespawnEvent;
use pocketmine\event\entity\ItemMergeEvent;
use pocketmine\event\inventory\CraftItemEvent;
use pocketmine\event\player\PlayerBlockPickEvent;
use pocketmine\event\player\PlayerChangeSkinEvent;
use pocketmine\event\player\PlayerCreationEvent;
use pocketmine\event\player\PlayerDataSaveEvent;
use pocketmine\event\player\PlayerDuplicateLoginEvent;
use pocketmine\event\player\PlayerEnchantingOptionsRequestEvent;
use pocketmine\event\player\PlayerEntityPickEvent;
use pocketmine\event\player\PlayerItemEnchantEvent;
use pocketmine\event\player\PlayerEditBookEvent;
use pocketmine\event\player\PlayerMissSwingEvent;
use pocketmine\event\player\PlayerPostChunkSendEvent;
use pocketmine\event\player\PlayerPreLoginEvent;
use pocketmine\event\player\PlayerResourcePackOfferEvent;
use pocketmine\event\player\PlayerRespawnAnchorUseEvent;
use pocketmine\event\player\PlayerToggleGlideEvent;
use pocketmine\event\player\PlayerToggleSwimEvent;
use pocketmine\event\player\PlayerViewDistanceChangeEvent;
use pocketmine\inventory\transaction\action\CreateItemAction;
use pocketmine\inventory\transaction\action\SlotChangeAction;
use pocketmine\inventory\transaction\CraftingTransaction;
use pocketmine\inventory\transaction\EnchantingTransaction;
use pocketmine\item\enchantment\EnchantmentInstance;
use pocketmine\item\enchantment\EnchantingOption;
use pocketmine\item\Item;
use pocketmine\item\WritableBookBase;
use pocketmine\nbt\LittleEndianNbtSerializer;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\TreeRoot;
use pocketmine\network\mcpe\NetworkSession;
use pocketmine\player\Player;
use pocketmine\player\PlayerInfo;
use pocketmine\player\XboxLivePlayerInfo;
use pocketmine\lang\Translatable;
use pocketmine\resourcepacks\ResourcePack;
use pocketmine\resourcepacks\ResourcePackException;
use pocketmine\Server;
use Ramsey\Uuid\Uuid;

final class PlayerCompatibilityEvents{
    private const CRAFT_ITEM = 20;
    private const ITEM_ENCHANT = 62;
    private const PLAYER_BLOCK_PICK = 116;
    private const PLAYER_CHANGE_SKIN = 117;
    public const PLAYER_CREATION = 118;
    private const PLAYER_DATA_SAVE = 119;
    public const PLAYER_DUPLICATE_LOGIN = 120;
    private const PLAYER_EDIT_BOOK = 121;
    private const PLAYER_ENCHANTING_OPTIONS = 122;
    private const PLAYER_ENTITY_PICK = 123;
    private const PLAYER_MISS_SWING = 124;
    private const PLAYER_POST_CHUNK_SEND = 125;
    public const PLAYER_RESOURCE_PACK_OFFER = 126;
    private const PLAYER_RESPAWN_ANCHOR_USE = 127;
    private const PLAYER_TOGGLE_GLIDE = 128;
    private const PLAYER_TOGGLE_SWIM = 129;
    private const PLAYER_VIEW_DISTANCE_CHANGE = 130;
    private const ENTITY_COMBUST_BY_BLOCK = 131;
    private const ENTITY_COMBUST_BY_ENTITY = 132;
    private const ENTITY_PRE_EXPLODE = 133;
    private const ENTITY_SHOOT_BOW = 134;
    private const ITEM_DESPAWN = 135;
    private const ITEM_MERGE = 136;
    private const PLAYER_LOGIN_ATTEMPT = 137;

    public const SOURCES = [
        self::CRAFT_ITEM => [CraftItemEvent::class],
        self::ITEM_ENCHANT => [PlayerItemEnchantEvent::class],
        self::PLAYER_BLOCK_PICK => [PlayerBlockPickEvent::class],
        self::PLAYER_CHANGE_SKIN => [PlayerChangeSkinEvent::class],
        self::PLAYER_CREATION => [PlayerCreationEvent::class],
        self::PLAYER_DATA_SAVE => [PlayerDataSaveEvent::class],
        self::PLAYER_DUPLICATE_LOGIN => [PlayerDuplicateLoginEvent::class],
        self::PLAYER_LOGIN_ATTEMPT => [PlayerPreLoginEvent::class],
        self::PLAYER_ENTITY_PICK => [PlayerEntityPickEvent::class],
        self::PLAYER_EDIT_BOOK => [PlayerEditBookEvent::class],
        self::PLAYER_ENCHANTING_OPTIONS => [PlayerEnchantingOptionsRequestEvent::class],
        self::PLAYER_MISS_SWING => [PlayerMissSwingEvent::class],
        self::PLAYER_POST_CHUNK_SEND => [PlayerPostChunkSendEvent::class],
        self::PLAYER_RESOURCE_PACK_OFFER => [PlayerResourcePackOfferEvent::class],
        self::PLAYER_RESPAWN_ANCHOR_USE => [PlayerRespawnAnchorUseEvent::class],
        self::PLAYER_TOGGLE_GLIDE => [PlayerToggleGlideEvent::class],
        self::PLAYER_TOGGLE_SWIM => [PlayerToggleSwimEvent::class],
        self::PLAYER_VIEW_DISTANCE_CHANGE => [PlayerViewDistanceChangeEvent::class],
        self::ENTITY_COMBUST_BY_BLOCK => [EntityCombustByBlockEvent::class],
        self::ENTITY_COMBUST_BY_ENTITY => [EntityCombustByEntityEvent::class],
        self::ENTITY_PRE_EXPLODE => [EntityPreExplodeEvent::class],
        self::ENTITY_SHOOT_BOW => [EntityShootBowEvent::class],
        self::ITEM_DESPAWN => [ItemDespawnEvent::class],
        self::ITEM_MERGE => [ItemMergeEvent::class],
    ];

    public static function create(int $type, int $handle, ?Player $player) : ?array{
        if($type === self::PLAYER_LOGIN_ATTEMPT){
            [$username, $identity, $xuid, $locale, $address, $skinId, $image] =
                array_map(base64_decode(...), json_decode(falcon_event_data($handle), true, flags: JSON_THROW_ON_ERROR));
            $uuid = Uuid::isValid($identity) ? Uuid::fromString($identity) : Uuid::uuid3(Uuid::NIL, $username);
            $skin = self::skinFromLogin($skinId, $image);
            [, $clientData] = falcon_event_packet($handle);
            $extra = json_decode($clientData, true);
            $info = $xuid === '' ? new PlayerInfo($username, $uuid, $skin, $locale, is_array($extra) ? $extra : [])
                : new XboxLivePlayerInfo($xuid, $username, $uuid, $skin, $locale, is_array($extra) ? $extra : []);
            return ['event' => new PlayerPreLoginEvent($info, $address, (int) falcon_event_previous_amount($handle),
                falcon_event_state($handle))];
        }
        if($type === self::PLAYER_RESOURCE_PACK_OFFER){
            $packs = [];
            $keys = [];
            foreach(falcon_event_resource_packs($handle) as [$id, $name, $version, $key, $sha256, $size, $data]){
                $packs[] = new OfferedResourcePack($id, $name, $version, $sha256, $size, $data);
                if($key !== ''){
                    $keys[$id] = $key;
                }
            }
            $required = falcon_event_state($handle);
            return ['event' => new PlayerResourcePackOfferEvent(self::playerInfo(falcon_event_player($handle)), $packs, $keys, $required),
                'offer' => [$packs, $keys, $required], 'nativePacks' => $packs];
        }
        if($type === self::PLAYER_DUPLICATE_LOGIN){
            $handleToPlayer = falcon_event_player($handle);
            $target = falcon_event_target($handle);
            $existing = $target === 0 ? null : Entities::get($target);
            if($handleToPlayer === 0 || !$existing instanceof Player){
                return null;
            }
            $session = new NetworkSession(connectingHandle: $handleToPlayer, playerInfo: self::playerInfo($handleToPlayer),
                remotePort: (int) falcon_event_amount($handle));
            return ['event' => new PlayerDuplicateLoginEvent($session, $existing->getNetworkSession(),
                falcon_event_message($handle), null)];
        }
        $entityHandle = falcon_event_entity($handle);
        $entity = $entityHandle === 0 ? null : Entities::get($entityHandle);
        if($type === self::ITEM_DESPAWN){
            return $entity instanceof ItemEntity ? ['event' => new ItemDespawnEvent($entity)] : null;
        }
        if($type === self::ITEM_MERGE){
            $targetHandle = falcon_event_target($handle);
            $target = $targetHandle === 0 ? null : Entities::get($targetHandle);
            return $entity instanceof ItemEntity && $target instanceof ItemEntity
                ? ['event' => new ItemMergeEvent($entity, $target)] : null;
        }
        if($type === self::ENTITY_COMBUST_BY_BLOCK || $type === self::ENTITY_COMBUST_BY_ENTITY){
            if($entity === null){
                return null;
            }
            $duration = (int) falcon_event_amount($handle);
            if($type === self::ENTITY_COMBUST_BY_BLOCK){
                $block = self::block($handle);
                return $block === null ? null : ['event' => new EntityCombustByBlockEvent($block, $entity, $duration)];
            }
            $source = falcon_event_attacker($handle);
            return $source === 0 ? null : ['event' => new EntityCombustByEntityEvent(Entities::get($source), $entity, $duration)];
        }
        if($type === self::ENTITY_SHOOT_BOW){
            $target = falcon_event_target($handle);
            $projectile = $target !== 0 ? Entities::get($target) : null;
            $bow = falcon_event_item($handle);
            return $entity instanceof Living && $projectile instanceof Projectile && $bow !== null
                ? ['event' => new EntityShootBowEvent($entity, Item::fromFalcon($bow), $projectile, falcon_event_amount($handle)),
                    'projectile' => $projectile]
                : null;
        }
        if($type === self::ENTITY_PRE_EXPLODE){
            $radius = falcon_event_amount($handle);
            if($entity === null || $radius <= 0){
                return null;
            }
            $event = new EntityPreExplodeEvent($entity, $radius, falcon_event_previous_amount($handle));
            $event->setBlockBreaking(falcon_event_state($handle));
            return ['event' => $event];
        }
        if($player === null){
            return null;
        }
        if($type === self::PLAYER_BLOCK_PICK || $type === self::PLAYER_ENTITY_PICK){
            $raw = falcon_event_result($handle);
            if($raw === null){
                return null;
            }
            $item = Item::fromFalcon($raw);
            if($type === self::PLAYER_BLOCK_PICK){
                $block = self::block($handle, $player);
                if($block === null){
                    return null;
                }
                $event = new PlayerBlockPickEvent($player, $block, $item);
            }else{
                if($entity === null){
                    return null;
                }
                $event = new PlayerEntityPickEvent($player, $entity, $item);
            }
            return ['event' => $event, 'item' => $item->toFalcon()];
        }
        if($type === self::PLAYER_ENCHANTING_OPTIONS){
            $block = self::block($handle, $player);
            $input = falcon_event_item($handle);
            $lapis = falcon_event_result($handle);
            if($block === null || $input === null || $lapis === null){
                return null;
            }
            $options = [];
            foreach(json_decode(falcon_event_data($handle), true, flags: JSON_THROW_ON_ERROR) as [$cost, $title, $raw]){
                $enchantments = [];
                foreach($raw as [$id, $level]){
                    $type = EnchantmentIdMap::getInstance()->fromId($id);
                    if($type !== null){
                        $enchantments[] = new EnchantmentInstance($type, $level);
                    }
                }
                $options[] = new EnchantingOption($cost, base64_decode($title), $enchantments);
            }
            $inventory = new EnchantInventory($block->getPosition(), $options);
            $inventory->setItem(EnchantInventory::SLOT_INPUT, Item::fromFalcon($input));
            $inventory->setItem(EnchantInventory::SLOT_LAPIS, Item::fromFalcon($lapis));
            return ['event' => new PlayerEnchantingOptionsRequestEvent($player, $inventory, $options), 'options' => $options];
        }
        if($type === self::CRAFT_ITEM || $type === self::ITEM_ENCHANT){
            $result = falcon_event_result($handle);
            if($result === null){
                return null;
            }
            $output = Item::fromFalcon($result);
            if($type === self::ITEM_ENCHANT){
                $raw = falcon_event_item($handle);
                if($raw === null){
                    return null;
                }
                $input = Item::fromFalcon($raw);
                $cost = (int) falcon_event_amount($handle);
                $option = new EnchantingOption((int) falcon_event_previous_amount($handle), '', $output->getEnchantments());
                $transaction = new EnchantingTransaction($player, $option, $cost);
                return ['event' => new PlayerItemEnchantEvent($player, $transaction, $option, $input, $output, $cost)];
            }
            $repetitions = max(1, (int) falcon_event_amount($handle));
            $inputs = array_map(Item::fromFalcon(...), falcon_event_drops($handle) ?? []);
            $recipeOutput = (clone $output)->setCount(intdiv($output->getCount(), $repetitions));
            [$gridWidth, $recipeWidth, $slots] = json_decode(falcon_event_data($handle), true, flags: JSON_THROW_ON_ERROR);
            $grid = new class($gridWidth) extends CraftingGrid{};
            $actions = [new CreateItemAction($output)];
            $ingredients = [];
            foreach($inputs as $index => $item){
                [$slot, $count] = $slots[$index];
                $before = (clone $item)->setCount($count);
                $grid->setItem($slot, $before);
                $actions[] = new SlotChangeAction($grid, $slot, $before, (clone $before)->setCount($count - $item->getCount()));
                $ingredient = new ExactRecipeIngredient((clone $item)->setCount(1));
                $ingredients[$slot] = $ingredient;
            }
            $manager = Server::getInstance()->getCraftingManager();
            $recipe = $manager->matchRecipe($grid, [$recipeOutput]);
            if($recipe === null && $recipeWidth > 0){
                $rows = [];
                $symbols = [];
                for($y = 0; $y < $grid->getRecipeHeight(); ++$y){
                    $row = '';
                    for($x = 0; $x < $grid->getRecipeWidth(); ++$x){
                        $item = $grid->getIngredient($x, $y);
                        if($item->isNull()){
                            $row .= ' ';
                        }else{
                            $symbol = chr(ord('A') + count($symbols));
                            $symbols[$symbol] = new ExactRecipeIngredient($item->setCount(1));
                            $row .= $symbol;
                        }
                    }
                    $rows[] = $row;
                }
                $recipe = new ShapedRecipe($rows, $symbols, [$recipeOutput]);
            }
            $recipe ??= new ShapelessRecipe(array_values($ingredients), [$recipeOutput], ShapelessRecipeType::CRAFTING);
            $transaction = new CraftingTransaction($player, $manager, $actions, $recipe, $repetitions);
            return ['event' => new CraftItemEvent($transaction, $recipe, $repetitions, $inputs, [$output])];
        }
        if($type === self::PLAYER_CHANGE_SKIN){
            [$previous, $proposed] = json_decode(falcon_event_data($handle), true, flags: JSON_THROW_ON_ERROR);
            $old = self::skin($previous, $player->getSkin());
            $new = self::skin($proposed, $old);
            return ['event' => new PlayerChangeSkinEvent($player, $old, $new), 'skin' => $new];
        }
        if($type === self::PLAYER_DATA_SAVE){
            [, $original] = falcon_event_packet($handle);
            $tag = (new LittleEndianNbtSerializer())->read($original)->getTag();
            return $tag instanceof CompoundTag
                ? ['event' => new PlayerDataSaveEvent($tag, $player->getName(), $player), 'original' => $original]
                : null;
        }
        if($type === self::PLAYER_EDIT_BOOK){
            $from = falcon_event_item($handle);
            $to = falcon_event_result($handle);
            if($from === null || $to === null){
                return null;
            }
            $old = Item::fromFalcon($from);
            $new = Item::fromFalcon($to);
            if(!$old instanceof WritableBookBase || !$new instanceof WritableBookBase){
                return null;
            }
            [$first, $second] = falcon_event_chunk($handle);
            $action = (int) falcon_event_amount($handle);
            $pages = match($action){
                PlayerEditBookEvent::ACTION_SWAP_PAGES => [$first, $second],
                PlayerEditBookEvent::ACTION_SIGN_BOOK => [],
                default => [$first],
            };
            return ['event' => new PlayerEditBookEvent($player, $old, $new, $action, $pages), 'item' => $new->toFalcon()];
        }
        $event = match($type){
            self::PLAYER_MISS_SWING => new PlayerMissSwingEvent($player),
            self::PLAYER_POST_CHUNK_SEND => new PlayerPostChunkSendEvent($player, ...falcon_event_chunk($handle)),
            self::PLAYER_RESPAWN_ANCHOR_USE => new PlayerRespawnAnchorUseEvent(
                $player, self::block($handle, $player), (int) falcon_event_amount($handle)
            ),
            self::PLAYER_TOGGLE_GLIDE => new PlayerToggleGlideEvent($player, falcon_event_state($handle)),
            self::PLAYER_TOGGLE_SWIM => new PlayerToggleSwimEvent($player, falcon_event_state($handle)),
            self::PLAYER_VIEW_DISTANCE_CHANGE => new PlayerViewDistanceChangeEvent(
                $player, (int) falcon_event_previous_amount($handle), (int) falcon_event_amount($handle)
            ),
            default => null,
        };
        return $event === null ? null : ['event' => $event];
    }

    public static function apply(int $handle, array &$state, bool $last) : void{
        $event = $state['event'];
        if($event instanceof PlayerPreLoginEvent){
            falcon_event_set_amount($handle, $event->isAuthRequired() ? 1 : 0);
            falcon_event_set_cancelled($handle, !$event->isAllowed());
            if(!$event->isAllowed()){
                falcon_event_set_message($handle, self::text($event->getFinalDisconnectScreenMessage()));
            }
        }elseif($event instanceof PlayerResourcePackOfferEvent){
            $offer = [$event->getResourcePacks(), $event->getEncryptionKeys(), $event->mustAccept()];
            if($offer !== $state['offer']){
                [$packs, $keys, $required] = $offer;
                $descriptors = [];
                foreach($packs as $pack){
                    $id = strtolower($pack->getPackId());
                    $descriptor = [$id, $keys[$pack->getPackId()] ?? $keys[$id] ?? ''];
                    if(!in_array($pack, $state['nativePacks'], true)){
                        $descriptor[] = self::packArchive($pack);
                    }
                    $descriptors[] = $descriptor;
                }
                if(!falcon_event_replace_resource_pack_offer($handle, $descriptors, $required)){
                    throw new ResourcePackException('Falcon rejected the resource packs proposed for this connection');
                }
                $state['offer'] = $offer;
                $state['nativePacks'] = $packs;
            }
        }elseif($event instanceof PlayerDuplicateLoginEvent){
            $reason = $event->getDisconnectScreenMessage();
            falcon_event_set_message($handle, self::text($reason ?? $event->getDisconnectReason()));
            if($last){
                $event->getConnectingSession()->finishConnection();
            }
        }elseif($event instanceof PlayerBlockPickEvent || $event instanceof PlayerEntityPickEvent || $event instanceof PlayerEditBookEvent){
            $item = ($event instanceof PlayerEditBookEvent ? $event->getNewBook() : $event->getResultItem())->toFalcon();
            if($item !== $state['item']){
                falcon_event_set_result($handle, $item);
                $state['item'] = $item;
            }
        }elseif($event instanceof PlayerEnchantingOptionsRequestEvent){
            $options = $event->getOptions();
            if($options !== $state['options']){
                $serialized = [];
                foreach($options as $option){
                    $enchantments = [];
                    foreach($option->getEnchantments() as $enchantment){
                        $enchantments[] = [
                            EnchantmentIdMap::getInstance()->toId($enchantment->getType()), $enchantment->getLevel(),
                        ];
                    }
                    $serialized[] = [$option->getRequiredXpLevel(), base64_encode($option->getDisplayName()), $enchantments];
                }
                falcon_event_set_data($handle, json_encode($serialized, JSON_THROW_ON_ERROR));
                $state['options'] = $options;
            }
        }elseif($event instanceof PlayerChangeSkinEvent){
            $skin = $event->getNewSkin();
            if($skin !== $state['skin']){
                falcon_event_set_data($handle, json_encode(array_map(base64_encode(...), [
                    $skin->getSkinId(), $skin->getSkinData(), $skin->getCapeData(), $skin->getGeometryName(), $skin->getGeometryData(),
                ]), JSON_THROW_ON_ERROR));
                $state['skin'] = $skin;
            }
        }elseif($event instanceof PlayerDataSaveEvent){
            $data = (new LittleEndianNbtSerializer())->write(new TreeRoot($event->getSaveData()));
            if($data !== $state['original']){
                falcon_event_set_packet($handle, $data);
                $state['original'] = $data;
            }
        }elseif($event instanceof PlayerRespawnAnchorUseEvent){
            falcon_event_set_amount($handle, $event->getAction());
        }elseif($event instanceof EntityCombustByBlockEvent || $event instanceof EntityCombustByEntityEvent){
            falcon_event_set_amount($handle, $event->getDuration());
        }elseif($event instanceof EntityShootBowEvent){
            $projectile = $event->getProjectile();
            falcon_event_set_amount($handle, $event->getForce());
            if($projectile !== $state['projectile']){
                falcon_event_set_data($handle, (string) $projectile->getId());
                if($last && $projectile->isDrawnByPlugin()){
                    $force = $event->getForce();
                    if($event->isCancelled() || !is_finite($force) || $force < 0){
                        $projectile->close();
                    }else{
                        $projectile->setMotion($projectile->getMotion()->multiply($force));
                        if(!$projectile instanceof Projectile){
                            $projectile->spawnToAll();
                        }
                    }
                }
            }
        }elseif($event instanceof EntityPreExplodeEvent){
            falcon_event_set_amount($handle, $event->getRadius());
            falcon_event_set_data($handle, json_encode([
                'fireChance' => $event->getFireChance(),
                'breakBlocks' => $event->isBlockBreaking(),
            ], JSON_THROW_ON_ERROR));
        }
    }

    /** @internal Identity is available before a Player object is created. */
    public static function playerInfo(int $handle) : PlayerInfo{
        $name = falcon_player_name($handle);
        $identity = falcon_player_uuid($handle);
        $uuid = Uuid::isValid($identity) ? Uuid::fromString($identity) : Uuid::uuid3(Uuid::NIL, $name);
        [$skinId, , , $skinData] = falcon_player_skin($handle) ?? ['', 0, 0, ''];
        $skin = self::skinFromLogin($skinId, $skinData);
        $locale = falcon_player_language($handle);
        $xuid = falcon_player_xuid($handle);
        $extraData = ['DeviceOS' => falcon_player_device_os($handle)];
        return $xuid === '' ? new PlayerInfo($name, $uuid, $skin, $locale, $extraData)
            : new XboxLivePlayerInfo($xuid, $name, $uuid, $skin, $locale, $extraData);
    }

    private static function packArchive(ResourcePack $pack) : string{
        $size = $pack->getPackSize();
        if($size <= 0 || $size > 128 * 1024 * 1024){
            throw new ResourcePackException('Resource pack exceeds the offer size limit');
        }
        $data = '';
        while(strlen($data) < $size){
            $limit = min(1024 * 1024, $size - strlen($data));
            $chunk = $pack->getPackChunk(strlen($data), $limit);
            if($chunk === '' || strlen($chunk) > $limit){
                throw new ResourcePackException('Invalid chunk in resource pack ' . $pack->getPackName());
            }
            $data .= $chunk;
        }
        return $data;
    }

    private static function text(Translatable|string $text) : string{
        return $text instanceof Translatable ? Server::getInstance()->getLanguage()->translate($text) : $text;
    }

    private static function skinFromLogin(string $id, string $appearance) : Skin{
        if(!in_array(strlen($appearance), Skin::ACCEPTED_SKIN_SIZES, true)){
            $appearance = str_repeat("\x00", 64 * 64 * 4);
        }
        return new Skin($id !== '' && strlen($id) <= 32767 ? $id : 'Standard_Custom', $appearance);
    }

    private static function skin(array $raw, Skin $fallback) : Skin{
        [$id, $image, $cape, $geometryName, $geometry] = array_map(base64_decode(...), $raw);
        if(!in_array(strlen($image), Skin::ACCEPTED_SKIN_SIZES, true)){
            $image = $fallback->getSkinData();
        }
        return new Skin($id !== '' ? $id : $fallback->getSkinId(), $image,
            strlen($cape) === 8192 ? $cape : '', $geometryName, $geometry);
    }

    private static function block(int $handle, ?Player $player = null) : ?Block{
        $world = Server::getInstance()->getWorldManager()->forLevel(falcon_event_level($handle)) ?? $player?->getWorld();
        if($world === null){
            return null;
        }
        [$x, $y, $z] = falcon_event_block($handle);
        return $world->getBlockAt($x, $y, $z);
    }
}
