<?php

declare(strict_types=1);

namespace pocketmine\world\format;

use pocketmine\block\Block;
use pocketmine\world\World;

/** A palette for a detached subchunk or a live view of one of Falcon's block layers. */
final class PalettedBlockArray{
    /** @var array<int, int> */
    private array $blocks = [];
    private ?World $world = null;
    /** @var array{int, int, int, int} */
    private array $origin;

    public function __construct(private int $fillEntry){
    }

    /** @internal */
    public static function forBlockLayer(World $world, int $x, int $y, int $z, int $layer, int $empty) : self{
        $view = new self($empty);
        $view->world = $world;
        $view->origin = [$x, $y, $z, $layer];
        return $view;
    }

    public function get(int $x, int $y, int $z) : int{
        $x &= 15;
        $y &= 15;
        $z &= 15;
        if($this->world === null){
            return $this->blocks[($x << 8) | ($z << 4) | $y] ?? $this->fillEntry;
        }
        [$blockX, $blockY, $blockZ, $layer] = $this->origin;
        if($layer === 0){
            return $this->world->getBlockAt($blockX + $x, $blockY + $y, $blockZ + $z)->getStateId();
        }
        $state = falcon_level_get_block_layer($this->world->getHandle(), $blockX + $x, $blockY + $y, $blockZ + $z, $layer);
        return $state === null ? $this->fillEntry : Block::fromFalcon($state[0], $state[1])->getStateId();
    }

    public function set(int $x, int $y, int $z, int $val) : void{
        $x &= 15;
        $y &= 15;
        $z &= 15;
        if($this->world === null){
            $index = ($x << 8) | ($z << 4) | $y;
            if($val === $this->fillEntry){
                unset($this->blocks[$index]);
            }else{
                $this->blocks[$index] = $val;
            }
            return;
        }
        $state = Block::stateOf($val);
        if($state === null){
            throw new \InvalidArgumentException("Unknown block state $val");
        }
        [$blockX, $blockY, $blockZ, $layer] = $this->origin;
        if($layer === 0){
            $this->world->setBlockAt($blockX + $x, $blockY + $y, $blockZ + $z, Block::fromFalcon($state[0], $state[1]));
        }elseif(!falcon_level_set_block_layer($this->world->getHandle(), $blockX + $x, $blockY + $y, $blockZ + $z,
            $layer, $state[0], $state[1])){
            throw new \LogicException('This block layer is unavailable');
        }
    }

    /** @return list<int> */
    public function getPalette() : array{
        if($this->world === null){
            $palette = count($this->blocks) < 4096 ? [$this->fillEntry => true] : [];
            foreach($this->blocks as $block){
                $palette[$block] = true;
            }
        }else{
            $palette = [];
            for($index = 0; $index < 4096; ++$index){
                $palette[$this->get($index >> 8, $index, $index >> 4)] = true;
            }
        }
        return array_keys($palette);
    }

    public function getBitsPerBlock() : int{
        $count = count($this->getPalette());
        foreach([0, 1, 2, 3, 4, 5, 6, 8, 16] as $bits){
            if($count <= (1 << $bits)){
                return $bits;
            }
        }
        return 16;
    }

    public function collectGarbage(bool $force = false) : void{
        // Entries matching the fill value are removed on every write.
    }
}
