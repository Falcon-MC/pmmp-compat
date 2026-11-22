<?php

declare(strict_types=1);

namespace builder;

use pocketmine\block\Block;
use pocketmine\block\BlockBreakInfo;
use pocketmine\block\BlockIdentifier;
use pocketmine\block\BlockTypeIds;
use pocketmine\block\BlockTypeInfo;
use pocketmine\block\RuntimeBlockStateRegistry;
use pocketmine\block\utils\DyeColor;
use pocketmine\block\VanillaBlocks;
use pocketmine\command\Command;
use pocketmine\entity\Skin;
use pocketmine\item\Item;
use pocketmine\item\ItemIdentifier;
use pocketmine\item\ItemTypeIds;
use pocketmine\item\LegacyStringToItemParser;
use pocketmine\item\StringToItemParser;
use pocketmine\item\VanillaItems;
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\network\mcpe\protocol\types\command\CommandEnum;
use pocketmine\network\mcpe\protocol\types\command\CommandParameter;
use pocketmine\plugin\PluginBase;
use pocketmine\world\format\io\GlobalBlockStateHandlers;
use pocketmine\world\format\io\GlobalItemDataHandlers;
use pocketmine\world\generator\GeneratorManager;
use pocketmine\world\World;

// what plugins build with: item and block types, saved items, enums, generators and the server's network
final class Main extends PluginBase{
    private int $passed = 0;
    private int $total = 0;

    private function check(string $what, \Closure $test) : void{
        ++$this->total;
        try{
            if($test()){
                ++$this->passed;
                return;
            }
            $this->getLogger()->error("fixture: Builder check failed: $what");
        }catch(\Throwable $e){
            $this->getLogger()->error("fixture: Builder check failed: $what: " . $e->getMessage());
        }
    }

    protected function onEnable() : void{
        $this->check("command enum", function() : bool{
            $parameter = CommandParameter::enum("mode", new CommandEnum("Mode", ["on", "off"]), 0);
            return $parameter->enum instanceof CommandEnum && $parameter->enum->getValues() === ["on", "off"];
        });
        $this->check("custom item", function() : bool{
            $item = new Item(new ItemIdentifier(ItemTypeIds::newId()), "Magic Wand");
            return $item->getName() === "Magic Wand" && $item->getTypeId() === $item->getIdentifier()->getTypeId();
        });
        $this->check("custom block", function() : bool{
            $id = BlockTypeIds::newId();
            $block = new Block(new BlockIdentifier($id), "Magic Block", new BlockTypeInfo(BlockBreakInfo::instant()));
            return $block->getName() === "Magic Block" && $block->getTypeId() === $id && $block->getTypeInfo()->getBreakInfo()->breaksInstantly();
        });
        $this->check("item nbt", function() : bool{
            $sword = VanillaItems::DIAMOND_SWORD()->setCustomName("Kit Sword");
            $sword->setDamage(3);
            $back = Item::nbtDeserialize($sword->nbtSerialize(4));
            return $back->equalsExact($sword) && $sword->nbtSerialize(4)->getByte("Slot") === 4;
        });
        $this->check("item data handlers", function() : bool{
            $stack = VanillaItems::GOLD_INGOT()->setCount(7);
            $data = GlobalItemDataHandlers::getSerializer()->serializeStack($stack);
            return GlobalItemDataHandlers::getDeserializer()->deserializeStack($data)->equalsExact($stack);
        });
        $this->check("block state handlers", function() : bool{
            $stone = VanillaBlocks::STONE();
            $data = GlobalBlockStateHandlers::getSerializer()->serialize($stone->getStateId());
            return $data->getName() === "minecraft:stone"
                && GlobalBlockStateHandlers::getDeserializer()->deserialize($data) === $stone->getStateId();
        });
        $this->check("legacy parser", function() : bool{
            $item = LegacyStringToItemParser::getInstance()->parse("diamond_sword:5");
            return $item->getDamage() === 5 && $item->equals(VanillaItems::DIAMOND_SWORD(), false);
        });
        $this->check("aliases", fn() => in_array("diamond_sword", StringToItemParser::getInstance()->lookupAliases(VanillaItems::DIAMOND_SWORD()), true));
        $this->check("state registry", function() : bool{
            $glass = VanillaBlocks::GLASS();
            return RuntimeBlockStateRegistry::getInstance()->fromStateId($glass->getStateId())->isSameState($glass);
        });
        $this->check("colours", fn() => VanillaBlocks::WOOL()->setColor(DyeColor::RED)->getColor() === DyeColor::RED);
        $this->check("generators", function() : bool{
            $generators = GeneratorManager::getInstance();
            $generators->addGenerator(VoidGenerator::class, "void", fn() => null);
            return $generators->getGenerator("void")?->getGeneratorClass() === VoidGenerator::class
                && in_array("normal", $generators->getGeneratorList(), true);
        });
        $this->check("block hash", function() : bool{
            World::getBlockXYZ(World::blockHash(-12, -60, 300), $x, $y, $z);
            return [$x, $y, $z] === [-12, -60, 300];
        });
        $this->check("loaded chunks", fn() => count($this->getServer()->getWorldManager()->getDefaultWorld()->getLoadedChunks()) > 0);
        $this->check("skin adapter", function() : bool{
            $skin = new Skin("Standard_Custom", str_repeat("\xff", 64 * 64 * 4));
            $adapter = TypeConverter::getInstance()->getSkinAdapter();
            return $adapter->fromSkinData($adapter->toSkinData($skin))->getSkinData() === $skin->getSkinData();
        });
        $this->check("network", fn() => count($this->getServer()->getNetwork()->getInterfaces()) === 0);
        $this->check("config group", fn() => $this->getServer()->getConfigGroup()->getPropertyInt("chunk-ticking.tick-radius", 3) === 3);
        $this->check("built-in commands", function() : bool{
            $tell = $this->getServer()->getCommandMap()->getCommand("tell");
            return $tell instanceof Command && $this->getServer()->getCommandMap()->getCommand("msg") === $tell
                && $this->getServer()->getCommandMap()->getCommand("nonsense") === null;
        });
        $this->check("yaml keys", function() : bool{
            $parsed = yaml_parse("null: unknown\ntrue: yes");
            return $parsed[""] === "unknown" && $parsed[1] === "yes";
        });
        $this->getLogger()->info("fixture: Builder {$this->passed} of {$this->total} checks passed");
    }
}
