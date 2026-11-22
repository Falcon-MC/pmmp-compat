<?php

declare(strict_types=1);

namespace courier;

use pocketmine\form\Form;
use pocketmine\player\Player;

final class Question implements Form{
    public function jsonSerialize() : array{
        return ["type" => "modal", "title" => "Courier", "content" => "Did it arrive?", "button1" => "Yes", "button2" => "No"];
    }

    public function handleResponse(Player $player, $data) : void{
    }
}
