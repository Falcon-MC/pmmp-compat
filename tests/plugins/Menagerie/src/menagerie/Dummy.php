<?php

declare(strict_types=1);

namespace menagerie;

use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Living;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;

// a mob a plugin defines, as Slapper's or MiniBosses' are: counts its ticks and the hits that reach it
class Dummy extends Living{
    public int $ticks = 0;
    /** @var int[] the causes of the hits its attack() saw */
    public array $hits = [];
    public string $mood = "";

    public static function getNetworkTypeId() : string{
        return EntityIds::ZOMBIE;
    }

    protected function getInitialSizeInfo() : EntitySizeInfo{
        return new EntitySizeInfo(1.95, 0.6);
    }

    public function getName() : string{
        return "Dummy";
    }

    protected function initEntity(CompoundTag $nbt) : void{
        parent::initEntity($nbt);
        $this->mood = $nbt->getString("Mood", "calm");
        $this->setHasGravity(false);
    }

    public function saveNBT() : CompoundTag{
        return parent::saveNBT()->setString("Mood", $this->mood);
    }

    protected function entityBaseTick(int $tickDiff = 1) : bool{
        $this->ticks += $tickDiff;
        return parent::entityBaseTick($tickDiff) || true;
    }

    public function attack(EntityDamageEvent $source) : void{
        $this->hits[] = $source->getCause();
        parent::attack($source);
    }
}
