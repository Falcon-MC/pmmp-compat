<?php

declare(strict_types=1);

namespace pmmpcompat;

use pocketmine\resourcepacks\ResourcePack;

/** @internal The pack snapshot Falcon supplied for this connection. */
final class OfferedResourcePack implements ResourcePack{
    public function __construct(
        private string $id,
        private string $name,
        private string $version,
        private string $sha256,
        private int $size,
        private string $archive,
    ){}

    public function getPackId() : string{
        return $this->id;
    }

    public function getPackName() : string{
        return $this->name;
    }

    public function getPackVersion() : string{
        return $this->version;
    }

    public function getPackSize() : int{
        return $this->size;
    }

    public function getSha256() : string{
        return $this->sha256;
    }

    public function getPackChunk(int $start, int $length) : string{
        if($start < 0 || $length < 0 || $start > strlen($this->archive)){
            throw new \InvalidArgumentException('Resource pack chunk is out of bounds');
        }
        return substr($this->archive, $start, $length);
    }
}
