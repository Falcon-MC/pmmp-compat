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

use pmmpcompat\DamageCauses;
use pmmpcompat\Entities;
use pocketmine\entity\animation\Animation;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntityDespawnEvent;
use pocketmine\event\entity\EntityExtinguishEvent;
use pocketmine\event\entity\EntityMotionEvent;
use pocketmine\event\entity\EntityRegainHealthEvent;
use pocketmine\event\entity\EntitySpawnEvent;
use pocketmine\event\entity\EntityTeleportEvent;
use pocketmine\item\Item;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Facing;
use pocketmine\math\Vector2;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\DoubleTag;
use pocketmine\nbt\tag\FloatTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\network\mcpe\EntityEventBroadcaster;
use pocketmine\network\mcpe\NetworkBroadcastUtils;
use pocketmine\network\mcpe\protocol\AddActorPacket;
use pocketmine\network\mcpe\protocol\MoveActorAbsolutePacket;
use pocketmine\network\mcpe\protocol\SetActorMotionPacket;
use pocketmine\network\mcpe\protocol\types\entity\Attribute as NetworkAttribute;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\network\mcpe\protocol\types\entity\MetadataProperty;
use pocketmine\network\mcpe\protocol\types\entity\PropertySyncData;
use pocketmine\player\Player;
use pocketmine\Server;
use pocketmine\utils\Limits;
use pocketmine\utils\Utils;
use pocketmine\world\Position;
use pocketmine\world\sound\Sound;
use pocketmine\world\World;
use function abs;
use function array_map;
use function cos;
use function count;
use function deg2rad;
use function floatval;
use function floor;
use function fmod;
use function get_class;
use function is_int;
use function min;
use function sin;
use function spl_object_id;
use const M_PI_2;

/**
 * An entity. Two kinds share the class:
 *
 * - Falcon's own, players and mobs, made by runtime id: Falcon is asked again on each call, so an object a plugin
 *   keeps after the entity is gone reads as closed and does nothing, as PocketMine-MP's closed entities do.
 * - a plugin's own, made as on PocketMine-MP from a Location: PHP runs it as PocketMine-MP does, moves it, ticks it
 *   and shows it to clients with PocketMine-MP's packets. A Falcon entity no client sees holds its place, so hits,
 *   clicks, projectiles and the world's queries find it, and its runtime id is Falcon's.
 */
abstract class Entity{

	public const MOTION_THRESHOLD = 0.00001;
	protected const STEP_CLIP_MULTIPLIER = 0.4;

	private const TAG_FIRE = "Fire"; //TAG_Short
	private const TAG_ON_GROUND = "OnGround"; //TAG_Byte
	private const TAG_FALL_DISTANCE = "FallDistance"; //TAG_Float
	private const TAG_CUSTOM_NAME = "CustomName"; //TAG_String
	private const TAG_CUSTOM_NAME_VISIBLE = "CustomNameVisible"; //TAG_Byte
	public const TAG_POS = "Pos"; //TAG_List<TAG_Double>|TAG_List<TAG_Float>
	public const TAG_MOTION = "Motion"; //TAG_List<TAG_Double>|TAG_List<TAG_Float>
	public const TAG_ROTATION = "Rotation"; //TAG_List<TAG_Float>

	/** ids for what only PHP shows, such as floating text: far past Falcon's, which count up from 1 */
	private static int $entityCount = 1 << 40;

	public static function nextRuntimeId() : int{
		return self::$entityCount++;
	}

	/**
	 * @var Player[]
	 * @phpstan-var array<int, Player>
	 */
	protected array $hasSpawned = [];

	protected int $id;

	private EntityMetadataCollection $networkProperties;

	protected ?EntityDamageEvent $lastDamageCause = null;

	/** @var \pocketmine\block\Block[]|null */
	protected ?array $blocksAround = null;

	protected Location $location;
	protected Location $lastLocation;
	protected Vector3 $motion;
	protected Vector3 $lastMotion;
	protected bool $forceMovementUpdate = false;
	private bool $checkBlockIntersectionsNextTick = true;

	public AxisAlignedBB $boundingBox;
	public bool $onGround = false;

	public EntitySizeInfo $size;

	private float $health = 20.0;
	private int $maxHealth = 20;

	protected float $ySize = 0.0;
	protected float $stepHeight = 0.0;
	public bool $keepMovement = false;

	public float $fallDistance = 0.0;
	public int $ticksLived = 0;
	public int $lastUpdate = 0;
	protected int $fireTicks = 0;

	private bool $savedWithChunk = true;

	public bool $isCollided = false;
	public bool $isCollidedHorizontally = false;
	public bool $isCollidedVertically = false;

	public int $noDamageTicks = 0;
	protected bool $justCreated = true;

	protected AttributeMap $attributeMap;

	protected float $gravity = 0.0;
	protected float $drag = 0.0;
	protected bool $gravityEnabled = true;

	protected Server $server;

	protected bool $closed = false;
	private bool $closeInFlight = false;
	private bool $needsDespawn = false;

	protected bool $networkPropertiesDirty = false;

	protected string $nameTag = "";
	protected bool $nameTagVisible = true;
	protected bool $alwaysShowNameTag = false;
	protected string $scoreTag = "";
	protected float $scale = 1.0;

	protected bool $canClimb = false;
	protected bool $canClimbWalls = false;
	protected bool $noClientPredictions = false;
	protected bool $invisible = false;
	protected bool $silent = false;

	protected ?int $ownerId = null;
	protected ?int $targetId = null;

	private bool $constructorCalled = false;

	/** a plugin made it: PHP runs and draws it, Falcon only holds its place */
	private bool $drawn = false;

	/** ticks left burning as a plugin last set them on one of Falcon's; Falcon only tells whether it burns */
	private int $falconFireTicks = 0;

	/**
	 * @param Location|int $location where a plugin's own entity starts; a runtime id makes the object of one of
	 *     Falcon's, which Entities and Players do
	 */
	public function __construct(Location|int $location, ?CompoundTag $nbt = null){
		if($this->constructorCalled){
			throw new \LogicException("Attempted to call constructor for an Entity multiple times");
		}
		$this->constructorCalled = true;
		$this->server = Server::getInstance();
		if(is_int($location)){
			$this->id = $location;
			$this->justCreated = false;
			// read by nothing on Falcon's entities, set so a plugin reaching for them finds no hole
			$this->location = new Location(0, 0, 0, null, 0, 0);
			$this->lastLocation = $this->location;
			$this->motion = Vector3::zero();
			$this->lastMotion = $this->motion;
			$this->size = new EntitySizeInfo(1.8, 0.6);
			$this->boundingBox = new AxisAlignedBB(0, 0, 0, 0, 0, 0);
			$this->networkProperties = new EntityMetadataCollection();
			$this->attributeMap = new AttributeMap();
			return;
		}
		Utils::checkLocationNotInfOrNaN($location);
		if(!$location->isValid()){
			throw new \InvalidArgumentException("An entity needs a world to be made in");
		}
		$this->drawn = true;

		$this->size = $this->getInitialSizeInfo();
		$this->drag = $this->getInitialDragMultiplier();
		$this->gravity = $this->getInitialGravity();

		$this->location = $location->asLocation();

		$this->boundingBox = new AxisAlignedBB(0, 0, 0, 0, 0, 0);
		$this->recalculateBoundingBox();

		if($nbt !== null){
			$this->motion = EntityDataHelper::parseVec3($nbt, self::TAG_MOTION, true);
		}else{
			$this->motion = Vector3::zero();
		}

		$this->resetLastMovements();

		$this->networkProperties = new EntityMetadataCollection();

		$this->attributeMap = new AttributeMap();
		$this->addAttributes();

		// Falcon's stand-in gives the runtime id: hits and clicks of clients name it
		$this->id = Entities::place($this);

		$this->initEntity($nbt ?? new CompoundTag());

		$this->lastUpdate = $this->server->getTick();

		$this->scheduleUpdate();
	}

	/** @internal true for an entity a plugin made, which PHP runs and draws */
	public function isDrawnByPlugin() : bool{
		return $this->drawn;
	}

	/** @internal Falcon's handle now: the entity, or the stand-in of a plugin's own; 0 once it is gone */
	public function getEntityHandle() : int{
		if($this->closed){
			return 0;
		}
		$handle = Entities::handleOf($this->id);
		if($handle === 0 && !$this->drawn){
			$this->closed = true;
		}
		return $handle;
	}

	/**
	 * @return array{type: string, level: int, position: float[], rotation: float[], motion: float[], health: float,
	 *     maxHealth: float, alive: bool, nameTag: string, player: int, onFire: bool, invisible: bool}|null
	 */
	private function info() : ?array{
		$handle = $this->getEntityHandle();
		return $handle === 0 ? null : falcon_entity_info($handle);
	}

	protected function getInitialSizeInfo() : EntitySizeInfo{
		return new EntitySizeInfo(1.0, 1.0);
	}

	protected function getInitialDragMultiplier() : float{
		return 0.02;
	}

	protected function getInitialGravity() : float{
		return 0.08;
	}

	public function getNameTag() : string{
		return $this->drawn ? $this->nameTag : ($this->info()["nameTag"] ?? "");
	}

	public function isNameTagVisible() : bool{
		return $this->drawn ? $this->nameTagVisible : $this->getNameTag() !== "";
	}

	public function isNameTagAlwaysVisible() : bool{
		return $this->alwaysShowNameTag;
	}

	public function canBeRenamed() : bool{
		return false;
	}

	public function setNameTag(string $name) : void{
		if(!$this->drawn){
			$handle = $this->getEntityHandle();
			if($handle !== 0){
				falcon_entity_set_name_tag($handle, $name);
			}
			return;
		}
		$this->nameTag = $name;
		$this->networkPropertiesDirty = true;
	}

	public function setNameTagVisible(bool $value = true) : void{
		$this->nameTagVisible = $value;
		$this->networkPropertiesDirty = true;
	}

	public function setNameTagAlwaysVisible(bool $value = true) : void{
		$this->alwaysShowNameTag = $value;
		$this->networkPropertiesDirty = true;
	}

	public function getScoreTag() : ?string{
		return $this->scoreTag;
	}

	public function setScoreTag(string $score) : void{
		$this->scoreTag = $score;
		$this->networkPropertiesDirty = true;
	}

	public function getScale() : float{
		return $this->scale;
	}

	public function setScale(float $value) : void{
		if($value <= 0){
			throw new \InvalidArgumentException("Scale must be greater than 0");
		}
		$this->scale = $value;
		if($this->drawn){
			$this->setSize($this->getInitialSizeInfo()->scale($value));
		}
	}

	public function getBoundingBox() : AxisAlignedBB{
		if(!$this->drawn){
			$position = $this->getPosition();
			$half = $this->size->getWidth() / 2;
			return new AxisAlignedBB($position->x - $half, $position->y, $position->z - $half, $position->x + $half, $position->y + $this->size->getHeight(), $position->z + $half);
		}
		return $this->boundingBox;
	}

	protected function recalculateBoundingBox() : void{
		$halfWidth = $this->size->getWidth() / 2;

		$this->boundingBox = new AxisAlignedBB(
			$this->location->x - $halfWidth,
			$this->location->y + $this->ySize,
			$this->location->z - $halfWidth,
			$this->location->x + $halfWidth,
			$this->location->y + $this->size->getHeight() + $this->ySize,
			$this->location->z + $halfWidth
		);
	}

	public function getSize() : EntitySizeInfo{
		return $this->size;
	}

	protected function setSize(EntitySizeInfo $size) : void{
		$this->size = $size;
		$this->recalculateBoundingBox();
		$this->networkPropertiesDirty = true;
		if($this->drawn){
			Entities::resize($this);
		}
	}

	public function hasNoClientPredictions() : bool{
		return $this->noClientPredictions;
	}

	public function setNoClientPredictions(bool $value = true) : void{
		$this->noClientPredictions = $value;
		$this->networkPropertiesDirty = true;
	}

	public function isInvisible() : bool{
		return $this->drawn ? $this->invisible : ($this->info()["invisible"] ?? false);
	}

	public function setInvisible(bool $value = true) : void{
		if(!$this->drawn){
			$handle = $this->getEntityHandle();
			if($handle !== 0){
				falcon_entity_set_invisible($handle, $value);
			}
			return;
		}
		$this->invisible = $value;
		$this->networkPropertiesDirty = true;
	}

	public function isSilent() : bool{
		return $this->silent;
	}

	public function setSilent(bool $value = true) : void{
		$this->silent = $value;
		$this->networkPropertiesDirty = true;
	}

	public function canClimb() : bool{
		return $this->canClimb;
	}

	public function setCanClimb(bool $value = true) : void{
		$this->canClimb = $value;
		$this->networkPropertiesDirty = true;
	}

	public function canClimbWalls() : bool{
		return $this->canClimbWalls;
	}

	public function setCanClimbWalls(bool $value = true) : void{
		$this->canClimbWalls = $value;
		$this->networkPropertiesDirty = true;
	}

	public function getOwningEntityId() : ?int{
		return $this->ownerId;
	}

	public function getOwningEntity() : ?Entity{
		return $this->ownerId !== null ? $this->server->getWorldManager()->findEntity($this->ownerId) : null;
	}

	public function setOwningEntity(?Entity $owner) : void{
		if($owner === null){
			$this->ownerId = null;
		}elseif($owner->isClosed()){
			throw new \InvalidArgumentException("Supplied owning entity is garbage and cannot be used");
		}else{
			$this->ownerId = $owner->getId();
		}
		$this->networkPropertiesDirty = true;
	}

	public function getTargetEntityId() : ?int{
		return $this->targetId;
	}

	public function getTargetEntity() : ?Entity{
		return $this->targetId !== null ? $this->server->getWorldManager()->findEntity($this->targetId) : null;
	}

	public function setTargetEntity(?Entity $target) : void{
		if($target === null){
			$this->targetId = null;
		}elseif($target->isClosed()){
			throw new \InvalidArgumentException("Supplied target entity is garbage and cannot be used");
		}else{
			$this->targetId = $target->getId();
		}
		$this->networkPropertiesDirty = true;
	}

	public function canSaveWithChunk() : bool{
		return $this->savedWithChunk;
	}

	public function setCanSaveWithChunk(bool $value) : void{
		$this->savedWithChunk = $value;
	}

	public function saveNBT() : CompoundTag{
		$location = $this->getLocation();
		$motion = $this->getMotion();
		$nbt = CompoundTag::create()
			->setTag(self::TAG_POS, new ListTag([
				new DoubleTag($location->x),
				new DoubleTag($location->y),
				new DoubleTag($location->z)
			]))
			->setTag(self::TAG_MOTION, new ListTag([
				new DoubleTag($motion->x),
				new DoubleTag($motion->y),
				new DoubleTag($motion->z)
			]))
			->setTag(self::TAG_ROTATION, new ListTag([
				new FloatTag($location->yaw),
				new FloatTag($location->pitch)
			]));

		if(!($this instanceof NeverSavedWithChunkEntity)){
			if(EntityFactory::getInstance()->isRegistered(get_class($this))){
				EntityFactory::getInstance()->injectSaveId(get_class($this), $nbt);
			}

			if($this->getNameTag() !== ""){
				$nbt->setString(self::TAG_CUSTOM_NAME, $this->getNameTag());
				$nbt->setByte(self::TAG_CUSTOM_NAME_VISIBLE, $this->isNameTagVisible() ? 1 : 0);
			}
		}

		$nbt->setFloat(self::TAG_FALL_DISTANCE, $this->fallDistance);
		$nbt->setShort(self::TAG_FIRE, $this->fireTicks);
		$nbt->setByte(self::TAG_ON_GROUND, $this->onGround ? 1 : 0);

		return $nbt;
	}

	protected function initEntity(CompoundTag $nbt) : void{
		$this->fireTicks = $nbt->getShort(self::TAG_FIRE, 0);

		$this->onGround = $nbt->getByte(self::TAG_ON_GROUND, 0) !== 0;

		$this->fallDistance = $nbt->getFloat(self::TAG_FALL_DISTANCE, 0.0);

		if(($customNameTag = $nbt->getTag(self::TAG_CUSTOM_NAME)) instanceof StringTag){
			$this->setNameTag($customNameTag->getValue());

			if(($customNameVisibleTag = $nbt->getTag(self::TAG_CUSTOM_NAME_VISIBLE)) instanceof StringTag){
				//Older versions incorrectly saved this as a string (see 890f72dbf23a77f294169b79590770470041adc4)
				$this->setNameTagVisible($customNameVisibleTag->getValue() !== "");
			}else{
				$this->setNameTagVisible($nbt->getByte(self::TAG_CUSTOM_NAME_VISIBLE, 1) !== 0);
			}
		}
	}

	protected function addAttributes() : void{

	}

	public function attack(EntityDamageEvent $source) : void{
		if(!$this->drawn){
			// through Falcon, which fires its damage event: PocketMine-MP listeners see it there
			$handle = $this->getEntityHandle();
			if($handle === 0){
				return;
			}
			$attacker = $source instanceof EntityDamageByEntityEvent ? ($source->getDamager()?->getEntityHandle() ?? 0) : 0;
			$this->lastDamageCause = $source;
			falcon_entity_damage($handle, $source->getFinalDamage(), DamageCauses::toFalcon($source->getCause()), $attacker);
			return;
		}
		if($this->isFireProof() && (
				$source->getCause() === EntityDamageEvent::CAUSE_FIRE ||
				$source->getCause() === EntityDamageEvent::CAUSE_FIRE_TICK ||
				$source->getCause() === EntityDamageEvent::CAUSE_LAVA
			)
		){
			$source->cancel();
		}
		$source->call();
		if($source->isCancelled()){
			return;
		}

		$this->setLastDamageCause($source);

		$this->setHealth($this->getHealth() - $source->getFinalDamage());
	}

	public function heal(EntityRegainHealthEvent $source) : void{
		$source->call();
		if($source->isCancelled()){
			return;
		}

		$this->setHealth($this->getHealth() + $source->getAmount());
	}

	public function kill() : void{
		if(!$this->drawn){
			$handle = $this->getEntityHandle();
			if($handle !== 0){
				falcon_entity_kill($handle);
			}
			return;
		}
		if($this->isAlive()){
			$this->health = 0;
			$this->onDeath();
			$this->scheduleUpdate();
		}
	}

	protected function onDeath() : void{

	}

	protected function onDeathUpdate(int $tickDiff) : bool{
		return true;
	}

	public function isAlive() : bool{
		return $this->drawn ? $this->health > 0 : ($this->info()["alive"] ?? false);
	}

	public function getHealth() : float{
		return $this->drawn ? $this->health : ($this->info()["health"] ?? 0.0);
	}

	public function setHealth(float $amount) : void{
		if(!$this->drawn){
			$handle = $this->getEntityHandle();
			if($handle !== 0){
				falcon_entity_set_health($handle, $amount);
			}
			return;
		}
		if($amount === $this->health){
			return;
		}

		if($amount <= 0){
			if($this->isAlive()){
				if(!$this->justCreated){
					$this->kill();
				}else{
					$this->health = 0;
				}
			}
		}elseif($amount <= $this->getMaxHealth() || $amount < $this->health){
			$this->health = $amount;
		}else{
			$this->health = $this->getMaxHealth();
		}
	}

	public function getMaxHealth() : int{
		return $this->drawn ? $this->maxHealth : (int) ($this->info()["maxHealth"] ?? 0);
	}

	public function setMaxHealth(int $amount) : void{
		if($this->drawn){
			$this->maxHealth = $amount;
		}elseif(($handle = $this->getEntityHandle()) !== 0){
			falcon_entity_set_max_health($handle, $amount);
		}
	}

	public function setLastDamageCause(EntityDamageEvent $type) : void{
		$this->lastDamageCause = $type;
	}

	public function getLastDamageCause() : ?EntityDamageEvent{
		return $this->lastDamageCause;
	}

	public function getAttributeMap() : AttributeMap{
		return $this->attributeMap;
	}

	public function getNetworkProperties() : EntityMetadataCollection{
		return $this->networkProperties;
	}

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		//TODO: check vehicles

		if($this->justCreated){
			$this->justCreated = false;
			if(!$this->isAlive()){
				$this->kill();
			}
		}

		$changedProperties = $this->getDirtyNetworkData();
		if(count($changedProperties) > 0){
			$this->sendData(null, $changedProperties);
			$this->networkProperties->clearDirtyProperties();
		}

		$hasUpdate = false;

		if($this->checkBlockIntersectionsNextTick){
			$this->checkBlockIntersections();
		}
		$this->checkBlockIntersectionsNextTick = true;

		if($this->location->y <= World::Y_MIN - 16 && $this->isAlive()){
			$ev = new EntityDamageEvent($this, EntityDamageEvent::CAUSE_VOID, 10);
			$this->attack($ev);
			$hasUpdate = true;
		}

		if($this->isOnFire() && $this->doOnFireTick($tickDiff)){
			$hasUpdate = true;
		}

		if($this->noDamageTicks > 0){
			$this->noDamageTicks -= $tickDiff;
			if($this->noDamageTicks < 0){
				$this->noDamageTicks = 0;
			}
		}

		$this->ticksLived += $tickDiff;

		return $hasUpdate;
	}

	public function isOnFire() : bool{
		return $this->drawn ? $this->fireTicks > 0 : ($this->info()["onFire"] ?? false);
	}

	public function setOnFire(int $seconds) : void{
		$ticks = $seconds * 20;
		if(!$this->drawn || $ticks > $this->getFireTicks()){
			$this->setFireTicks($ticks);
		}
		$this->networkPropertiesDirty = true;
	}

	public function getFireTicks() : int{
		if(!$this->drawn){
			return $this->isOnFire() ? max($this->falconFireTicks, 1) : 0;
		}
		return $this->fireTicks;
	}

	public function setFireTicks(int $fireTicks) : void{
		if($fireTicks < 0){
			throw new \InvalidArgumentException("Fire ticks cannot be negative");
		}
		$fireTicks = min($fireTicks, Limits::INT16_MAX);
		if(!$this->drawn){
			$this->falconFireTicks = $fireTicks;
			$handle = $this->getEntityHandle();
			if($handle !== 0){
				falcon_entity_set_on_fire($handle, $fireTicks);
			}
			return;
		}

		if(!$this->isFireProof()){
			$this->fireTicks = $fireTicks;
			$this->networkPropertiesDirty = true;
		}
	}

	public function extinguish(int $cause = EntityExtinguishEvent::CAUSE_CUSTOM) : void{
		if(!$this->drawn){
			$this->setFireTicks(0);
			return;
		}
		$ev = new EntityExtinguishEvent($this, $cause);
		$ev->call();

		$this->fireTicks = 0;
		$this->networkPropertiesDirty = true;
	}

	public function isFireProof() : bool{
		return false;
	}

	protected function doOnFireTick(int $tickDiff = 1) : bool{
		if($this->isFireProof() && $this->isOnFire()){
			$this->extinguish(EntityExtinguishEvent::CAUSE_FIRE_PROOF);
			return false;
		}

		$this->fireTicks -= $tickDiff;

		if(($this->fireTicks % 20 === 0) || $tickDiff > 20){
			$this->dealFireDamage();
		}

		if(!$this->isOnFire()){
			$this->extinguish(EntityExtinguishEvent::CAUSE_TICKING);
		}else{
			return true;
		}

		return false;
	}

	protected function dealFireDamage() : void{
		$ev = new EntityDamageEvent($this, EntityDamageEvent::CAUSE_FIRE_TICK, 1);
		$this->attack($ev);
	}

	public function canCollideWith(Entity $entity) : bool{
		return !$this->justCreated && $entity !== $this;
	}

	public function canBeCollidedWith() : bool{
		return $this->isAlive();
	}

	protected function updateMovement(bool $teleport = false) : void{
		if(!$this->drawn){
			return;
		}
		$diffPosition = $this->location->distanceSquared($this->lastLocation);
		$diffRotation = ($this->location->yaw - $this->lastLocation->yaw) ** 2 + ($this->location->pitch - $this->lastLocation->pitch) ** 2;

		$diffMotion = $this->motion->subtractVector($this->lastMotion)->lengthSquared();

		$still = $this->motion->lengthSquared() === 0.0;
		$wasStill = $this->lastMotion->lengthSquared() === 0.0;
		if($wasStill !== $still){
			//TODO: hack for client-side AI interference: prevent client sided movement when motion is 0
			$this->setNoClientPredictions($still);
		}

		if($teleport || $diffPosition > 0.0001 || $diffRotation > 1.0 || (!$wasStill && $still)){
			$this->lastLocation = $this->location->asLocation();

			$this->broadcastMovement($teleport);
		}

		if($diffMotion > 0.0025 || $wasStill !== $still){ //0.05 ** 2
			$this->lastMotion = clone $this->motion;

			$this->broadcastMotion();
		}
	}

	public function getOffsetPosition(Vector3 $vector3) : Vector3{
		return $vector3;
	}

	protected function broadcastMovement(bool $teleport = false) : void{
		if(count($this->hasSpawned) === 0){
			return;
		}
		NetworkBroadcastUtils::broadcastPackets($this->hasSpawned, [MoveActorAbsolutePacket::create(
			$this->id,
			$this->getOffsetPosition($this->location),
			$this->location->pitch,
			$this->location->yaw,
			$this->location->yaw,
			(
				($this->onGround ? MoveActorAbsolutePacket::FLAG_GROUND : 0)
			)
		)]);
	}

	protected function broadcastMotion() : void{
		if(count($this->hasSpawned) === 0){
			return;
		}
		NetworkBroadcastUtils::broadcastPackets($this->hasSpawned, [SetActorMotionPacket::create($this->id, $this->getMotion(), tick: 0)]);
	}

	public function getGravity() : float{
		return $this->gravity;
	}

	public function setGravity(float $gravity) : void{
		Utils::checkFloatNotInfOrNaN("gravity", $gravity);
		$this->gravity = $gravity;
	}

	public function hasGravity() : bool{
		return $this->gravityEnabled;
	}

	public function setHasGravity(bool $v = true) : void{
		$this->gravityEnabled = $v;
	}

	public function getDrag() : float{
		return $this->drag;
	}

	public function setDrag(float $drag) : void{
		Utils::checkFloatNotInfOrNaN("drag", $drag);
		$this->drag = $drag;
	}

	protected function applyDragBeforeGravity() : bool{
		return false;
	}

	protected function tryChangeMovement() : void{
		$friction = 1 - $this->drag;

		$mY = $this->motion->y;

		if($this->applyDragBeforeGravity()){
			$mY *= $friction;
		}

		if($this->gravityEnabled){
			$mY -= $this->gravity;
		}

		if(!$this->applyDragBeforeGravity()){
			$mY *= $friction;
		}

		if($this->onGround){
			$friction *= $this->getWorld()->getBlockAt((int) floor($this->location->x), (int) floor($this->location->y - 1), (int) floor($this->location->z))->getFrictionFactor();
		}

		$this->motion = new Vector3($this->motion->x * $friction, $mY, $this->motion->z * $friction);
	}

	protected function checkObstruction(float $x, float $y, float $z) : bool{
		return false;
	}

	public function getHorizontalFacing() : int{
		$angle = fmod($this->getLocation()->yaw, 360);
		if($angle < 0){
			$angle += 360.0;
		}

		if((0 <= $angle && $angle < 45) || (315 <= $angle && $angle < 360)){
			return Facing::SOUTH;
		}
		if(45 <= $angle && $angle < 135){
			return Facing::WEST;
		}
		if(135 <= $angle && $angle < 225){
			return Facing::NORTH;
		}

		return Facing::EAST;
	}

	public function getDirectionVector() : Vector3{
		$location = $this->getLocation();
		$y = -sin(deg2rad($location->pitch));
		$xz = cos(deg2rad($location->pitch));
		$x = -$xz * sin(deg2rad($location->yaw));
		$z = $xz * cos(deg2rad($location->yaw));

		return (new Vector3($x, $y, $z))->normalize();
	}

	public function getDirectionPlane() : Vector2{
		$yaw = $this->getLocation()->yaw;
		return (new Vector2(-cos(deg2rad($yaw) - M_PI_2), -sin(deg2rad($yaw) - M_PI_2)))->normalize();
	}

	/**
	 * Called from onUpdate() on the first tick of a new entity. This is called before any movement processing or
	 * main ticking logic. Use this to fire any events related to spawning the entity.
	 */
	protected function onFirstUpdate(int $currentTick) : void{
		(new EntitySpawnEvent($this))->call();
	}

	public function onUpdate(int $currentTick) : bool{
		if($this->closed || !$this->drawn){
			return false;
		}

		$tickDiff = $currentTick - $this->lastUpdate;
		if($tickDiff <= 0){
			return true;
		}

		$this->lastUpdate = $currentTick;

		if($this->justCreated){
			$this->onFirstUpdate($currentTick);
		}

		if(!$this->isAlive()){
			if($this->onDeathUpdate($tickDiff)){
				$this->flagForDespawn();
			}

			return true;
		}

		if($this->hasMovementUpdate()){
			$this->tryChangeMovement();

			$this->motion = $this->motion->withComponents(
				abs($this->motion->x) <= self::MOTION_THRESHOLD ? 0 : null,
				abs($this->motion->y) <= self::MOTION_THRESHOLD ? 0 : null,
				abs($this->motion->z) <= self::MOTION_THRESHOLD ? 0 : null
			);

			if(floatval($this->motion->x) !== 0.0 || floatval($this->motion->y) !== 0.0 || floatval($this->motion->z) !== 0.0){
				$this->move($this->motion->x, $this->motion->y, $this->motion->z);
			}

			$this->forceMovementUpdate = false;
		}

		$this->updateMovement();

		$hasUpdate = $this->entityBaseTick($tickDiff);

		return ($hasUpdate || $this->hasMovementUpdate());
	}

	final public function scheduleUpdate() : void{
		if($this->closed){
			throw new \LogicException("Cannot schedule update on garbage entity " . get_class($this));
		}
		if($this->drawn){
			Entities::scheduleUpdate($this);
		}
	}

	public function onNearbyBlockChange() : void{
		$this->setForceMovementUpdate();
		$this->scheduleUpdate();
	}

	public function onRandomUpdate() : void{
		$this->scheduleUpdate();
	}

	final public function setForceMovementUpdate(bool $value = true) : void{
		$this->forceMovementUpdate = $value;

		$this->blocksAround = null;
	}

	public function hasMovementUpdate() : bool{
		return (
			$this->forceMovementUpdate ||
			floatval($this->motion->x) !== 0.0 ||
			floatval($this->motion->y) !== 0.0 ||
			floatval($this->motion->z) !== 0.0 ||
			!$this->onGround
		);
	}

	public function getFallDistance() : float{ return $this->fallDistance; }

	public function setFallDistance(float $fallDistance) : void{
		$this->fallDistance = $fallDistance;
	}

	public function resetFallDistance() : void{
		$this->fallDistance = 0.0;
	}

	protected function updateFallState(float $distanceThisTick, bool $onGround) : ?float{
		if($distanceThisTick < $this->fallDistance){
			$this->fallDistance -= $distanceThisTick;
		}else{
			$this->fallDistance = 0;
		}
		if($onGround && $this->fallDistance > 0){
			$newVerticalVelocity = $this->onHitGround();
			$this->resetFallDistance();
			return $newVerticalVelocity;
		}
		return null;
	}

	protected function onHitGround() : ?float{
		return null;
	}

	public function getEyeHeight() : float{
		return $this->size->getEyeHeight();
	}

	public function getEyePos() : Vector3{
		$position = $this->getPosition();
		return new Vector3($position->x, $position->y + $this->getEyeHeight(), $position->z);
	}

	public function onCollideWithPlayer(Player $player) : void{

	}

	/**
	 * Called when interacted or tapped by a Player. Returns whether something happened as a result of the interaction.
	 */
	public function onInteract(Player $player, Vector3 $clickPos) : bool{
		return false;
	}

	public function isUnderwater() : bool{
		$position = $this->getPosition();
		$block = $this->getWorld()->getBlockAt((int) floor($position->x), (int) floor($position->y + $this->getEyeHeight()), (int) floor($position->z));
		return $block instanceof \pocketmine\block\Water;
	}

	public function isInsideOfSolid() : bool{
		if(!$this->drawn){
			return false;
		}
		$y = $this->location->y + $this->getEyeHeight();
		$boxes = $this->getWorld()->getBlockCollisionBoxes(new AxisAlignedBB(
			$this->location->x - 0.1, $y - 0.1, $this->location->z - 0.1,
			$this->location->x + 0.1, $y + 0.1, $this->location->z + 0.1
		));
		return count($boxes) > 0;
	}

	protected function move(float $dx, float $dy, float $dz) : void{
		if(!$this->drawn){
			return;
		}
		$this->blocksAround = null;

		$wantedX = $dx;
		$wantedY = $dy;
		$wantedZ = $dz;

		if($this->keepMovement){
			$this->boundingBox->offset($dx, $dy, $dz);
		}else{
			$this->ySize *= self::STEP_CLIP_MULTIPLIER;

			$moveBB = clone $this->boundingBox;

			$list = $this->getWorld()->getBlockCollisionBoxes($moveBB->addCoord($dx, $dy, $dz));

			foreach($list as $bb){
				$dy = $bb->calculateYOffset($moveBB, $dy);
			}

			$moveBB->offset(0, $dy, 0);

			$fallingFlag = ($this->onGround || ($dy !== $wantedY && $wantedY < 0));

			foreach($list as $bb){
				$dx = $bb->calculateXOffset($moveBB, $dx);
			}

			$moveBB->offset($dx, 0, 0);

			foreach($list as $bb){
				$dz = $bb->calculateZOffset($moveBB, $dz);
			}

			$moveBB->offset(0, 0, $dz);

			$stepHeight = $this->getStepHeight();

			if($stepHeight > 0 && $fallingFlag && ($wantedX !== $dx || $wantedZ !== $dz)){
				$cx = $dx;
				$cy = $dy;
				$cz = $dz;
				$dx = $wantedX;
				$dy = $stepHeight;
				$dz = $wantedZ;

				$stepBB = clone $this->boundingBox;

				$list = $this->getWorld()->getBlockCollisionBoxes($stepBB->addCoord($dx, $dy, $dz));
				foreach($list as $bb){
					$dy = $bb->calculateYOffset($stepBB, $dy);
				}

				$stepBB->offset(0, $dy, 0);

				foreach($list as $bb){
					$dx = $bb->calculateXOffset($stepBB, $dx);
				}

				$stepBB->offset($dx, 0, 0);

				foreach($list as $bb){
					$dz = $bb->calculateZOffset($stepBB, $dz);
				}

				$stepBB->offset(0, 0, $dz);

				$reverseDY = -$dy;
				foreach($list as $bb){
					$reverseDY = $bb->calculateYOffset($stepBB, $reverseDY);
				}
				$dy += $reverseDY;
				$stepBB->offset(0, $reverseDY, 0);

				if(($cx ** 2 + $cz ** 2) >= ($dx ** 2 + $dz ** 2)){
					$dx = $cx;
					$dy = $cy;
					$dz = $cz;
				}else{
					$moveBB = $stepBB;
					$this->ySize += $dy;
				}
			}

			$this->boundingBox = $moveBB;
		}

		$this->location = new Location(
			($this->boundingBox->minX + $this->boundingBox->maxX) / 2,
			$this->boundingBox->minY - $this->ySize,
			($this->boundingBox->minZ + $this->boundingBox->maxZ) / 2,
			$this->location->world,
			$this->location->yaw,
			$this->location->pitch
		);

		$this->checkBlockIntersections();
		$this->checkGroundState($wantedX, $wantedY, $wantedZ, $dx, $dy, $dz);
		$postFallVerticalVelocity = $this->updateFallState($dy, $this->onGround);

		$this->motion = $this->motion->withComponents(
			$wantedX !== $dx ? 0 : null,
			$postFallVerticalVelocity ?? ($wantedY !== $dy ? 0 : null),
			$wantedZ !== $dz ? 0 : null
		);
	}

	public function setStepHeight(float $stepHeight) : void{
		$this->stepHeight = $stepHeight;
	}

	public function getStepHeight() : float{
		return $this->stepHeight;
	}

	protected function checkGroundState(float $wantedX, float $wantedY, float $wantedZ, float $dx, float $dy, float $dz) : void{
		$this->isCollidedVertically = $wantedY !== $dy;
		$this->isCollidedHorizontally = ($wantedX !== $dx || $wantedZ !== $dz);
		$this->isCollided = ($this->isCollidedHorizontally || $this->isCollidedVertically);
		$this->onGround = ($wantedY !== $dy && $wantedY < 0);
	}

	/**
	 * Falcon's blocks act on what touches them through its own systems; the blocks of this runtime have no
	 * entity-inside hooks to call yet.
	 */
	protected function checkBlockIntersections() : void{
		$this->checkBlockIntersectionsNextTick = false;
	}

	public function canBeMovedByCurrents() : bool{
		return true;
	}

	public function getPosition() : Position{
		if(!$this->drawn){
			$info = $this->info();
			if($info === null){
				return new Position(0, 0, 0, null);
			}
			[$x, $y, $z] = $info["position"];
			return new Position($x, $y, $z, $this->server->getWorldManager()->forLevel($info["level"]));
		}
		return $this->location->asPosition();
	}

	/** Falcon gives the rotation of its own as pitch, yaw */
	public function getLocation() : Location{
		if(!$this->drawn){
			$info = $this->info();
			if($info === null){
				return new Location(0, 0, 0, null, 0, 0);
			}
			[$x, $y, $z] = $info["position"];
			[$pitch, $yaw] = $info["rotation"];
			return new Location($x, $y, $z, $this->server->getWorldManager()->forLevel($info["level"]), $yaw, $pitch);
		}
		return $this->location->asLocation();
	}

	public function getWorld() : World{
		if(!$this->drawn){
			$world = $this->server->getWorldManager()->forLevel($this->info()["level"] ?? 0);
			if($world === null){
				throw new \LogicException("The entity is not in a world");
			}
			return $world;
		}
		return $this->location->getWorld();
	}

	public function getWorldOrNull() : ?World{
		if($this->drawn){
			return $this->location->isValid() ? $this->location->getWorld() : null;
		}
		return $this->isClosed() ? null : $this->getWorld();
	}

	protected function setPosition(Vector3 $pos) : bool{
		if($this->closed){
			return false;
		}

		$oldWorld = $this->getWorld();
		$newWorld = $pos instanceof Position ? $pos->getWorld() : $oldWorld;
		if($oldWorld !== $newWorld){
			$this->despawnFromAll();
		}

		$this->location = Location::fromObject(
			$pos,
			$newWorld,
			$this->location->yaw,
			$this->location->pitch
		);

		$this->recalculateBoundingBox();

		$this->blocksAround = null;

		return true;
	}

	public function setRotation(float $yaw, float $pitch) : void{
		Utils::checkFloatNotInfOrNaN("yaw", $yaw);
		Utils::checkFloatNotInfOrNaN("pitch", $pitch);
		if(!$this->drawn){
			return;
		}
		$this->location->yaw = $yaw;
		$this->location->pitch = $pitch;
		$this->scheduleUpdate();
	}

	protected function setPositionAndRotation(Vector3 $pos, float $yaw, float $pitch) : bool{
		if($this->setPosition($pos)){
			$this->setRotation($yaw, $pitch);

			return true;
		}

		return false;
	}

	protected function resetLastMovements() : void{
		$this->lastLocation = $this->location->asLocation();
		$this->lastMotion = clone $this->motion;
	}

	public function getMotion() : Vector3{
		if(!$this->drawn){
			[$x, $y, $z] = $this->info()["motion"] ?? [0.0, 0.0, 0.0];
			return new Vector3($x, $y, $z);
		}
		return clone $this->motion;
	}

	public function setMotion(Vector3 $motion) : bool{
		Utils::checkVector3NotInfOrNaN($motion);
		if(!$this->drawn){
			$handle = $this->getEntityHandle();
			if($handle === 0){
				return false;
			}
			$event = new EntityMotionEvent($this, $motion);
			$event->call();
			if($event->isCancelled() || ($handle = $this->getEntityHandle()) === 0){
				return false;
			}
			$motion = $event->getVector();
			Utils::checkVector3NotInfOrNaN($motion);
			falcon_entity_set_motion($handle, $motion->x, $motion->y, $motion->z);
			return true;
		}

		if(!$this->justCreated){
			$ev = new EntityMotionEvent($this, $motion);
			$ev->call();
			if($ev->isCancelled()){
				return false;
			}
		}

		$this->motion = clone $motion;

		if(!$this->justCreated){
			$this->updateMovement();
		}

		return true;
	}

	public function addMotion(float $x, float $y, float $z) : void{
		Utils::checkFloatNotInfOrNaN("x", $x);
		Utils::checkFloatNotInfOrNaN("y", $y);
		Utils::checkFloatNotInfOrNaN("z", $z);
		if(!$this->drawn){
			$this->setMotion($this->getMotion()->add($x, $y, $z));
			return;
		}
		$this->motion = $this->motion->add($x, $y, $z);
	}

	public function isOnGround() : bool{
		return $this->onGround;
	}

	/**
	 * @param Vector3|Position|Location $pos
	 */
	public function teleport(Vector3 $pos, ?float $yaw = null, ?float $pitch = null) : bool{
		Utils::checkVector3NotInfOrNaN($pos);
		if($pos instanceof Location){
			$yaw = $yaw ?? $pos->yaw;
			$pitch = $pitch ?? $pos->pitch;
		}
		if($yaw !== null){
			Utils::checkFloatNotInfOrNaN("yaw", $yaw);
		}
		if($pitch !== null){
			Utils::checkFloatNotInfOrNaN("pitch", $pitch);
		}
		if(!$this->drawn){
			$handle = $this->getEntityHandle();
			if($handle === 0){
				return false;
			}
			$from = $this->getLocation();
			$world = $pos instanceof Position && $pos->isValid() ? $pos->getWorld() : $from->getWorld();
			if(function_exists('falcon_entity_teleport_with_rotation')){
				$result = falcon_entity_teleport_with_rotation($handle, $world->getHandle(), $pos->x, $pos->y, $pos->z,
					$yaw ?? $from->yaw, $pitch ?? $from->pitch);
				if($result !== null){
					return $result;
				}
			}
			falcon_entity_teleport($handle, $world->getHandle(), $pos->x, $pos->y, $pos->z);
			return true;
		}

		$from = $this->location->asPosition();
		$to = Position::fromObject($pos, $pos instanceof Position && $pos->isValid() ? $pos->getWorld() : $this->getWorld());
		$ev = new EntityTeleportEvent($this, $from, $to);
		$ev->call();
		if($ev->isCancelled()){
			return false;
		}
		$this->ySize = 0;
		$pos = $ev->getTo();

		$this->setMotion(new Vector3(0, 0, 0));
		if($this->setPositionAndRotation($pos, $yaw ?? $this->location->yaw, $pitch ?? $this->location->pitch)){
			$this->resetFallDistance();
			$this->setForceMovementUpdate();

			$this->updateMovement(true);

			return true;
		}

		return false;
	}

	public function getId() : int{
		return $this->id;
	}

	/**
	 * @return Player[]
	 */
	public function getViewers() : array{
		if(!$this->drawn){
			$world = $this->getWorldOrNull();
			return $world === null ? [] : $world->getViewersForPosition($this->getPosition());
		}
		return $this->hasSpawned;
	}

	/**
	 * The game's identifier of the class's entities, minecraft:zombie; a plugin's own entity class gives its own.
	 * Falcon's entities PocketMine-MP has no class for share a class: their identifier is getFalconTypeId()'s.
	 */
	public static function getNetworkTypeId() : string{
		return Entities::networkTypeOf(static::class);
	}

	/** @internal the game's identifier of this entity, as Falcon has it */
	public function getFalconTypeId() : string{
		return $this->drawn ? static::getNetworkTypeId() : ($this->info()["type"] ?? "");
	}

	/**
	 * Called by spawnTo() to send whatever packets needed to spawn the entity to the client.
	 */
	protected function sendSpawnPacket(Player $player) : void{
		$player->getNetworkSession()->sendDataPacket(AddActorPacket::create(
			$this->getId(),
			$this->getId(),
			static::getNetworkTypeId(),
			$this->getOffsetPosition($this->location->asVector3()),
			$this->getMotion(),
			$this->location->pitch,
			$this->location->yaw,
			$this->location->yaw,
			$this->location->yaw,
			array_map(function(Attribute $attr) : NetworkAttribute{
				return new NetworkAttribute($attr->getId(), $attr->getMinValue(), $attr->getMaxValue(), $attr->getValue(), $attr->getDefaultValue(), []);
			}, $this->attributeMap->getAll()),
			$this->getAllNetworkData(),
			new PropertySyncData([], []),
			[],
			null
		));
	}

	public function spawnTo(Player $player) : void{
		if(!$this->drawn){
			return;
		}
		$id = spl_object_id($player);
		if(!isset($this->hasSpawned[$id]) && $player->isOnline() && $player->getWorld() === $this->getWorld() && $player->hasReceivedChunk($this->location->getFloorX() >> 4, $this->location->getFloorZ() >> 4)){
			$this->hasSpawned[$id] = $player;

			$this->sendSpawnPacket($player);
		}
	}

	public function spawnToAll() : void{
		if($this->closed || !$this->drawn){
			return;
		}
		foreach($this->getWorld()->getViewersForPosition($this->location) as $player){
			$this->spawnTo($player);
		}
	}

	public function respawnToAll() : void{
		foreach($this->hasSpawned as $key => $player){
			unset($this->hasSpawned[$key]);
			$this->spawnTo($player);
		}
	}

	/**
	 * @deprecated WARNING: This function DOES NOT permanently hide the entity from the player. As soon as the entity or
	 * player moves, the player will once again be able to see the entity.
	 */
	public function despawnFrom(Player $player, bool $send = true) : void{
		$id = spl_object_id($player);
		if(isset($this->hasSpawned[$id])){
			if($send && $player->isConnected()){
				$player->getNetworkSession()->getEntityEventBroadcaster()->onEntityRemoved([$player->getNetworkSession()], $this);
			}
			unset($this->hasSpawned[$id]);
		}
	}

	/**
	 * @deprecated WARNING: This function DOES NOT permanently hide the entity from viewers. As soon as the entity or
	 * player moves, viewers will once again be able to see the entity.
	 */
	public function despawnFromAll() : void{
		NetworkBroadcastUtils::broadcastEntityEvent(
			$this->hasSpawned,
			fn(EntityEventBroadcaster $broadcaster, array $recipients) => $broadcaster->onEntityRemoved($recipients, $this)
		);
		$this->hasSpawned = [];
	}

	/**
	 * Returns the item that players will equip when middle-clicking on this entity.
	 */
	public function getPickedItem() : ?Item{
		return null;
	}

	/**
	 * Flags the entity to be removed from the world on the next tick.
	 */
	public function flagForDespawn() : void{
		$this->needsDespawn = true;
		if(!$this->drawn){
			$this->close();
			return;
		}
		$this->scheduleUpdate();
	}

	public function isFlaggedForDespawn() : bool{
		return $this->needsDespawn;
	}

	/**
	 * Returns whether the entity has been "closed".
	 */
	public function isClosed() : bool{
		return $this->drawn ? $this->closed : $this->getEntityHandle() === 0;
	}

	/**
	 * Closes the entity and frees attached references.
	 *
	 * WARNING: Entities are unusable after this has been executed!
	 */
	public function close() : void{
		if(!$this->drawn){
			$handle = $this->getEntityHandle();
			if($handle !== 0 && !$this instanceof Player){
				falcon_entity_remove($handle);
			}
			$this->closed = true;
			return;
		}
		if($this->closeInFlight){
			return;
		}

		if(!$this->closed){
			$this->closeInFlight = true;
			(new EntityDespawnEvent($this))->call();

			$this->onDispose();
			$this->closed = true;
			$this->destroyCycles();
			$this->closeInFlight = false;
		}
	}

	/**
	 * Called when the entity is disposed to clean up things like viewers. This SHOULD NOT destroy internal state,
	 * because it may be needed by descendent classes.
	 */
	protected function onDispose() : void{
		$this->despawnFromAll();
		Entities::remove($this);
	}

	/**
	 * Called when the entity is disposed, after all events have been fired. This should be used to perform destructive
	 * circular object references and things which could impact memory usage.
	 *
	 * It is expected that the object is unusable after this is called.
	 */
	protected function destroyCycles() : void{
		$this->lastDamageCause = null;
	}

	/**
	 * @param Player[]|null      $targets
	 * @param MetadataProperty[] $data Properly formatted entity data, defaults to everything
	 *
	 * @phpstan-param array<int, MetadataProperty> $data
	 */
	public function sendData(?array $targets, ?array $data = null) : void{
		$targets = $targets ?? $this->hasSpawned;
		$data = $data ?? $this->getAllNetworkData();

		NetworkBroadcastUtils::broadcastEntityEvent($targets, fn(EntityEventBroadcaster $broadcaster, array $recipients) => $broadcaster->syncActorData($recipients, $this, $data));
	}

	/**
	 * @return MetadataProperty[]
	 * @phpstan-return array<int, MetadataProperty>
	 */
	final protected function getDirtyNetworkData() : array{
		if($this->networkPropertiesDirty){
			$this->syncNetworkData($this->networkProperties);
			$this->networkPropertiesDirty = false;
		}
		return $this->networkProperties->getDirty();
	}

	/**
	 * @return MetadataProperty[]
	 * @phpstan-return array<int, MetadataProperty>
	 */
	final protected function getAllNetworkData() : array{
		if($this->networkPropertiesDirty){
			$this->syncNetworkData($this->networkProperties);
			$this->networkPropertiesDirty = false;
		}
		return $this->networkProperties->getAll();
	}

	protected function syncNetworkData(EntityMetadataCollection $properties) : void{
		$properties->setByte(EntityMetadataProperties::ALWAYS_SHOW_NAMETAG, $this->alwaysShowNameTag ? 1 : 0);
		$properties->setFloat(EntityMetadataProperties::BOUNDING_BOX_HEIGHT, $this->size->getHeight() / $this->scale);
		$properties->setFloat(EntityMetadataProperties::BOUNDING_BOX_WIDTH, $this->size->getWidth() / $this->scale);
		$properties->setFloat(EntityMetadataProperties::SCALE, $this->scale);
		$properties->setLong(EntityMetadataProperties::LEAD_HOLDER_EID, -1);
		$properties->setLong(EntityMetadataProperties::OWNER_EID, $this->ownerId ?? -1);
		$properties->setLong(EntityMetadataProperties::TARGET_EID, $this->targetId ?? 0);
		$properties->setString(EntityMetadataProperties::NAMETAG, $this->nameTag);
		$properties->setString(EntityMetadataProperties::SCORE_TAG, $this->scoreTag);
		$properties->setByte(EntityMetadataProperties::COLOR, 0);

		$properties->setGenericFlag(EntityMetadataFlags::AFFECTED_BY_GRAVITY, $this->gravityEnabled);
		$properties->setGenericFlag(EntityMetadataFlags::CAN_CLIMB, $this->canClimb);
		$properties->setGenericFlag(EntityMetadataFlags::CAN_SHOW_NAMETAG, $this->nameTagVisible);
		$properties->setGenericFlag(EntityMetadataFlags::HAS_COLLISION, true);
		$properties->setGenericFlag(EntityMetadataFlags::NO_AI, $this->noClientPredictions);
		$properties->setGenericFlag(EntityMetadataFlags::INVISIBLE, $this->invisible);
		$properties->setGenericFlag(EntityMetadataFlags::SILENT, $this->silent);
		$properties->setGenericFlag(EntityMetadataFlags::ONFIRE, $this->isOnFire());
		$properties->setGenericFlag(EntityMetadataFlags::WALLCLIMBING, $this->canClimbWalls);
	}

	/**
	 * @param Player[]|null $targets
	 */
	public function broadcastAnimation(Animation $animation, ?array $targets = null) : void{
		$targets ??= $this->getViewers();
		if(count($targets) > 0){
			NetworkBroadcastUtils::broadcastPackets($targets, $animation->encode());
		}
	}

	/**
	 * Broadcasts a sound caused by the entity. If the entity is considered "silent", the sound will be dropped.
	 * @param Player[]|null $targets
	 */
	public function broadcastSound(Sound $sound, ?array $targets = null) : void{
		if(!$this->silent && !$this->isClosed()){
			$this->getWorld()->addSound($this->getPosition(), $sound, $targets ?? ($this->drawn ? $this->getViewers() : null));
		}
	}

	/** an attribute Falcon keeps for one of its own, by its game name: [value, min, max, default] */
	protected function attribute(string $name) : ?array{
		$handle = $this->getEntityHandle();
		return $handle === 0 ? null : falcon_entity_attribute($handle, $name);
	}

	protected function setAttribute(string $name, float $value) : void{
		$handle = $this->getEntityHandle();
		if($handle !== 0){
			falcon_entity_set_attribute($handle, $name, $value);
		}
	}

	public function __toString() : string{
		return (new \ReflectionClass($this))->getShortName() . "(" . $this->getId() . ")";
	}
}
