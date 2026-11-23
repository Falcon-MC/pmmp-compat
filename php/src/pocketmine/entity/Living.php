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

use pocketmine\entity\animation\DeathAnimation;
use pocketmine\entity\animation\HurtAnimation;
use pocketmine\entity\animation\RespawnAnimation;
use pocketmine\entity\effect\EffectManager;
use pocketmine\entity\effect\VanillaEffects;
use pocketmine\event\entity\EntityDamageByChildEntityEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntityDeathEvent;
use pocketmine\event\entity\EntityRegainHealthEvent;
use pocketmine\inventory\ArmorInventory;
use pocketmine\inventory\CallbackInventoryListener;
use pocketmine\item\Item;
use pocketmine\math\Vector3;
use pocketmine\math\VoxelRayTrace;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\FloatTag;
use pocketmine\nbt\tag\ShortTag;
use pocketmine\network\mcpe\EntityEventBroadcaster;
use pocketmine\network\mcpe\NetworkBroadcastUtils;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\player\Player;
use function array_shift;
use function atan2;
use function ceil;
use function count;
use function max;
use function min;
use function mt_getrandmax;
use function mt_rand;
use function sqrt;
use const M_PI;

/**
 * An entity with health that can die: mobs and players. Falcon's own read their health, armour and attributes from
 * Falcon; a plugin's own keep them in PHP, as on PocketMine-MP.
 */
abstract class Living extends Entity{
	public const DEFAULT_BREATH_TICKS = 300;

	public const DEFAULT_KNOCKBACK_FORCE = 0.4;
	public const DEFAULT_KNOCKBACK_VERTICAL_LIMIT = 0.4;

	private const TAG_LEGACY_HEALTH = "HealF"; //TAG_Float
	private const TAG_HEALTH = "Health"; //TAG_Float
	private const TAG_BREATH_TICKS = "Air"; //TAG_Short

	private const ATTRIBUTE_ABSORPTION = "minecraft:absorption";
	private const ATTRIBUTE_MOVEMENT = "minecraft:movement";

	protected int $attackTime = 0;

	public int $deadTicks = 0;
	protected int $maxDeadTicks = 25;

	protected float $jumpVelocity = 0.42;

	protected ?EffectManager $effectManager = null;

	protected ArmorInventory $armorInventory;

	protected bool $breathing = true;
	protected int $breathTicks = self::DEFAULT_BREATH_TICKS;
	protected int $maxBreathTicks = self::DEFAULT_BREATH_TICKS;

	protected Attribute $healthAttr;
	protected Attribute $absorptionAttr;
	protected Attribute $knockbackResistanceAttr;
	protected Attribute $moveSpeedAttr;

	protected bool $sprinting = false;
	protected bool $sneaking = false;
	protected bool $gliding = false;
	protected bool $swimming = false;

	protected function getInitialDragMultiplier() : float{ return 0.02; }

	protected function getInitialGravity() : float{ return 0.08; }

	protected function getInitialSizeInfo() : EntitySizeInfo{
		return new EntitySizeInfo(1.8, 0.6);
	}

	public function getName() : string{
		return (new \ReflectionClass($this))->getShortName();
	}

	public function canBeRenamed() : bool{
		return true;
	}

	protected function initEntity(CompoundTag $nbt) : void{
		parent::initEntity($nbt);

		$this->effectManager = new EffectManager($this);
		$this->effectManager->getEffectAddHooks()->add(function() : void{ $this->networkPropertiesDirty = true; });
		$this->effectManager->getEffectRemoveHooks()->add(function() : void{ $this->networkPropertiesDirty = true; });

		$this->armorInventory = new ArmorInventory($this);
		//TODO: load/save armor inventory contents
		$this->armorInventory->getListeners()->add(CallbackInventoryListener::onAnyChange(fn() => NetworkBroadcastUtils::broadcastEntityEvent(
			$this->getViewers(),
			fn(EntityEventBroadcaster $broadcaster, array $recipients) => $broadcaster->onMobArmorChange($recipients, $this)
		)));

		$health = $this->getMaxHealth();

		if(($healFTag = $nbt->getTag(self::TAG_LEGACY_HEALTH)) instanceof FloatTag){
			$health = $healFTag->getValue();
		}elseif(($healthTag = $nbt->getTag(self::TAG_HEALTH)) instanceof ShortTag){
			$health = $healthTag->getValue(); //Older versions of PocketMine-MP incorrectly saved this as a short instead of a float
		}elseif($healthTag instanceof FloatTag){
			$health = $healthTag->getValue();
		}

		$this->setHealth($health);

		$this->setAirSupplyTicks($nbt->getShort(self::TAG_BREATH_TICKS, self::DEFAULT_BREATH_TICKS));
	}

	protected function addAttributes() : void{
		$this->attributeMap->add($this->healthAttr = AttributeFactory::getInstance()->mustGet(Attribute::HEALTH));
		$this->attributeMap->add(AttributeFactory::getInstance()->mustGet(Attribute::FOLLOW_RANGE));
		$this->attributeMap->add($this->knockbackResistanceAttr = AttributeFactory::getInstance()->mustGet(Attribute::KNOCKBACK_RESISTANCE));
		$this->attributeMap->add($this->moveSpeedAttr = AttributeFactory::getInstance()->mustGet(Attribute::MOVEMENT_SPEED));
		$this->attributeMap->add(AttributeFactory::getInstance()->mustGet(Attribute::ATTACK_DAMAGE));
		$this->attributeMap->add($this->absorptionAttr = AttributeFactory::getInstance()->mustGet(Attribute::ABSORPTION));
	}

	public function getDisplayName() : string{
		return $this->nameTag !== "" ? $this->nameTag : $this->getName();
	}

	public function getEyeHeight() : float{
		return $this->isDrawnByPlugin() ? parent::getEyeHeight() : 1.62;
	}

	public function setHealth(float $amount) : void{
		if(!$this->isDrawnByPlugin()){
			parent::setHealth($amount);
			return;
		}
		$wasAlive = $this->isAlive();
		parent::setHealth($amount);
		$this->healthAttr->setValue(ceil($this->getHealth()), true);
		if($this->isAlive() && !$wasAlive){
			$this->broadcastAnimation(new RespawnAnimation($this));
		}
	}

	public function getMaxHealth() : int{
		return $this->isDrawnByPlugin() ? (int) $this->healthAttr->getMaxValue() : parent::getMaxHealth();
	}

	public function setMaxHealth(int $amount) : void{
		if(!$this->isDrawnByPlugin()){
			$this->setAttribute("minecraft:health", (float) $amount);
			return;
		}
		$this->healthAttr->setMaxValue($amount)->setDefaultValue($amount);
	}

	public function getAbsorption() : float{
		if(!$this->isDrawnByPlugin()){
			return $this->attribute(self::ATTRIBUTE_ABSORPTION)[0] ?? 0.0;
		}
		return $this->absorptionAttr->getValue();
	}

	public function setAbsorption(float $absorption) : void{
		if(!$this->isDrawnByPlugin()){
			$this->setAttribute(self::ATTRIBUTE_ABSORPTION, $absorption);
			return;
		}
		$this->absorptionAttr->setValue($absorption);
	}

	public function getSneakOffset() : float{
		return 0.0;
	}

	public function isSneaking() : bool{
		return $this->sneaking;
	}

	public function setSneaking(bool $value = true) : void{
		$this->sneaking = $value;
		$this->networkPropertiesDirty = true;
		$this->recalculateSize();
	}

	public function isSprinting() : bool{
		return $this->sprinting;
	}

	public function setSprinting(bool $value = true) : void{
		if($value !== $this->isSprinting()){
			$this->sprinting = $value;
			$this->networkPropertiesDirty = true;
			$moveSpeed = $this->getMovementSpeed();
			$this->setMovementSpeed($value ? ($moveSpeed * 1.3) : ($moveSpeed / 1.3));
			if($this->isDrawnByPlugin()){
				$this->moveSpeedAttr->markSynchronized(false); //TODO: reevaluate this hack
			}
		}
	}

	public function isGliding() : bool{
		return $this->gliding;
	}

	public function setGliding(bool $value = true) : void{
		$this->gliding = $value;
		$this->networkPropertiesDirty = true;
		$this->recalculateSize();
	}

	public function isSwimming() : bool{
		return $this->swimming;
	}

	public function setSwimming(bool $value = true) : void{
		$this->swimming = $value;
		$this->networkPropertiesDirty = true;
		$this->recalculateSize();
	}

	private function recalculateSize() : void{
		if(!$this->isDrawnByPlugin()){
			return;
		}
		$size = $this->getInitialSizeInfo();
		if($this->isSwimming() || $this->isGliding()){
			$width = $size->getWidth();
			$this->setSize((new EntitySizeInfo($width, $width, $width * 0.9))->scale($this->getScale()));
		}elseif($this->isSneaking()){
			$this->setSize((new EntitySizeInfo($size->getHeight() - $this->getSneakOffset(), $size->getWidth(), $size->getEyeHeight() - $this->getSneakOffset()))->scale($this->getScale()));
		}else{
			$this->setSize($size->scale($this->getScale()));
		}
	}

	public function getMovementSpeed() : float{
		if(!$this->isDrawnByPlugin()){
			return $this->attribute(self::ATTRIBUTE_MOVEMENT)[0] ?? 0.1;
		}
		return $this->moveSpeedAttr->getValue();
	}

	public function setMovementSpeed(float $v, bool $fit = false) : void{
		if(!$this->isDrawnByPlugin()){
			$this->setAttribute(self::ATTRIBUTE_MOVEMENT, $v);
			return;
		}
		$this->moveSpeedAttr->setValue($v, $fit);
	}

	public function saveNBT() : CompoundTag{
		$nbt = parent::saveNBT();
		$nbt->setFloat(self::TAG_HEALTH, $this->getHealth());

		$nbt->setShort(self::TAG_BREATH_TICKS, $this->getAirSupplyTicks());

		return $nbt;
	}

	public function getEffects() : EffectManager{
		return $this->effectManager ??= new EffectManager($this);
	}

	/**
	 * Causes consuming an item.
	 */
	public function consumeObject(Consumable $consumable) : bool{
		foreach($consumable->getAdditionalEffects() as $effect){
			$this->getEffects()->add($effect);
		}
		$consumable->onConsume($this);

		return true;
	}

	/** gives health back as PocketMine-MP does: through an event a listener can change or cancel */
	public function heal(EntityRegainHealthEvent $source) : void{
		$source->call();
		if($source->isCancelled()){
			return;
		}
		$this->setHealth(min($this->getMaxHealth(), $this->getHealth() + $source->getAmount()));
	}

	public function getJumpVelocity() : float{
		$boost = $this->getEffects()->get(VanillaEffects::JUMP_BOOST());
		return $this->jumpVelocity + ($boost !== null ? $boost->getEffectLevel() / 10 : 0);
	}

	public function jump() : void{
		if(!$this->isDrawnByPlugin()){
			$this->setMotion($this->getMotion()->withComponents(null, $this->getJumpVelocity(), null));
			return;
		}
		if($this->onGround){
			$this->motion = $this->motion->withComponents(null, $this->getJumpVelocity(), null); //Y motion should already be 0 if we're jumping from the ground.
		}
	}

	protected function calculateFallDamage(float $fallDistance) : float{
		$boost = $this->getEffects()->get(VanillaEffects::JUMP_BOOST());
		return ceil($fallDistance - 3 - ($boost !== null ? $boost->getEffectLevel() : 0));
	}

	protected function onHitGround() : ?float{
		$damage = $this->calculateFallDamage($this->fallDistance);
		if($damage > 0){
			$ev = new EntityDamageEvent($this, EntityDamageEvent::CAUSE_FALL, $damage);
			$this->attack($ev);
		}
		return null;
	}

	/** armour points: what the armour slots give */
	public function getArmorPoints() : int{
		if(!isset($this->armorInventory)){
			return 0;
		}
		$total = 0;
		foreach($this->armorInventory->getContents() as $item){
			$total += $item->getDefensePoints();
		}

		return $total;
	}

	public function getArmorInventory() : ArmorInventory{
		return $this->armorInventory ??= new ArmorInventory($this);
	}

	public function applyDamageModifiers(EntityDamageEvent $source) : void{
		if($this->lastDamageCause !== null && $this->attackTime > 0){
			if($this->lastDamageCause->getBaseDamage() >= $source->getBaseDamage()){
				$source->cancel();
			}
			$source->setModifier(-$this->lastDamageCause->getBaseDamage(), EntityDamageEvent::MODIFIER_PREVIOUS_DAMAGE_COOLDOWN);
		}
		if($source->canBeReducedByArmor()){
			//MCPE uses the same system as PC did pre-1.9
			$source->setModifier(-$source->getFinalDamage() * $this->getArmorPoints() * 0.04, EntityDamageEvent::MODIFIER_ARMOR);
		}

		$cause = $source->getCause();
		if(($resistance = $this->getEffects()->get(VanillaEffects::RESISTANCE())) !== null && $cause !== EntityDamageEvent::CAUSE_VOID && $cause !== EntityDamageEvent::CAUSE_SUICIDE){
			$source->setModifier(-$source->getFinalDamage() * min(1, 0.2 * $resistance->getEffectLevel()), EntityDamageEvent::MODIFIER_RESISTANCE);
		}

		$source->setModifier(-min($this->getAbsorption(), $source->getFinalDamage()), EntityDamageEvent::MODIFIER_ABSORPTION);
	}

	protected function applyPostDamageEffects(EntityDamageEvent $source) : void{
		$this->setAbsorption(max(0, $this->getAbsorption() + $source->getModifier(EntityDamageEvent::MODIFIER_ABSORPTION)));
	}

	public function attack(EntityDamageEvent $source) : void{
		if(!$this->isDrawnByPlugin()){
			parent::attack($source);
			return;
		}
		if($this->noDamageTicks > 0 && $source->getCause() !== EntityDamageEvent::CAUSE_SUICIDE){
			$source->cancel();
		}

		if($this->getEffects()->has(VanillaEffects::FIRE_RESISTANCE()) && (
				$source->getCause() === EntityDamageEvent::CAUSE_FIRE
				|| $source->getCause() === EntityDamageEvent::CAUSE_FIRE_TICK
				|| $source->getCause() === EntityDamageEvent::CAUSE_LAVA
			)
		){
			$source->cancel();
		}

		if($source->getCause() !== EntityDamageEvent::CAUSE_SUICIDE){
			$this->applyDamageModifiers($source);
		}

		parent::attack($source);

		if($source->isCancelled()){
			return;
		}

		if($this->attackTime <= 0){
			//this logic only applies if the entity was cold attacked

			$this->attackTime = $source->getAttackCooldown();

			if($source instanceof EntityDamageByChildEntityEvent){
				$e = $source->getChild();
				if($e !== null){
					$motion = $e->getMotion();
					$this->knockBack($motion->x, $motion->z, $source->getKnockBack(), $source->getVerticalKnockBackLimit());
				}
			}elseif($source instanceof EntityDamageByEntityEvent){
				$e = $source->getDamager();
				if($e !== null){
					$from = $e->getPosition();
					$deltaX = $this->location->x - $from->x;
					$deltaZ = $this->location->z - $from->z;
					$this->knockBack($deltaX, $deltaZ, $source->getKnockBack(), $source->getVerticalKnockBackLimit());
				}
			}

			if($this->isAlive()){
				$this->doHitAnimation();
			}
		}

		if($this->isAlive()){
			$this->applyPostDamageEffects($source);
		}
	}

	protected function doHitAnimation() : void{
		$this->broadcastAnimation(new HurtAnimation($this));
	}

	/** pushes the entity away, as a hit does */
	public function knockBack(float $x, float $z, float $force = self::DEFAULT_KNOCKBACK_FORCE, ?float $verticalLimit = self::DEFAULT_KNOCKBACK_VERTICAL_LIMIT) : void{
		$f = sqrt($x * $x + $z * $z);
		if($f <= 0){
			return;
		}
		$resistance = $this->isDrawnByPlugin() ? $this->knockbackResistanceAttr->getValue() : 0.0;
		if(mt_rand() / mt_getrandmax() > $resistance){
			$f = 1 / $f;

			$motion = $this->getMotion();
			$motionX = $motion->x / 2;
			$motionY = $motion->y / 2;
			$motionZ = $motion->z / 2;
			$motionX += $x * $f * $force;
			$motionY += $force;
			$motionZ += $z * $f * $force;

			$verticalLimit ??= $force;
			if($motionY > $verticalLimit){
				$motionY = $verticalLimit;
			}

			$this->setMotion(new Vector3($motionX, $motionY, $motionZ));
		}
	}

	protected function onDeath() : void{
		$ev = new EntityDeathEvent($this, $this->getDrops(), $this->getXpDropAmount());
		$ev->call();
		foreach($ev->getDrops() as $item){
			$this->getWorld()->dropItem($this->location, $item);
		}

		//TODO: check death conditions (must have been damaged by player < 5 seconds from death)
		if($ev->getXpDropAmount() > 0 && method_exists($this->getWorld(), "dropExperience")){
			$this->getWorld()->dropExperience($this->location, $ev->getXpDropAmount());
		}

		$this->startDeathAnimation();
	}

	protected function onDeathUpdate(int $tickDiff) : bool{
		if($this->deadTicks < $this->maxDeadTicks){
			$this->deadTicks += $tickDiff;
			if($this->deadTicks >= $this->maxDeadTicks){
				$this->endDeathAnimation();
			}
		}

		return $this->deadTicks >= $this->maxDeadTicks;
	}

	protected function startDeathAnimation() : void{
		$this->broadcastAnimation(new DeathAnimation($this));
	}

	protected function endDeathAnimation() : void{
		$this->despawnFromAll();
	}

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		$hasUpdate = parent::entityBaseTick($tickDiff);

		if($this->isAlive()){
			if($this->getEffects()->tick($tickDiff)){
				$hasUpdate = true;
			}

			if($this->isInsideOfSolid()){
				$hasUpdate = true;
				$ev = new EntityDamageEvent($this, EntityDamageEvent::CAUSE_SUFFOCATION, 1);
				$this->attack($ev);
			}

			if($this->doAirSupplyTick($tickDiff)){
				$hasUpdate = true;
			}
		}

		if($this->attackTime > 0){
			$this->attackTime -= $tickDiff;
		}

		return $hasUpdate;
	}

	/**
	 * Ticks the entity's air supply, consuming it when underwater and regenerating it when out of water.
	 */
	protected function doAirSupplyTick(int $tickDiff) : bool{
		$ticks = $this->getAirSupplyTicks();
		$oldTicks = $ticks;
		if(!$this->canBreathe()){
			$this->setBreathing(false);

			$ticks -= $tickDiff;
			if($ticks <= -20){
				$ticks = 0;
				$this->onAirExpired();
			}
		}elseif(!$this->isBreathing()){
			if($ticks < ($max = $this->getMaxAirSupplyTicks())){
				$ticks += $tickDiff * 5;
			}
			if($ticks >= $max){
				$ticks = $max;
				$this->setBreathing(true);
			}
		}

		if($ticks !== $oldTicks){
			$this->setAirSupplyTicks($ticks);
		}

		return $ticks !== $oldTicks;
	}

	public function canBreathe() : bool{
		if(!$this->isDrawnByPlugin()){
			return true;
		}
		return $this->getEffects()->has(VanillaEffects::WATER_BREATHING()) || !$this->isUnderwater();
	}

	public function isBreathing() : bool{
		return $this->breathing;
	}

	public function setBreathing(bool $value = true) : void{
		$this->breathing = $value;
		$this->networkPropertiesDirty = true;
	}

	public function getAirSupplyTicks() : int{
		return $this->breathTicks;
	}

	public function setAirSupplyTicks(int $ticks) : void{
		$this->breathTicks = $ticks;
		$this->networkPropertiesDirty = true;
	}

	public function getMaxAirSupplyTicks() : int{
		return $this->maxBreathTicks;
	}

	public function setMaxAirSupplyTicks(int $ticks) : void{
		$this->maxBreathTicks = $ticks;
		$this->networkPropertiesDirty = true;
	}

	public function onAirExpired() : void{
		$ev = new EntityDamageEvent($this, EntityDamageEvent::CAUSE_DROWNING, 2);
		$this->attack($ev);
	}

	/**
	 * @return Item[]
	 */
	public function getDrops() : array{
		return [];
	}

	public function getXpDropAmount() : int{
		return 0;
	}

	/**
	 * @param true[] $transparent
	 * @phpstan-param array<int, true> $transparent
	 *
	 * @return \pocketmine\block\Block[]
	 */
	public function getLineOfSight(int $maxDistance, int $maxLength = 0, array $transparent = []) : array{
		if($maxDistance > 120){
			$maxDistance = 120;
		}

		if(count($transparent) === 0){
			$transparent = null;
		}

		$blocks = [];
		$nextIndex = 0;

		foreach(VoxelRayTrace::inDirection($this->getEyePos(), $this->getDirectionVector(), $maxDistance) as $vector3){
			$block = $this->getWorld()->getBlockAt((int) $vector3->x, (int) $vector3->y, (int) $vector3->z);
			$blocks[$nextIndex++] = $block;

			if($maxLength !== 0 && count($blocks) > $maxLength){
				array_shift($blocks);
				--$nextIndex;
			}

			$id = $block->getTypeId();

			if($transparent === null){
				if($id !== \pocketmine\block\BlockTypeIds::AIR){
					break;
				}
			}else{
				if(!isset($transparent[$id])){
					break;
				}
			}
		}

		return $blocks;
	}

	/**
	 * @param true[] $transparent
	 * @phpstan-param array<int, true> $transparent
	 */
	public function getTargetBlock(int $maxDistance, array $transparent = []) : ?\pocketmine\block\Block{
		$line = $this->getLineOfSight($maxDistance, 1, $transparent);
		if(count($line) > 0){
			return array_shift($line);
		}

		return null;
	}

	/**
	 * Changes the entity's yaw and pitch to make it look at the specified Vector3 position. For mobs, this will cause
	 * their heads to turn.
	 */
	public function lookAt(Vector3 $target) : void{
		$location = $this->getLocation();
		$xDist = $target->x - $location->x;
		$zDist = $target->z - $location->z;

		$horizontal = sqrt($xDist ** 2 + $zDist ** 2);
		$vertical = $target->y - ($location->y + $this->getEyeHeight());
		$pitch = -atan2($vertical, $horizontal) / M_PI * 180; //negative is up, positive is down

		$yaw = atan2($zDist, $xDist) / M_PI * 180 - 90;
		if($yaw < 0){
			$yaw += 360.0;
		}

		$this->setRotation($yaw, $pitch);
	}

	protected function sendSpawnPacket(Player $player) : void{
		parent::sendSpawnPacket($player);

		$networkSession = $player->getNetworkSession();
		$networkSession->getEntityEventBroadcaster()->onMobArmorChange([$networkSession], $this);
	}

	protected function syncNetworkData(EntityMetadataCollection $properties) : void{
		parent::syncNetworkData($properties);

		$properties->setShort(EntityMetadataProperties::AIR, $this->breathTicks);
		$properties->setShort(EntityMetadataProperties::MAX_AIR, $this->maxBreathTicks);

		$properties->setGenericFlag(EntityMetadataFlags::BREATHING, $this->breathing);
		$properties->setGenericFlag(EntityMetadataFlags::SNEAKING, $this->sneaking);
		$properties->setGenericFlag(EntityMetadataFlags::SPRINTING, $this->sprinting);
		$properties->setGenericFlag(EntityMetadataFlags::GLIDING, $this->gliding);
		$properties->setGenericFlag(EntityMetadataFlags::SWIMMING, $this->swimming);
	}

	protected function onDispose() : void{
		if(isset($this->armorInventory)){
			$this->armorInventory->removeAllViewers();
		}
		parent::onDispose();
	}

	protected function destroyCycles() : void{
		$this->effectManager = null;
		parent::destroyCycles();
	}
}
