# PocketMine-MP API usage of the most downloaded Poggit plugins

## Reproduce this survey

The snapshot uses revision [102194b](https://github.com/poggit/release-archive/tree/102194b0b1022180fc774318f39cd2a10ee4bb27)
of the Poggit release archive. With Python 3 and PHP CLI with Phar and zlib:

```sh
git clone https://github.com/poggit/release-archive.git
git -C release-archive checkout 102194b0b1022180fc774318f39cd2a10ee4bb27
python3 scripts/survey/run.py release-archive survey-results
```

Use a newer archive checkout to refresh the ranking. The script writes `ranking.json`,
`analysis.json` and `REPORT.md` to a new directory. It ranks repository/project pairs by
the download counts in the archived release metadata and extracts PHP and YAML files
from the newest release whose declared API range has an API 5 endpoint. It records
extraction failures in the ranking and includes whole-file gzip PHARs such as BlockPets.

Source: the Poggit release archive (github.com/poggit/release-archive), 200 most downloaded projects with an API 5 release, latest API 5 version each; 200 with detected PocketMine-MP class references. Bundled virions are included.

Static reading of the PHP, no code run: method calls are attributed only where the receiver's type is visible (a typed parameter, `instanceof`, or a known chain such as `$this->getServer()`), so method counts are a floor.

## How much of the API reaches how many plugins

Classes ranked by the number of plugins that use them; a plugin counts once every `pocketmine\` class it names is covered.

| Classes covered | Plugins fully covered |
|---|---|
| 25 | 7 of 200 (3%) |
| 50 | 18 of 200 (9%) |
| 75 | 28 of 200 (14%) |
| 100 | 31 of 200 (15%) |
| 150 | 54 of 200 (27%) |
| 200 | 72 of 200 (36%) |
| 300 | 94 of 200 (47%) |
| 400 | 121 of 200 (60%) |
| 500 | 137 of 200 (68%) |
| 693 | 200 of 200 (100%) |

109 of 200 plugins name no `pocketmine\network\` class.

## Where the classes come from

Standalone PocketMine libraries are plain PHP under LGPL and can be bundled as they are; the rest is the server API to implement.

| Source | Classes | Uses |
|---|---|---|
| server API | 484 | 4817 |
| pmmp/BedrockProtocol | 133 | 962 |
| server data | 32 | 90 |
| pmmp/NBT | 23 | 193 |
| pmmp/Math | 7 | 128 |
| server (threads) | 6 | 58 |
| pmmp/BinaryUtils | 3 | 12 |
| pmmp/Snooze | 3 | 48 |
| pmmp/Color | 1 | 5 |
| pmmp/ErrorHandler | 1 | 16 |

With those libraries bundled, server API classes to write against plugins fully covered:

| Server classes | Plugins |
|---|---|
| 25 | 10 of 200 (5%) |
| 50 | 27 of 200 (13%) |
| 100 | 63 of 200 (31%) |
| 150 | 90 of 200 (45%) |
| 200 | 104 of 200 (52%) |
| 300 | 143 of 200 (71%) |
| 484 | 200 of 200 (100%) |

## Features that need more than the API

| Feature | Plugins |
|---|---|
| raw network / packets | 91 |
| reflection / closure binding | 63 |
| forms | 63 |
| AsyncTask | 60 |
| http requests | 53 |
| database | 39 |
| threads (pmmpthread) | 33 |
| Commando virion | 27 |
| InvMenu virion | 19 |

## Classes

| Plugins | Class |
|---|---|
| 200 | `pocketmine\plugin\PluginBase` |
| 174 | `pocketmine\player\Player` |
| 172 | `pocketmine\event\Listener` |
| 149 | `pocketmine\command\CommandSender` |
| 147 | `pocketmine\command\Command` |
| 122 | `pocketmine\Server` |
| 118 | `pocketmine\utils\TextFormat` |
| 111 | `pocketmine\utils\Config` |
| 91 | `pocketmine\plugin\PluginOwned` |
| 91 | `pocketmine\plugin\Plugin` |
| 79 | `pocketmine\math\Vector3` |
| 70 | `pocketmine\world\World` |
| 70 | `pocketmine\event\player\PlayerQuitEvent` |
| 65 | `pocketmine\entity\Entity` |
| 64 | `pocketmine\item\Item` |
| 61 | `pocketmine\form\Form` |
| 61 | `pocketmine\event\player\PlayerJoinEvent` |
| 60 | `pocketmine\scheduler\AsyncTask` |
| 59 | `pocketmine\block\Block` |
| 56 | `pocketmine\world\Position` |
| 54 | `pocketmine\utils\Utils` |
| 52 | `pocketmine\event\EventPriority` |
| 50 | `pocketmine\event\server\DataPacketReceiveEvent` |
| 49 | `pocketmine\nbt\tag\CompoundTag` |
| 48 | `pocketmine\scheduler\ClosureTask` |
| 48 | `pocketmine\block\VanillaBlocks` |
| 46 | `pocketmine\utils\SingletonTrait` |
| 46 | `pocketmine\scheduler\Task` |
| 46 | `pocketmine\item\StringToItemParser` |
| 45 | `pocketmine\network\mcpe\protocol\ClientboundPacket` |
| 45 | `pocketmine\network\mcpe\NetworkSession` |
| 43 | `pocketmine\utils\Internet` |
| 39 | `pocketmine\item\VanillaItems` |
| 39 | `pocketmine\form\FormValidationException` |
| 39 | `pocketmine\event\entity\EntityDamageByEntityEvent` |
| 39 | `pocketmine\event\block\BlockBreakEvent` |
| 38 | `pocketmine\event\server\DataPacketSendEvent` |
| 36 | `pocketmine\network\mcpe\NetworkBroadcastUtils` |
| 36 | `pocketmine\event\player\PlayerInteractEvent` |
| 36 | `pocketmine\event\entity\EntityDamageEvent` |
| 35 | `pocketmine\lang\Translatable` |
| 34 | `pocketmine\network\mcpe\protocol\types\entity\EntityIds` |
| 33 | `pocketmine\network\mcpe\protocol\AvailableCommandsPacket` |
| 33 | `pocketmine\network\mcpe\convert\TypeConverter` |
| 33 | `pocketmine\event\HandlerListManager` |
| 32 | `pocketmine\permission\PermissionManager` |
| 32 | `pocketmine\math\Facing` |
| 32 | `pocketmine\event\player\PlayerLoginEvent` |
| 32 | `pocketmine\event\Event` |
| 31 | `pocketmine\network\mcpe\protocol\DataPacket` |
| 30 | `pocketmine\event\RegisteredListener` |
| 29 | `pocketmine\network\mcpe\protocol\types\command\CommandParameter` |
| 28 | `pocketmine\thread\Thread` |
| 28 | `pocketmine\network\mcpe\protocol\types\command\CommandOverload` |
| 28 | `pocketmine\network\mcpe\protocol\ServerboundPacket` |
| 28 | `pocketmine\network\mcpe\protocol\Packet` |
| 28 | `pocketmine\console\ConsoleCommandSender` |
| 27 | `pocketmine\network\mcpe\protocol\types\entity\PropertySyncData` |
| 27 | `pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties` |
| 27 | `pocketmine\network\mcpe\protocol\UpdateSoftEnumPacket` |
| 27 | `pocketmine\network\mcpe\protocol\AddActorPacket` |
| 27 | `pocketmine\event\player\PlayerChatEvent` |
| 26 | `pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags` |
| 26 | `pocketmine\event\inventory\InventoryTransactionEvent` |
| 26 | `pocketmine\event\block\BlockPlaceEvent` |
| 26 | `pocketmine\event\Cancellable` |
| 25 | `pocketmine\network\mcpe\protocol\types\entity\StringMetadataProperty` |
| 25 | `pocketmine\event\CancellableTrait` |
| 25 | `pocketmine\block\tile\Tile` |
| 24 | `pocketmine\utils\Terminal` |
| 24 | `pocketmine\snooze\SleeperHandlerEntry` |
| 24 | `pocketmine\network\mcpe\protocol\types\DeviceOS` |
| 24 | `pocketmine\network\mcpe\protocol\PlaySoundPacket` |
| 24 | `pocketmine\event\entity\EntityTeleportEvent` |
| 24 | `pocketmine\block\tile\Chest` |
| 23 | `pocketmine\snooze\SleeperNotifier` |
| 23 | `pocketmine\permission\DefaultPermissions` |
| 23 | `pocketmine\network\mcpe\protocol\types\CacheableNbt` |
| 23 | `pocketmine\network\mcpe\protocol\types\BlockPosition` |
| 23 | `pocketmine\network\mcpe\protocol\UpdateBlockPacket` |
| 23 | `pocketmine\network\mcpe\protocol\RemoveActorPacket` |
| 23 | `pocketmine\inventory\Inventory` |
| 23 | `pocketmine\\VERSION` |
| 22 | `pocketmine\utils\AssumptionFailedError` |
| 22 | `pocketmine\thread\log\AttachableThreadSafeLogger` |
| 22 | `pocketmine\nbt\tag\ListTag` |
| 21 | `pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection` |
| 21 | `pocketmine\network\mcpe\protocol\BlockActorDataPacket` |
| 21 | `pocketmine\inventory\transaction\action\SlotChangeAction` |
| 21 | `pocketmine\entity\Location` |
| 21 | `pocketmine\block\inventory\BlockInventory` |
| 20 | `pocketmine\network\mcpe\protocol\types\inventory\WindowTypes` |
| 20 | `pocketmine\network\mcpe\protocol\types\entity\MetadataProperty` |
| 20 | `pocketmine\network\mcpe\protocol\ContainerOpenPacket` |
| 20 | `pocketmine\inventory\SimpleInventory` |
| 20 | `pocketmine\event\inventory\InventoryCloseEvent` |
| 20 | `pocketmine\block\tile\Nameable` |
| 19 | `pocketmine\network\mcpe\protocol\types\command\CommandEnum` |
| 19 | `pocketmine\network\mcpe\protocol\NetworkStackLatencyPacket` |
| 19 | `pocketmine\nbt\TreeRoot` |
| 19 | `pocketmine\item\LegacyStringToItemParser` |
| 19 | `pocketmine\inventory\transaction\InventoryTransaction` |
| 19 | `pocketmine\inventory\InventoryListener` |
| 18 | `pocketmine\item\enchantment\EnchantmentInstance` |
| 18 | `pocketmine\event\server\CommandEvent` |
| 17 | `pocketmine\item\ItemTypeIds` |
| 17 | `pocketmine\event\player\PlayerDeathEvent` |
| 17 | `pocketmine\entity\EntityFactory` |
| 17 | `pocketmine\entity\EntityDataHelper` |
| 16 | `pocketmine\nbt\tag\StringTag` |
| 16 | `pocketmine\errorhandler\ErrorToExceptionHandler` |
| 15 | `pocketmine\world\format\io\GlobalItemDataHandlers` |
| 15 | `pocketmine\scheduler\TaskHandler` |
| 15 | `pocketmine\permission\Permission` |
| 15 | `pocketmine\block\BlockTypeIds` |
| 14 | `pocketmine\world\format\Chunk` |
| 14 | `pocketmine\nbt\BigEndianNbtSerializer` |
| 14 | `pocketmine\item\ItemIdentifier` |
| 14 | `pocketmine\event\player\PlayerMoveEvent` |
| 14 | `pocketmine\event\player\PlayerItemUseEvent` |
| 14 | `pocketmine\block\tile\Spawnable` |
| 13 | `pocketmine\plugin\ApiVersion` |
| 13 | `pocketmine\item\ItemBlock` |
| 13 | `pocketmine\event\player\PlayerEvent` |
| 12 | `pocketmine\network\mcpe\protocol\PacketPool` |
| 12 | `pocketmine\command` |
| 11 | `pocketmine\lang\KnownTranslationFactory` |
| 11 | `pocketmine\item\enchantment\VanillaEnchantments` |
| 11 | `pocketmine\item\enchantment\StringToEnchantmentParser` |
| 11 | `pocketmine\entity\Living` |
| 11 | `pocketmine\entity\EntitySizeInfo` |
| 10 | `pocketmine\plugin\PluginOwnedTrait` |
| 10 | `pocketmine\player\GameMode` |
| 10 | `pocketmine\network\mcpe\protocol\types\command\CommandHardEnum` |
| 10 | `pocketmine\network\mcpe\protocol\serializer\AvailableCommandsPacketDisassembler` |
| 10 | `pocketmine\network\mcpe\protocol\serializer\AvailableCommandsPacketAssembler` |
| 10 | `pocketmine\nbt\tag\IntTag` |
| 10 | `pocketmine\event\player\PlayerDropItemEvent` |
| 10 | `pocketmine\entity\effect\EffectInstance` |
| 10 | `pocketmine\entity\Human` |
| 10 | `pocketmine\data\bedrock\item\SavedItemData` |
| 10 | `pocketmine\data\bedrock\EnchantmentIdMap` |
| 9 | `pocketmine\plugin\DisablePluginException` |
| 9 | `pocketmine\nbt\NBT` |
| 9 | `pocketmine\item\enchantment\Enchantment` |
| 9 | `pocketmine\item\Armor` |
| 9 | `pocketmine\event\world\WorldLoadEvent` |
| 9 | `pocketmine\command\utils\InvalidCommandSyntaxException` |
| 9 | `pocketmine\block\RuntimeBlockStateRegistry` |
| 8 | `pocketmine\utils\Filesystem` |

## Events listened to

| Plugins | Event |
|---|---|
| 60 | `pocketmine\event\player\PlayerJoinEvent` |
| 51 | `pocketmine\event\player\PlayerQuitEvent` |
| 35 | `pocketmine\event\block\BlockBreakEvent` |
| 32 | `pocketmine\event\player\PlayerInteractEvent` |
| 31 | `pocketmine\event\entity\EntityDamageEvent` |
| 27 | `pocketmine\event\server\DataPacketReceiveEvent` |
| 26 | `pocketmine\event\inventory\InventoryTransactionEvent` |
| 24 | `pocketmine\event\entity\EntityTeleportEvent` |
| 23 | `pocketmine\event\player\PlayerChatEvent` |
| 23 | `pocketmine\event\block\BlockPlaceEvent` |
| 20 | `pocketmine\event\inventory\InventoryCloseEvent` |
| 18 | `pocketmine\event\server\CommandEvent` |
| 17 | `pocketmine\event\player\PlayerDeathEvent` |
| 17 | `pocketmine\event\entity\EntityDamageByEntityEvent` |
| 13 | `pocketmine\event\player\PlayerItemUseEvent` |
| 12 | `pocketmine\event\player\PlayerMoveEvent` |
| 12 | `pocketmine\event\player\PlayerLoginEvent` |
| 11 | `pocketmine\event\server\DataPacketSendEvent` |
| 9 | `pocketmine\event\player\PlayerDropItemEvent` |
| 7 | `pocketmine\event\world\WorldLoadEvent` |
| 7 | `pocketmine\event\entity\EntityItemPickupEvent` |
| 6 | `pocketmine\event\player\PlayerExhaustEvent` |
| 6 | `pocketmine\event\block\SignChangeEvent` |
| 5 | `pocketmine\event\world\ChunkLoadEvent` |
| 5 | `pocketmine\event\entity\EntitySpawnEvent` |
| 4 | `pocketmine\event\player\PlayerRespawnEvent` |
| 4 | `pocketmine\event\player\PlayerPreLoginEvent` |
| 4 | `pocketmine\event\player\PlayerJumpEvent` |
| 4 | `pocketmine\event\player\PlayerItemHeldEvent` |
| 4 | `pocketmine\event\player\PlayerItemConsumeEvent` |
| 4 | `pocketmine\event\entity\EntityShootBowEvent` |
| 4 | `pocketmine\event\entity\EntityRegainHealthEvent` |
| 4 | `pocketmine\event\entity\EntityPreExplodeEvent` |
| 3 | `pocketmine\event\world\WorldUnloadEvent` |
| 3 | `pocketmine\event\world\ChunkUnloadEvent` |
| 3 | `pocketmine\event\player\PlayerBedEnterEvent` |
| 3 | `pocketmine\event\entity\EntityMotionEvent` |
| 3 | `pocketmine\event\entity\EntityExplodeEvent` |
| 3 | `pocketmine\event\Event` |
| 2 | `pocketmine\event\player\PlayerExperienceChangeEvent` |
| 2 | `pocketmine\event\player\PlayerCreationEvent` |
| 2 | `pocketmine\event\inventory\CraftItemEvent` |
| 2 | `pocketmine\event\entity\ProjectileLaunchEvent` |
| 2 | `pocketmine\event\entity\EntityDespawnEvent` |
| 2 | `pocketmine\event\entity\EntityDeathEvent` |
| 2 | `pocketmine\event\entity\EntityDamageByChildEntityEvent` |
| 2 | `pocketmine\event\block\LeavesDecayEvent` |
| 2 | `pocketmine\event\block\BlockUpdateEvent` |
| 2 | `pocketmine\event\block\BlockSpreadEvent` |
| 2 | `pocketmine\event\block\BlockFormEvent` |
| 1 | `pocketmine\event\world\WorldSoundEvent` |
| 1 | `pocketmine\event\world\WorldSaveEvent` |
| 1 | `pocketmine\event\server\QueryRegenerateEvent` |
| 1 | `pocketmine\event\plugin\PluginDisableEvent` |
| 1 | `pocketmine\event\player\PlayerToggleSneakEvent` |
| 1 | `pocketmine\event\player\PlayerToggleGlideEvent` |
| 1 | `pocketmine\event\player\PlayerPostChunkSendEvent` |
| 1 | `pocketmine\event\player\PlayerKickEvent` |
| 1 | `pocketmine\event\player\PlayerItemEnchantEvent` |
| 1 | `pocketmine\event\player\PlayerEntityInteractEvent` |

## Classes extended or implemented

| Plugins | Class |
|---|---|
| 199 | `pocketmine\plugin\PluginBase` |
| 172 | `pocketmine\event\Listener (implements)` |
| 92 | `pocketmine\command\Command` |
| 91 | `pocketmine\plugin\PluginOwned (implements)` |
| 61 | `pocketmine\form\Form (implements)` |
| 55 | `pocketmine\scheduler\AsyncTask` |
| 45 | `pocketmine\scheduler\Task` |
| 28 | `pocketmine\thread\Thread` |
| 28 | `pocketmine\event\Event` |
| 24 | `pocketmine\event\Cancellable (implements)` |
| 19 | `pocketmine\inventory\SimpleInventory` |
| 19 | `pocketmine\inventory\InventoryListener (implements)` |
| 19 | `pocketmine\block\inventory\BlockInventory (implements)` |
| 9 | `pocketmine\event\player\PlayerEvent` |
| 7 | `pocketmine\entity\Human` |
| 5 | `pocketmine\item\Item` |
| 5 | `pocketmine\event\plugin\PluginEvent` |
| 4 | `pocketmine\world\ChunkListener (implements)` |
| 4 | `pocketmine\entity\Living` |
| 4 | `pocketmine\entity\Entity` |
| 3 | `pocketmine\world\sound\Sound (implements)` |
| 3 | `pocketmine\world\generator\Generator` |
| 3 | `pocketmine\world\ChunkLoader (implements)` |
| 3 | `pocketmine\utils\BinaryStream` |
| 2 | `pocketmine\utils\Config` |
| 2 | `pocketmine\item\Durable` |
| 2 | `pocketmine\item\Armor` |
| 2 | `pocketmine\event\entity\EntityEvent` |
| 2 | `pocketmine\entity\projectile\Throwable` |
| 2 | `pocketmine\entity\projectile\Projectile` |
| 2 | `pocketmine\console\ConsoleCommandSender` |
| 2 | `pocketmine\command\CommandExecutor (implements)` |
| 2 | `pocketmine\block\tile\Tile` |
| 2 | `pocketmine\block\tile\Spawnable` |
| 2 | `pocketmine\block\Opaque` |
| 1 | `pocketmine\world\utils\SubChunkExplorer` |
| 1 | `pocketmine\world\particle\Particle (implements)` |
| 1 | `pocketmine\world\particle\FloatingTextParticle` |
| 1 | `pocketmine\world\generator\populator\Populator (implements)` |
| 1 | `pocketmine\world\Position` |

## Methods (where the type is known)

| Plugins | Method |
|---|---|
| 179 | `pocketmine\Server->getPluginManager` |
| 134 | `pocketmine\command\CommandSender->sendMessage` |
| 119 | `pocketmine\player\Player->getName` |
| 100 | `pocketmine\utils\Config->get` |
| 97 | `pocketmine\Server->getCommandMap` |
| 95 | `pocketmine\player\Player->sendMessage` |
| 80 | `pocketmine\player\Player->getWorld` |
| 66 | `pocketmine\player\Player->getPosition` |
| 63 | `pocketmine\command\CommandSender->hasPermission` |
| 61 | `pocketmine\player\Player->getNetworkSession` |
| 57 | `pocketmine\player\Player->sendForm` |
| 54 | `pocketmine\Server->getAsyncPool` |
| 50 | `pocketmine\event\player\PlayerQuitEvent->getPlayer` |
| 49 | `pocketmine\command\CommandSender->getName` |
| 47 | `pocketmine\Server->getOnlinePlayers` |
| 46 | `pocketmine\player\Player->hasPermission` |
| 45 | `pocketmine\command\Command->getName` |
| 43 | `pocketmine\player\Player->getInventory` |
| 42 | `AttachableLogger->error` |
| 40 | `pocketmine\scheduler\TaskScheduler->scheduleRepeatingTask` |
| 40 | `pocketmine\Server->getWorldManager` |
| 37 | `pocketmine\plugin\Plugin->getName` |
| 37 | `pocketmine\player\Player->getId` |
| 37 | `pocketmine\command\CommandSender->getPosition` |
| 37 | `pocketmine\Server->getLogger` |
| 35 | `pocketmine\event\server\DataPacketSendEvent->getPackets` |
| 34 | `AttachableLogger->notice` |
| 32 | `pocketmine\plugin\PluginBase->getDataFolder` |
| 32 | `pocketmine\event\server\DataPacketSendEvent->getTargets` |
| 32 | `pocketmine\Server->getPlayerExact` |
| 29 | `pocketmine\network\mcpe\NetworkSession->getPlayer` |
| 29 | `pocketmine\command\CommandSender->getServer` |
| 28 | `pocketmine\plugin\Plugin->getLogger` |
| 28 | `pocketmine\event\server\DataPacketSendEvent->getPacket` |
| 28 | `pocketmine\event\server\DataPacketSendEvent->getOrigin` |
| 28 | `pocketmine\event\server\DataPacketSendEvent->cancel` |
| 27 | `pocketmine\plugin\PluginBase->getServer` |
| 26 | `pocketmine\network\mcpe\NetworkSession->sendDataPacket` |
| 25 | `AttachableLogger->info` |
| 24 | `pocketmine\plugin\PluginBase->getName` |
| 24 | `pocketmine\player\Player->getPlayerInfo` |
| 24 | `pocketmine\Server->getTickSleeper` |
| 24 | `pocketmine\Server->getLoader` |
| 23 | `pocketmine\plugin\PluginBase->getResource` |
| 23 | `pocketmine\player\Player->getUniqueId` |
| 23 | `pocketmine\player\Player->getLocation` |
| 23 | `AttachableLogger->critical` |
| 22 | `pocketmine\inventory\Inventory->getContents` |
| 22 | `pocketmine\command\CommandSender->sendForm` |
| 21 | `pocketmine\math\Vector3->getSide` |
| 21 | `pocketmine\inventory\transaction\action\SlotChangeAction->getSourceItem` |
| 21 | `pocketmine\inventory\Inventory->getSize` |
| 21 | `pocketmine\event\inventory\InventoryTransactionEvent->getTransaction` |
| 21 | `pocketmine\event\inventory\InventoryTransactionEvent->getPlayer` |
| 21 | `pocketmine\event\inventory\InventoryTransactionEvent->cancel` |
| 21 | `pocketmine\command\CommandSender->getInventory` |
| 21 | `AttachableLogger->warning` |
| 20 | `pocketmine\world\World->getFolderName` |
| 20 | `pocketmine\utils\Config->getNested` |
| 20 | `pocketmine\utils\Config->getAll` |
| 20 | `pocketmine\player\Player->getDirectionVector` |
| 20 | `pocketmine\inventory\transaction\action\SlotChangeAction->getTargetItem` |
| 20 | `pocketmine\inventory\transaction\action\SlotChangeAction->getInventory` |
| 20 | `pocketmine\inventory\Inventory->getItem` |
| 20 | `pocketmine\inventory\Inventory->getHolder` |
| 19 | `pocketmine\world\World->getTileAt` |
| 19 | `pocketmine\player\Player->teleport` |
| 19 | `pocketmine\player\Player->setCurrentWindow` |
| 19 | `pocketmine\player\Player->removeCurrentWindow` |
| 19 | `pocketmine\player\Player->getHealth` |
| 19 | `pocketmine\inventory\transaction\InventoryTransaction->discard` |
| 19 | `pocketmine\inventory\Inventory->getListeners` |
| 19 | `pocketmine\event\inventory\InventoryTransactionEvent->getPacket` |
| 19 | `pocketmine\event\inventory\InventoryTransactionEvent->getOrigin` |
| 19 | `pocketmine\event\inventory\InventoryTransactionEvent->getInventory` |
| 19 | `pocketmine\block\tile\Chest->getPair` |
| 17 | `pocketmine\player\Player->getXpManager` |
| 17 | `pocketmine\network\mcpe\protocol\types\command\CommandEnum->getValues` |
| 17 | `pocketmine\network\mcpe\protocol\types\command\CommandEnum->getName` |
| 17 | `pocketmine\block\Block->getStateId` |
| 17 | `pocketmine\Server->dispatchCommand` |
| 16 | `pocketmine\utils\Config->exists` |
| 16 | `pocketmine\player\Player->getMaxHealth` |
| 16 | `pocketmine\command\CommandSender->getWorld` |
| 16 | `pocketmine\Server->getLanguage` |
| 15 | `pocketmine\world\Position->getWorld` |
| 15 | `pocketmine\plugin\Plugin->getDescription` |
| 15 | `pocketmine\player\Player->getDisplayName` |
| 15 | `pocketmine\item\Item->getNamedTag` |
| 15 | `pocketmine\event\entity\EntityDamageByEntityEvent->getDamager` |
| 15 | `pocketmine\Server->getPlayerByPrefix` |
| 15 | `pocketmine\Server->getApiVersion` |
| 14 | `pocketmine\nbt\tag\CompoundTag->getString` |
| 14 | `pocketmine\item\Item->getCount` |
| 14 | `pocketmine\form\FormValidationException->getMessage` |
| 14 | `pocketmine\command\CommandSender->teleport` |
| 13 | `pocketmine\utils\Config->save` |
| 13 | `pocketmine\player\Player->getHungerManager` |
| 13 | `pocketmine\Server->broadcastMessage` |
| 13 | `AttachableLogger->debug` |
| 12 | `pocketmine\utils\Config->set` |
| 12 | `pocketmine\scheduler\TaskScheduler->scheduleDelayedTask` |
| 12 | `pocketmine\event\player\PlayerInteractEvent->getPlayer` |
| 12 | `pocketmine\block\tile\Spawnable->getSerializedSpawnCompound` |
| 11 | `pocketmine\world\World->getPlayers` |
| 11 | `pocketmine\player\Player->getXuid` |
| 11 | `pocketmine\nbt\tag\CompoundTag->getCompoundTag` |
| 11 | `pocketmine\item\Item->setCustomName` |
| 11 | `pocketmine\event\player\PlayerJoinEvent->getPlayer` |
| 11 | `pocketmine\event\player\PlayerInteractEvent->getBlock` |
| 11 | `pocketmine\block\Block->getPosition` |
| 10 | `pocketmine\utils\Config->getPath` |
| 10 | `pocketmine\player\Player->isSneaking` |
| 10 | `pocketmine\player\Player->isOnline` |
| 10 | `pocketmine\player\Player->isCreative` |
| 10 | `pocketmine\player\Player->getServer` |
| 10 | `pocketmine\nbt\tag\CompoundTag->setString` |
| 10 | `pocketmine\event\server\CommandEvent->getCommand` |
| 10 | `pocketmine\event\player\PlayerInteractEvent->cancel` |
| 10 | `pocketmine\event\entity\EntityDamageEvent->cancel` |
| 9 | `pocketmine\world\Position->getFloorZ` |
| 9 | `pocketmine\world\Position->getFloorX` |
| 9 | `pocketmine\player\Player->sendTip` |
| 9 | `pocketmine\nbt\tag\CompoundTag->setTag` |
| 9 | `pocketmine\item\Item->setCount` |
| 9 | `pocketmine\event\player\PlayerQuitEvent->cancel` |
| 9 | `pocketmine\event\player\PlayerDeathEvent->getPlayer` |
| 9 | `pocketmine\event\entity\EntityTeleportEvent->getEntity` |
| 9 | `pocketmine\event\block\BlockBreakEvent->getPlayer` |
| 8 | `pocketmine\world\World->getId` |
| 8 | `pocketmine\world\Position->getFloorY` |
| 8 | `pocketmine\utils\Config->reload` |
| 8 | `pocketmine\plugin\Plugin->getScheduler` |
| 8 | `pocketmine\player\Player->setAllowFlight` |
| 8 | `pocketmine\player\Player->sendTitle` |
| 8 | `pocketmine\player\Player->isAlive` |
| 8 | `pocketmine\player\Player->getEffects` |
| 8 | `pocketmine\network\mcpe\protocol\types\command\CommandHardEnum->getValues` |
| 8 | `pocketmine\network\mcpe\protocol\types\command\CommandHardEnum->getName` |
| 8 | `pocketmine\math\Vector3->getFloorZ` |
| 8 | `pocketmine\math\Vector3->getFloorY` |
| 8 | `pocketmine\math\Vector3->getFloorX` |
| 8 | `pocketmine\item\Item->setLore` |
| 8 | `pocketmine\item\Item->isNull` |
| 8 | `pocketmine\item\Item->getName` |
| 8 | `pocketmine\event\server\CommandEvent->getSender` |
| 8 | `pocketmine\event\entity\EntityTeleportEvent->getTo` |
| 8 | `pocketmine\event\entity\EntityDamageEvent->getEntity` |
| 8 | `pocketmine\event\entity\EntityDamageEvent->getDamager` |
| 8 | `pocketmine\command\CommandSender->getLocation` |

## Static calls and constants

| Plugins | Call |
|---|---|
| 118 | `pocketmine\Server::getInstance()` |
| 95 | `pocketmine\utils\TextFormat::RED` |
| 66 | `pocketmine\utils\Config::YAML` |
| 57 | `pocketmine\utils\TextFormat::GREEN` |
| 50 | `pocketmine\utils\TextFormat::GOLD` |
| 46 | `pocketmine\utils\TextFormat::YELLOW` |
| 46 | `pocketmine\event\EventPriority::MONITOR` |
| 45 | `pocketmine\item\StringToItemParser::getInstance()` |
| 40 | `pocketmine\utils\Internet::getURL()` |
| 38 | `pocketmine\utils\TextFormat::RESET` |
| 37 | `pocketmine\utils\TextFormat::colorize()` |
| 37 | `pocketmine\nbt\tag\CompoundTag::create()` |
| 35 | `pocketmine\network\mcpe\NetworkBroadcastUtils::broadcastPackets()` |
| 33 | `pocketmine\network\mcpe\convert\TypeConverter::getInstance()` |
| 33 | `pocketmine\event\HandlerListManager::global()` |
| 32 | `pocketmine\utils\TextFormat::GRAY` |
| 32 | `pocketmine\permission\PermissionManager::getInstance()` |
| 32 | `pocketmine\event\EventPriority::NORMAL` |
| 30 | `pocketmine\utils\TextFormat::AQUA` |
| 30 | `pocketmine\event\server\DataPacketReceiveEvent::class` |
| 29 | `pocketmine\network\mcpe\protocol\AvailableCommandsPacket::ARG_TYPE_STRING` |
| 29 | `pocketmine\network\mcpe\protocol\AvailableCommandsPacket::ARG_TYPE_RAWTEXT` |
| 29 | `pocketmine\math\Facing::NORTH` |
| 28 | `pocketmine\utils\TextFormat::BOLD` |
| 28 | `pocketmine\network\mcpe\protocol\ServerboundPacket::class` |
| 28 | `pocketmine\network\mcpe\protocol\DataPacket::class` |
| 28 | `pocketmine\network\mcpe\protocol\ClientboundPacket::class` |
| 28 | `pocketmine\network\mcpe\NetworkSession::class` |
| 28 | `pocketmine\math\Facing::WEST` |
| 28 | `pocketmine\math\Facing::EAST` |
| 28 | `pocketmine\event\server\DataPacketSendEvent::class` |
| 27 | `pocketmine\network\mcpe\protocol\UpdateSoftEnumPacket::TYPE_SET` |
| 27 | `pocketmine\network\mcpe\protocol\UpdateSoftEnumPacket::TYPE_REMOVE` |
| 27 | `pocketmine\network\mcpe\protocol\UpdateSoftEnumPacket::TYPE_ADD` |
| 27 | `pocketmine\network\mcpe\protocol\AvailableCommandsPacket::ARG_TYPE_POSITION` |
| 27 | `pocketmine\network\mcpe\protocol\AvailableCommandsPacket::ARG_TYPE_INT` |
| 27 | `pocketmine\network\mcpe\protocol\AvailableCommandsPacket::ARG_TYPE_FLOAT` |
| 27 | `pocketmine\math\Facing::SOUTH` |
| 26 | `pocketmine\utils\TextFormat::WHITE` |
| 26 | `pocketmine\network\mcpe\protocol\AddActorPacket::create()` |
| 26 | `pocketmine\entity\Entity::nextRuntimeId()` |
| 25 | `pocketmine\utils\TextFormat::BLUE` |
| 25 | `pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties::NAMETAG` |
| 25 | `pocketmine\event\player\PlayerQuitEvent::class` |
| 24 | `pocketmine\utils\Utils::getOS()` |
| 24 | `pocketmine\network\mcpe\protocol\AvailableCommandsPacket::ARG_FLAG_VALID` |
| 24 | `pocketmine\network\mcpe\protocol\AvailableCommandsPacket::ARG_FLAG_ENUM` |
| 23 | `pocketmine\utils\Utils::validateCallableSignature()` |
| 23 | `pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties::BOUNDING_BOX_WIDTH` |
| 23 | `pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties::BOUNDING_BOX_HEIGHT` |
| 23 | `pocketmine\network\mcpe\protocol\RemoveActorPacket::create()` |
| 22 | `pocketmine\network\mcpe\protocol\types\DeviceOS::PLAYSTATION` |
| 22 | `pocketmine\network\mcpe\protocol\AvailableCommandsPacket::ARG_TYPE_TARGET` |
| 22 | `pocketmine\block\VanillaBlocks::CHEST()` |
| 21 | `pocketmine\network\mcpe\protocol\types\BlockPosition::fromVector3()` |
| 21 | `pocketmine\network\mcpe\protocol\UpdateBlockPacket::create()` |
| 21 | `pocketmine\network\mcpe\protocol\UpdateBlockPacket::FLAG_NETWORK` |
| 21 | `pocketmine\event\player\PlayerLoginEvent::class` |
| 21 | `pocketmine\block\tile\Tile::TAG_ID` |
| 20 | `pocketmine\utils\TextFormat::DARK_RED` |
| 20 | `pocketmine\utils\TextFormat::DARK_GRAY` |
| 20 | `pocketmine\permission\DefaultPermissions::ROOT_OPERATOR` |
| 20 | `pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags::INVISIBLE` |
| 20 | `pocketmine\network\mcpe\protocol\UpdateBlockPacket::DATA_LAYER_NORMAL` |
| 20 | `pocketmine\network\mcpe\protocol\ContainerOpenPacket::blockInv()` |
| 20 | `pocketmine\network\mcpe\protocol\BlockActorDataPacket::create()` |
| 20 | `pocketmine\math\Facing::HORIZONTAL` |
| 20 | `pocketmine\block\tile\Nameable::TAG_CUSTOM_NAME` |
| 20 | `pocketmine\block\tile\Chest::TAG_PAIRZ` |
| 20 | `pocketmine\block\tile\Chest::TAG_PAIRX` |
| 20 | `pocketmine\block\VanillaBlocks::HOPPER()` |
| 19 | `pocketmine\utils\TextFormat::DARK_GREEN` |
| 19 | `pocketmine\network\mcpe\protocol\types\inventory\WindowTypes::HOPPER` |
| 19 | `pocketmine\network\mcpe\protocol\types\inventory\WindowTypes::CONTAINER` |
| 19 | `pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties::CONTAINER_TYPE` |
| 19 | `pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties::CONTAINER_BASE_SIZE` |
| 19 | `pocketmine\network\mcpe\protocol\types\entity\EntityIds::CHEST_MINECART` |
| 19 | `pocketmine\network\mcpe\protocol\NetworkStackLatencyPacket::create()` |
| 19 | `pocketmine\item\LegacyStringToItemParser::getInstance()` |
| 19 | `pocketmine\block\VanillaBlocks::BARRIER()` |

## Plugins reachable first

Plugins whose classes all sit highest in the ranking above.

| Classes needed | Plugin | Downloads | Network classes |
|---|---|---|---|
| 1 | CommandDisabler | 3380 | no |
| 5 | VanillaXP | 2528 | no |
| 5 | TransferMe | 1835 | no |
| 7 | Sudo | 19481 | no |
| 7 | playerstatus | 4225 | no |
| 10 | ChangeDisplayName | 3062 | no |
| 20 | PlayerCoords | 2001 | no |
| 27 | StarterKit | 43813 | no |
| 27 | RenameItems | 12618 | no |
| 28 | MOTDShuffle | 12632 | no |
| 33 | MineParty | 4466 | no |
| 33 | AntiXrayPE | 4014 | no |
| 35 | BetterHomes | 12386 | no |
| 35 | AdvancedRules | 2633 | no |
| 38 | XGTShowPlayerHP | 27031 | no |
| 38 | NoVoid | 1932 | no |
| 41 | AsyncWorldReset | 2462 | no |
| 47 | MaintenanceMode | 4803 | no |
| 53 | TimeCommander | 5690 | no |
| 53 | RemoteConsole | 2222 | no |
| 53 | Vouchers | 1839 | no |
| 58 | ChatCooldown | 5439 | no |
| 58 | TextReplacer | 1892 | no |
| 68 | BetterMute | 9370 | no |
| 71 | BlazinFly | 21647 | no |
| 71 | SimpleFly | 3663 | no |
| 71 | BetterFlight | 2390 | no |
| 72 | AutoAnnouncer | 2097 | yes |
| 82 | SimpleBroadcast | 4344 | no |
| 82 | AntiFallDamage | 2768 | no |
| 84 | SignIt | 6453 | no |
| 103 | EntityMonitor | 2091 | yes |
| 104 | AntiCombatLog | 3772 | no |
| 104 | SocialSpy | 3152 | no |
| 104 | IgnoreCase | 1853 | no |
| 108 | KeepInventory | 54316 | no |
| 108 | AdvancedBounty | 2094 | no |
| 116 | BlockLagFix | 6751 | yes |
| 116 | VBackpack | 1998 | yes |
| 116 | CustomPanels | 1834 | yes |
| 122 | Rewards | 12964 | no |
| 123 | KygekRanksUI | 18447 | no |
| 123 | KygekJoinUI | 11565 | no |
| 123 | KygekRulesUI | 6820 | no |
| 123 | KygekTagsShop | 2334 | no |
| 129 | RegionProtect | 2485 | no |
| 130 | AutoSmeltOre | 3496 | no |
| 131 | Nicknames | 7052 | no |
| 131 | AntiServerStop | 2922 | no |
| 137 | LobbyItems | 5050 | no |
| 141 | GamemodeUI | 6808 | yes |
| 142 | TitanCommandSpy | 3552 | no |
| 145 | AutoClearChunk | 10017 | no |
| 148 | PlayerVaults | 16078 | yes |
| 154 | AntiInstaBreak | 7512 | no |
| 154 | NightVision | 3437 | no |
| 155 | ChatGames | 2094 | no |
| 157 | RankShopGUI | 2594 | yes |
| 159 | ChestShop | 33624 | yes |
| 164 | RepairUI | 5845 | yes |

## The 30 most downloaded

| Downloads | Plugin | Classes needed | Network classes |
|---|---|---|---|
| 194169 | BuilderTools | 677 | no |
| 183411 | ScoreHud | 668 | yes |
| 143525 | Slapper | 647 | yes |
| 112561 | PocketMine-DevTools | 662 | no |
| 111547 | MultiWorld | 679 | yes |
| 99202 | SkyBlock | 648 | no |
| 81225 | VanishV2 | 560 | yes |
| 64557 | InvSee | 452 | yes |
| 64095 | ShopUI-FormAPI-Working | 348 | no |
| 60960 | EasyEdit | 674 | yes |
| 60228 | EasyCommandAutofill | 634 | yes |
| 60175 | AuctionHouse | 442 | yes |
| 54316 | KeepInventory | 108 | no |
| 52080 | MaxEnchants | 389 | no |
| 49907 | Worlds | 523 | no |
| 46502 | Texter | 635 | yes |
| 43813 | StarterKit | 27 | no |
| 33624 | ChestShop | 159 | yes |
| 32255 | BedrockEconomy | 652 | yes |
| 27031 | XGTShowPlayerHP | 38 | no |
| 26248 | HealthTag | 198 | no |
| 25554 | AdminLogin | 326 | no |
| 22371 | ServerTools | 661 | no |
| 21647 | BlazinFly | 71 | no |
| 21419 | RankSystem | 653 | yes |
| 20903 | NaviCompass | 620 | yes |
| 20437 | AllSigns | 327 | no |
| 20053 | Shield | 690 | yes |
| 20042 | BedcoreProtect | 572 | no |
| 20010 | theSpawn | 689 | no |
