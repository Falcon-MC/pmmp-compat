<?php

declare(strict_types=1);

namespace pocketmine\entity;

use pocketmine\world\sound\Sound;

/** A player or an entity shaped like one: food, experience and a skin, which Falcon keeps */
abstract class Human extends Living{
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

    private ?HungerManager $hungerManager = null;
    private ?ExperienceManager $xpManager = null;
    private ?Skin $skin = null;

    public function getHungerManager() : HungerManager{
        return $this->hungerManager ??= new HungerManager($this);
    }

    public function getXpManager() : ExperienceManager{
        return $this->xpManager ??= new ExperienceManager($this);
    }

    public function canEat() : bool{
        return $this->getHungerManager()->isHungry();
    }

    /** the player's skin as Falcon has it; a 64x64 blank one when Falcon has none */
    public function getSkin() : Skin{
        if($this instanceof \pocketmine\player\Player && $this->isOnline()){
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

    public function setSkin(Skin $skin) : void{
        $this->skin = $skin;
        if($this instanceof \pocketmine\player\Player && $this->isOnline()){
            $data = $skin->getSkinData();
            [$width, $height] = self::SIZES[strlen($data)] ?? [64, 64];
            falcon_player_set_skin($this->getHandle(), $skin->getSkinId(), $data, $width, $height);
        }
    }

    /**
     * Falcon sends the skin to everyone when it is set.
     * @param \pocketmine\player\Player[]|null $targets
     */
    public function sendSkin(?array $targets = null) : void{
    }

    public function getXpDropAmount() : int{
        return min(100, 7 * $this->getXpManager()->getXpLevel());
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
}
