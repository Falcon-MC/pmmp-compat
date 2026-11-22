<?php

declare(strict_types=1);

namespace pocketmine\block\tile;

use pocketmine\block\utils\SignText;

/** A sign's text, both sides, which Falcon keeps */
class Sign extends Spawnable{
    public const TAG_TEXT_BLOB = "Text";
    public const TAG_TEXT_LINE = "Text%d";
    public const TAG_TEXT_COLOR = "SignTextColor";
    public const TAG_GLOWING_TEXT = "IgnoreLighting";
    public const TAG_WAXED = "IsWaxed";
    public const TAG_FRONT_TEXT = "FrontText";
    public const TAG_BACK_TEXT = "BackText";

    private const FRONT = 0;
    private const BACK = 1;

    private ?int $editorEntityRuntimeId = null;

    private function read(int $side) : SignText{
        $pos = $this->position;
        $text = falcon_level_sign_text($pos->getWorld()->getHandle(), $pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ(), $side);
        return new SignText($text === null || $text === "" ? [] : explode("\n", $text));
    }

    private function write(int $side, SignText $text) : void{
        $pos = $this->position;
        falcon_level_set_sign_text($pos->getWorld()->getHandle(), $pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ(),
            $side, implode("\n", $text->getLines()));
    }

    public function getText() : SignText{
        return $this->read(self::FRONT);
    }

    public function setText(SignText $text) : void{
        $this->write(self::FRONT, $text);
    }

    public function getBackText() : SignText{
        return $this->read(self::BACK);
    }

    public function setBackText(SignText $text) : void{
        $this->write(self::BACK, $text);
    }

    public function isWaxed() : bool{
        return false;
    }

    public function setWaxed(bool $waxed) : void{
    }

    public function getEditorEntityRuntimeId() : ?int{
        return $this->editorEntityRuntimeId;
    }

    public function setEditorEntityRuntimeId(?int $editorEntityRuntimeId) : void{
        $this->editorEntityRuntimeId = $editorEntityRuntimeId;
    }
}
