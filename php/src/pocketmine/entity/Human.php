<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 *
 *
 */

declare(strict_types=1);

namespace pocketmine\entity;

use pocketmine\data\SavedDataLoadingException;
use pocketmine\inventory\CallbackInventoryListener;
use pocketmine\inventory\Inventory;
use pocketmine\inventory\PlayerEnderInventory;
use pocketmine\inventory\PlayerInventory;
use pocketmine\inventory\PlayerOffHandInventory;
use pocketmine\item\Item;
use pocketmine\nbt\tag\ByteArrayTag;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\network\mcpe\EntityEventBroadcaster;
use pocketmine\network\mcpe\NetworkBroadcastUtils;
use pocketmine\network\mcpe\protocol\AddPlayerPacket;
use pocketmine\network\mcpe\protocol\PlayerListPacket;
use pocketmine\network\mcpe\protocol\PlayerSkinPacket;
use pocketmine\network\mcpe\protocol\types\AbilitiesData;
use pocketmine\network\mcpe\protocol\types\AbilitiesLayer;
use pocketmine\network\mcpe\protocol\types\command\CommandPermissions;
use pocketmine\network\mcpe\protocol\types\DeviceOS;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\network\mcpe\protocol\types\entity\PropertySyncData;
use pocketmine\network\mcpe\protocol\types\entity\StringMetadataProperty;
use pocketmine\network\mcpe\protocol\types\GameMode;
use pocketmine\network\mcpe\protocol\types\inventory\ItemStackWrapper;
use pocketmine\network\mcpe\protocol\types\PlayerListEntry;
use pocketmine\network\mcpe\protocol\types\PlayerPermissions;
use pocketmine\network\mcpe\protocol\UpdateAbilitiesPacket;
use pocketmine\player\Player;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use function array_fill;
use function array_key_exists;
use function min;
use function str_repeat;
use function strlen;

/**
 * A player, or an entity shaped like one that a plugin made: an NPC with a skin, shown to clients as a player that
 * is not in the player list.
 */
class Human extends Living{
	private const TAG_INVENTORY = "Inventory"; //TAG_List<TAG_Compound>
	private const TAG_SELECTED_INVENTORY_SLOT = "SelectedInventorySlot"; //TAG_Int
	private const TAG_OFF_HAND_ITEM = "OffHandItem"; //TAG_Compound
	private const TAG_SKIN = "Skin"; //TAG_Compound
	private const TAG_SKIN_NAME = "Name"; //TAG_String
	private const TAG_SKIN_DATA = "Data"; //TAG_ByteArray
	private const TAG_SKIN_CAPE_DATA = "CapeData"; //TAG_ByteArray
	private const TAG_SKIN_GEOMETRY_NAME = "GeometryName"; //TAG_String
	private const TAG_SKIN_GEOMETRY_DATA = "GeometryData"; //TAG_ByteArray

	private const GEOMETRY = "geometry.humanoid.custom";

	/** the sizes a skin image comes in, width and height by byte length */
	private const SIZES = [
		64 * 32 * 4 => [64, 32],
		64 * 64 * 4 => [64, 64],
		128 * 64 * 4 => [128, 64],
		128 * 128 * 4 => [128, 128],
		256 * 128 * 4 => [256, 128],
		256 * 256 * 4 => [256, 256],
	];

	public static function getNetworkTypeId() : string{ return EntityIds::PLAYER; }

	protected PlayerInventory $inventory;
	protected PlayerOffHandInventory $offHandInventory;
	protected PlayerEnderInventory $enderInventory;

	protected ?UuidInterface $uuid = null;

	protected ?Skin $skin = null;

	protected ?HungerManager $hungerManager = null;
	protected ?ExperienceManager $xpManager = null;

	/**
	 * @param Location|int $location where a plugin's own human starts; a runtime id is a player's, which Player makes
	 */
	public function __construct(Location|int $location, ?Skin $skin = null, ?CompoundTag $nbt = null){
		$this->skin = $skin;
		parent::__construct($location, $nbt);
	}

	protected function getInitialSizeInfo() : EntitySizeInfo{ return new EntitySizeInfo(1.8, 0.6, 1.62); }

	/**
	 * @throws InvalidSkinException
	 * @throws SavedDataLoadingException
	 */
	public static function parseSkinNBT(CompoundTag $nbt) : Skin{
		$skinTag = $nbt->getCompoundTag(self::TAG_SKIN);
		if($skinTag === null){
			throw new SavedDataLoadingException("Missing skin data");
		}
		return new Skin( //this throws if the skin is invalid
			$skinTag->getString(self::TAG_SKIN_NAME),
			($skinDataTag = $skinTag->getTag(self::TAG_SKIN_DATA)) instanceof StringTag ? $skinDataTag->getValue() : $skinTag->getByteArray(self::TAG_SKIN_DATA), //old data (this used to be saved as a StringTag in older versions of PM)
			$skinTag->getByteArray(self::TAG_SKIN_CAPE_DATA, ""),
			$skinTag->getString(self::TAG_SKIN_GEOMETRY_NAME, ""),
			$skinTag->getByteArray(self::TAG_SKIN_GEOMETRY_DATA, "")
		);
	}

	public function getUniqueId() : UuidInterface{
		return $this->uuid ??= Uuid::uuid4();
	}

	/** the skin; a player's as Falcon has it, a 64x64 blank one when there is none */
	public function getSkin() : Skin{
		if($this instanceof Player && $this->isOnline()){
			$skin = falcon_player_skin($this->getHandle());
			if($skin !== null && isset(self::SIZES[strlen($skin[3])])){
				[$id, , , $rgba] = $skin;
				if($this->skin === null || $this->skin->getSkinData() !== $rgba || $this->skin->getSkinId() !== $id){
					$this->skin = new Skin($id === "" ? "Standard_Custom" : $id, $rgba, "", $this->skin?->getGeometryName() ?? self::GEOMETRY);
				}
			}
		}
		return $this->skin ??= new Skin("Standard_Custom", str_repeat("\x00", 64 * 64 * 4));
	}

	/**
	 * Sets the skin. For a player Falcon sends it to everyone; for a plugin's own human, sendSkin() does, as on
	 * PocketMine-MP.
	 */
	public function setSkin(Skin $skin) : void{
		$this->skin = $skin;
		if($this instanceof Player && $this->isOnline()){
			$data = $skin->getSkinData();
			[$width, $height] = self::SIZES[strlen($data)] ?? [64, 64];
			falcon_player_set_skin($this->getHandle(), $skin->getSkinId(), $data, $width, $height);
		}
	}

	/**
	 * @param Player[]|null $targets
	 */
	public function sendSkin(?array $targets = null) : void{
		if(!$this->isDrawnByPlugin()){
			return;
		}
		$packet = PlayerSkinPacket::create($this->getUniqueId(), "", "", \pocketmine\network\mcpe\convert\TypeConverter::getInstance()->getSkinAdapter()->toSkinData($this->getSkin()));
		$targets ??= $this->hasSpawned;
		if(count($targets) > 0){
			NetworkBroadcastUtils::broadcastPackets($targets, [$packet]);
		}
	}

	public function getName() : string{
		return $this->getNameTag();
	}

	public function getHungerManager() : HungerManager{
		return $this->hungerManager ??= new HungerManager($this);
	}

	public function getXpManager() : ExperienceManager{
		return $this->xpManager ??= new ExperienceManager($this);
	}

	public function canEat() : bool{
		return $this->getHungerManager()->isHungry();
	}

	public function getInventory() : PlayerInventory{
		return $this->inventory ??= new PlayerInventory($this);
	}

	public function getOffHandInventory() : PlayerOffHandInventory{
		return $this->offHandInventory ??= new PlayerOffHandInventory($this);
	}

	public function getEnderInventory() : PlayerEnderInventory{
		return $this->enderInventory ??= new PlayerEnderInventory($this);
	}

	protected function initEntity(CompoundTag $nbt) : void{
		parent::initEntity($nbt);

		$this->uuid = Uuid::uuid3(Uuid::NIL, ((string) $this->getId()) . $this->getSkin()->getSkinData() . $this->getNameTag());

		$this->inventory = new PlayerInventory($this);
		$syncHeldItem = fn() => NetworkBroadcastUtils::broadcastEntityEvent(
			$this->getViewers(),
			fn(EntityEventBroadcaster $broadcaster, array $recipients) => $broadcaster->onMobMainHandItemChange($recipients, $this)
		);
		$this->inventory->getListeners()->add(new CallbackInventoryListener(
			function(Inventory $unused, int $slot, Item $unused2) use ($syncHeldItem) : void{
				if($slot === $this->inventory->getHeldItemIndex()){
					$syncHeldItem();
				}
			},
			function(Inventory $unused, array $oldItems) use ($syncHeldItem) : void{
				if(array_key_exists($this->inventory->getHeldItemIndex(), $oldItems)){
					$syncHeldItem();
				}
			}
		));
		$this->offHandInventory = new PlayerOffHandInventory($this);
		$this->enderInventory = new PlayerEnderInventory($this);

		$inventoryTag = $nbt->getListTag(self::TAG_INVENTORY, CompoundTag::class);
		if($inventoryTag !== null){
			foreach($inventoryTag as $item){
				$slot = $item->getByte("Slot");
				try{
					if($slot >= 100 && $slot < 104){
						$this->armorInventory->setItem($slot - 100, Item::nbtDeserialize($item));
					}elseif($slot >= 9 && $slot < $this->inventory->getSize() + 9){
						$this->inventory->setItem($slot - 9, Item::nbtDeserialize($item));
					}
				}catch(\Throwable){
					//an item this server cannot read: left out, as PocketMine-MP leaves it
				}
			}
		}
		$offHand = $nbt->getCompoundTag(self::TAG_OFF_HAND_ITEM);
		if($offHand !== null){
			try{
				$this->offHandInventory->setItem(0, Item::nbtDeserialize($offHand));
			}catch(\Throwable){
			}
		}
		$this->offHandInventory->getListeners()->add(CallbackInventoryListener::onAnyChange(fn() => NetworkBroadcastUtils::broadcastEntityEvent(
			$this->getViewers(),
			fn(EntityEventBroadcaster $broadcaster, array $recipients) => $broadcaster->onMobOffHandItemChange($recipients, $this)
		)));

		$this->inventory->setHeldItemIndex(min(8, max(0, $nbt->getInt(self::TAG_SELECTED_INVENTORY_SLOT, 0))));
		$this->inventory->getHeldItemIndexChangeListeners()->add(fn() => NetworkBroadcastUtils::broadcastEntityEvent(
			$this->getViewers(),
			fn(EntityEventBroadcaster $broadcaster, array $recipients) => $broadcaster->onMobMainHandItemChange($recipients, $this)
		));
	}

	public function saveNBT() : CompoundTag{
		$nbt = parent::saveNBT();

		$inventoryTag = [];
		foreach($this->getInventory()->getContents() as $slot => $item){
			$inventoryTag[] = $item->nbtSerialize($slot + 9);
		}
		foreach($this->getArmorInventory()->getContents() as $slot => $item){
			$inventoryTag[] = $item->nbtSerialize($slot + 100);
		}
		$nbt->setTag(self::TAG_INVENTORY, new \pocketmine\nbt\tag\ListTag($inventoryTag, \pocketmine\nbt\NBT::TAG_Compound));
		$nbt->setInt(self::TAG_SELECTED_INVENTORY_SLOT, $this->getInventory()->getHeldItemIndex());

		$offHandItem = $this->getOffHandInventory()->getItem(0);
		if(!$offHandItem->isNull()){
			$nbt->setTag(self::TAG_OFF_HAND_ITEM, $offHandItem->nbtSerialize());
		}

		$skin = $this->getSkin();
		$nbt->setTag(self::TAG_SKIN, CompoundTag::create()
			->setString(self::TAG_SKIN_NAME, $skin->getSkinId())
			->setByteArray(self::TAG_SKIN_DATA, $skin->getSkinData())
			->setByteArray(self::TAG_SKIN_CAPE_DATA, $skin->getCapeData())
			->setString(self::TAG_SKIN_GEOMETRY_NAME, $skin->getGeometryName())
			->setByteArray(self::TAG_SKIN_GEOMETRY_DATA, $skin->getGeometryData())
		);

		return $nbt;
	}

	public function getXpDropAmount() : int{
		return $this->isDrawnByPlugin() ? 0 : min(100, 7 * $this->getXpManager()->getXpLevel());
	}

	public function getEnchantmentSeed() : int{
		return $this->getId();
	}

	public function setEnchantmentSeed(int $seed) : void{
	}

	public function regenerateEnchantmentSeed() : void{
	}

	public function getSneakOffset() : float{
		return 0.31;
	}

	/** what is drawn for a plugin's own human: Falcon spawns players itself */
	protected function sendSpawnPacket(Player $player) : void{
		$networkSession = $player->getNetworkSession();
		$typeConverter = $networkSession->getTypeConverter();
		$uuid = $this->getUniqueId();
		$networkSession->sendDataPacket(PlayerListPacket::add([PlayerListEntry::createAdditionEntry($uuid, $this->id, $this->getName(), $typeConverter->getSkinAdapter()->toSkinData($this->getSkin()))]));

		$networkSession->sendDataPacket(AddPlayerPacket::create(
			$uuid,
			$this->getName(),
			$this->getId(),
			"",
			$this->location->asVector3(),
			$this->getMotion(),
			$this->location->pitch,
			$this->location->yaw,
			$this->location->yaw, //TODO: head yaw
			new ItemStackWrapper(0, $typeConverter->coreItemStackToNet($this->getInventory()->getItemInHand())),
			GameMode::SURVIVAL,
			$this->getAllNetworkData(),
			new PropertySyncData([], []),
			UpdateAbilitiesPacket::create(new AbilitiesData(CommandPermissions::NORMAL, PlayerPermissions::VISITOR, $this->getId(), [
				new AbilitiesLayer(
					AbilitiesLayer::LAYER_BASE,
					array_fill(0, AbilitiesLayer::NUMBER_OF_ABILITIES, false),
					0.0,
					0.0,
					0.0
				)
			])),
			[], //TODO: entity links
			null,
			"", //device ID (we intentionally don't send this - secvuln)
			DeviceOS::UNKNOWN //we intentionally don't send this (secvuln)
		));

		//TODO: Hack for MCPE 1.2.13: DATA_NAMETAG is useless in AddPlayerPacket, so it has to be sent separately
		$this->sendData([$player], [EntityMetadataProperties::NAMETAG => new StringMetadataProperty($this->getNameTag())]);

		$entityEventBroadcaster = $networkSession->getEntityEventBroadcaster();
		$entityEventBroadcaster->onMobArmorChange([$networkSession], $this);
		$entityEventBroadcaster->onMobOffHandItemChange([$networkSession], $this);

		$networkSession->sendDataPacket(PlayerListPacket::remove([PlayerListEntry::createRemovalEntry($uuid)]));
	}

	public function getOffsetPosition(\pocketmine\math\Vector3 $vector3) : \pocketmine\math\Vector3{
		return $vector3->add(0, 1.621, 0);
	}

	protected function onDispose() : void{
		if(isset($this->inventory)){
			$this->inventory->removeAllViewers();
			$this->inventory->getHeldItemIndexChangeListeners()->clear();
		}
		if(isset($this->offHandInventory)){
			$this->offHandInventory->removeAllViewers();
		}
		if(isset($this->enderInventory)){
			$this->enderInventory->removeAllViewers();
		}
		parent::onDispose();
	}
}
