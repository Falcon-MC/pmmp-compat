<?php

declare(strict_types=1);

namespace pocketmine\data\bedrock\block;

use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\Tag;

/** A block state as the game saves it: its name, its states and the version of the game that wrote it */
final class BlockStateData{
    public const CURRENT_VERSION = (1 << 24) | (26 << 16) | (60 << 8);

    public const TAG_NAME = "name";
    public const TAG_STATES = "states";
    public const TAG_VERSION = "version";

    /**
     * @param Tag[] $states
     */
    public function __construct(
        private string $name,
        private array $states,
        private int $version
    ){}

    /** @param Tag[] $states */
    public static function current(string $name, array $states) : self{
        return new self($name, $states, self::CURRENT_VERSION);
    }

    public function getName() : string{
        return $this->name;
    }

    /** @return Tag[] */
    public function getStates() : array{
        return $this->states;
    }

    public function getState(string $name) : ?Tag{
        return $this->states[$name] ?? null;
    }

    public function getVersion() : int{
        return $this->version;
    }

    public function getVersionAsString() : string{
        return sprintf("%d.%d.%d.%d", ($this->version >> 24) & 0xff, ($this->version >> 16) & 0xff,
            ($this->version >> 8) & 0xff, $this->version & 0xff);
    }

    public static function fromNbt(CompoundTag $nbt) : self{
        $states = $nbt->getCompoundTag(self::TAG_STATES);
        return new self($nbt->getString(self::TAG_NAME), $states?->getValue() ?? [], $nbt->getInt(self::TAG_VERSION, 0));
    }

    public function toNbt() : CompoundTag{
        $states = CompoundTag::create();
        foreach($this->states as $name => $tag){
            $states->setTag($name, $tag);
        }
        return CompoundTag::create()
            ->setString(self::TAG_NAME, $this->name)
            ->setInt(self::TAG_VERSION, $this->version)
            ->setTag(self::TAG_STATES, $states);
    }

    public function equals(self $that) : bool{
        return $this->name === $that->name && $this->toNbt()->equals($that->toNbt());
    }

    /** @internal Falcon's states as JSON */
    public function toFalconStates() : string{
        $states = [];
        foreach($this->states as $name => $tag){
            $states[$name] = $tag->getValue();
        }
        return count($states) === 0 ? "{}" : (string) json_encode($states);
    }

    /** @internal */
    public static function fromFalcon(string $name, string $statesJson) : self{
        $states = [];
        foreach(json_decode($statesJson, true) ?: [] as $key => $value){
            $states[$key] = match(true){
                is_bool($value) => new \pocketmine\nbt\tag\ByteTag($value ? 1 : 0),
                is_int($value) => new \pocketmine\nbt\tag\IntTag($value),
                default => new \pocketmine\nbt\tag\StringTag((string) $value),
            };
        }
        return self::current($name, $states);
    }
}
