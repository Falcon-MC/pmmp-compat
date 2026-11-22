<?php

declare(strict_types=1);

namespace pocketmine\inventory;

use pocketmine\item\Item;
use pocketmine\utils\ObjectSet;
use pocketmine\utils\SingletonTrait;

/**
 * The items of the creative menu, as PocketMine-MP keeps them for plugins.
 *
 * Falcon sends its own creative menu, its custom items in it: what plugins add here is kept for the plugins that
 * read it back.
 */
final class CreativeInventory{
    use SingletonTrait;

    /** @var Item[] */
    private array $creative = [];
    /** @phpstan-var ObjectSet<\Closure() : void> */
    private ObjectSet $contentChangedCallbacks;

    private function __construct(){
        $this->contentChangedCallbacks = new ObjectSet();
    }

    public function clear() : void{
        $this->creative = [];
        $this->onContentChange();
    }

    /** @return Item[] */
    public function getAll() : array{
        return array_map(static fn(Item $item) => clone $item, $this->creative);
    }

    public function getItem(int $index) : ?Item{
        return isset($this->creative[$index]) ? clone $this->creative[$index] : null;
    }

    public function getItemIndex(Item $item) : int{
        foreach($this->creative as $i => $known){
            if($item->equals($known, true, false)){
                return $i;
            }
        }
        return -1;
    }

    public function add(Item $item, mixed $category = null, mixed $group = null) : void{
        $this->creative[] = clone $item;
        $this->onContentChange();
    }

    public function remove(Item $item) : void{
        $index = $this->getItemIndex($item);
        if($index !== -1){
            unset($this->creative[$index]);
            $this->creative = array_values($this->creative);
            $this->onContentChange();
        }
    }

    public function contains(Item $item) : bool{
        return $this->getItemIndex($item) !== -1;
    }

    /** @phpstan-return ObjectSet<\Closure() : void> */
    public function getContentChangedCallbacks() : ObjectSet{
        return $this->contentChangedCallbacks;
    }

    private function onContentChange() : void{
        foreach($this->contentChangedCallbacks as $callback){
            $callback();
        }
    }
}
