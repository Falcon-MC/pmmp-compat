<?php

declare(strict_types=1);

namespace pocketmine\entity;

use pocketmine\entity\utils\ExperienceUtils;
use pocketmine\event\player\PlayerExperienceChangeEvent;
use pocketmine\world\sound\XpCollectSound;
use pocketmine\world\sound\XpLevelUpSound;
use function max;

/**
 * A human's experience level and progress, which Falcon keeps.
 *
 * Changes go through PlayerExperienceChangeEvent first, as in PocketMine-MP.
 */
class ExperienceManager{
    private const PROGRESS = "minecraft:player.experience";

    private int $totalXp = 0;
    private bool $canAttractXpOrbs = true;
    private int $xpCooldown = 0;

    public function __construct(private Human $entity){
    }

    private function player() : int{
        return $this->entity instanceof \pocketmine\player\Player && $this->entity->isOnline() ? $this->entity->getHandle() : 0;
    }

    public function getXpLevel() : int{
        $player = $this->player();
        return $player === 0 ? 0 : falcon_player_xp_level($player);
    }

    public function setXpLevel(int $level) : bool{
        return $this->setXpAndProgress($level, null);
    }

    public function addXpLevels(int $amount, bool $playSound = true) : bool{
        $old = $this->getXpLevel();
        if($this->setXpLevel($old + $amount)){
            if($playSound){
                $new = $this->getXpLevel();
                if((int) ($new / 5) > (int) ($old / 5)){
                    $this->entity->broadcastSound(new XpLevelUpSound($new));
                }
            }
            return true;
        }
        return false;
    }

    public function subtractXpLevels(int $amount) : bool{
        return $this->addXpLevels(-$amount);
    }

    public function getXpProgress() : float{
        $handle = $this->entity->getEntityHandle();
        return $handle === 0 ? 0.0 : (falcon_entity_attribute($handle, self::PROGRESS)[0] ?? 0.0);
    }

    public function setXpProgress(float $progress) : bool{
        return $this->setXpAndProgress(null, $progress);
    }

    public function getRemainderXp() : int{
        return (int) (ExperienceUtils::getXpToCompleteLevel($this->getXpLevel()) * $this->getXpProgress());
    }

    public function getCurrentTotalXp() : int{
        return ExperienceUtils::getXpToReachLevel($this->getXpLevel()) + $this->getRemainderXp();
    }

    public function setCurrentTotalXp(int $amount) : bool{
        $newLevel = ExperienceUtils::getLevelFromXp($amount);
        $level = (int) $newLevel;
        return $this->setXpAndProgress($level, $newLevel - $level);
    }

    public function addXp(int $amount, bool $playSound = true) : bool{
        $this->totalXp += $amount;
        $oldLevel = $this->getXpLevel();
        $oldTotal = $this->getCurrentTotalXp();
        if($this->setCurrentTotalXp($oldTotal + $amount)){
            if($playSound){
                $newLevel = $this->getXpLevel();
                if((int) ($newLevel / 5) > (int) ($oldLevel / 5)){
                    $this->entity->broadcastSound(new XpLevelUpSound($newLevel));
                }elseif($this->getCurrentTotalXp() > $oldTotal){
                    $this->entity->broadcastSound(new XpCollectSound());
                }
            }
            return true;
        }
        return false;
    }

    public function subtractXp(int $amount) : bool{
        return $this->addXp(-$amount);
    }

    public function setXpAndProgress(?int $level, ?float $progress) : bool{
        $ev = new PlayerExperienceChangeEvent($this->entity, $this->getXpLevel(), $this->getXpProgress(), $level, $progress);
        $ev->call();
        if($ev->isCancelled()){
            return false;
        }
        $level = $ev->getNewLevel();
        $progress = $ev->getNewProgress();
        if($level !== null || $progress !== null){
            $this->setXpAndProgressNoEvent($level ?? $this->getXpLevel(), $progress ?? $this->getXpProgress());
        }
        return true;
    }

    public function setXpAndProgressNoEvent(int $level, float $progress) : void{
        $player = $this->player();
        if($player !== 0){
            falcon_player_set_xp_level($player, max(0, $level));
            falcon_entity_set_attribute($this->entity->getEntityHandle(), self::PROGRESS, max(0.0, min(1.0, $progress)));
        }
    }

    public function getLifetimeTotalXp() : int{
        return max($this->totalXp, $this->getCurrentTotalXp());
    }

    public function setLifetimeTotalXp(int $amount) : void{
        if($amount < 0){
            throw new \InvalidArgumentException("XP must be greater than 0");
        }
        $this->totalXp = $amount;
    }

    public function canPickupXp() : bool{
        return $this->xpCooldown === 0;
    }

    public function onPickupXp(int $xpValue) : void{
        $this->addXp($xpValue);
        $this->resetXpCooldown();
    }

    public function resetXpCooldown(int $value = 2) : void{
        $this->xpCooldown = $value;
    }

    public function tick(int $tickDiff = 1) : void{
        $this->xpCooldown = max(0, $this->xpCooldown - $tickDiff);
    }

    public function canAttractXpOrbs() : bool{
        return $this->canAttractXpOrbs;
    }

    public function setCanAttractXpOrbs(bool $v = true) : void{
        $this->canAttractXpOrbs = $v;
    }
}
