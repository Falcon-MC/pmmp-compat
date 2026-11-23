<?php

declare(strict_types=1);

namespace pocketmine\block;

use pocketmine\block\utils\SignText;

/** A standing, wall or hanging sign; the text a SignChangeEvent carries is the one being written */
class BaseSign extends Block{
    protected SignText $text;

    /**
     * @param array<string, bool|int|string> $states
     */
    public function __construct(BlockIdentifier|string $identifier, array|string $states = [], string|BlockTypeInfo $fallbackName = ""){
        parent::__construct($identifier, $states, $fallbackName);
        $this->text = new SignText();
    }

    public function getText() : SignText{
        return $this->text;
    }

    /** @return $this */
    public function setText(SignText $text) : self{
        $this->text = $text;
        return $this;
    }
}
