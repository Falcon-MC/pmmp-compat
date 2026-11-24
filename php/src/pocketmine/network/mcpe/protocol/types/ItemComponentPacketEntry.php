<?php

declare(strict_types=1);

namespace pocketmine\network\mcpe\protocol\types;

final class ItemComponentPacketEntry{
    public function __construct(
        public string $name,
        public CacheableNbt $componentNbt
    ){
    }
}
