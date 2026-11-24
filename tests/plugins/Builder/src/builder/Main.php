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
use pocketmine\data\bedrock\EnchantmentIdMap;
use pocketmine\data\bedrock\EnchantmentIds;
use pocketmine\entity\Skin;
use pocketmine\item\enchantment\Enchantment;
use pocketmine\item\enchantment\EnchantmentInstance;
use pocketmine\item\enchantment\StringToEnchantmentParser;
use pocketmine\item\enchantment\VanillaEnchantments;
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
        $this->check("legacy Fortune registration", function() : bool{
            $parser = StringToEnchantmentParser::getInstance();
            $map = EnchantmentIdMap::getInstance();
            $default = VanillaEnchantments::FORTUNE();
            if($parser->parse("fortune") !== $default){
                return false;
            }

            $replacement = new Enchantment($default->getName(), $default->getRarity(), 0, 0, 3);
            $map->register(EnchantmentIds::FORTUNE, $replacement);
            try{
                $parser->register("minecraft:fortune", fn() => $replacement);
                $pickaxe = VanillaItems::DIAMOND_PICKAXE()->addEnchantment(new EnchantmentInstance($replacement, 3));
                $restored = Item::nbtDeserialize($pickaxe->nbtSerialize());
                $duplicateRejected = false;
                try{
                    $parser->register("fortune", fn() => $replacement);
                }catch(\InvalidArgumentException){
                    $duplicateRejected = true;
                }

                return $duplicateRejected && $parser->parse("fortune") === $replacement
                    && $restored->getEnchantmentLevel($replacement) === 3;
            }finally{
                $map->register(EnchantmentIds::FORTUNE, $default);
                $parser->override("fortune", fn() => $default);
            }
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
            $parsed = yaml_parse("null: unknown\ntrue: 'yes'");
            return $parsed[""] === "unknown" && $parsed[1] === "yes";
        });
        $this->check("legacy numeric ids", fn() => LegacyStringToItemParser::getInstance()->parse("276:2")->equals(VanillaItems::DIAMOND_SWORD(), false));
        $this->check("registry members", function() : bool{
            $reflection = new \ReflectionClass(VanillaItems::class);
            $members = $reflection->getStaticPropertyValue("members");
            $members["APPLE"] = VanillaItems::GOLDEN_APPLE();
            $reflection->setStaticPropertyValue("members", $members);
            $replaced = VanillaItems::APPLE()->equals(VanillaItems::GOLDEN_APPLE());
            $reflection->setStaticPropertyValue("members", []);
            return $replaced && !VanillaItems::APPLE()->equals(VanillaItems::GOLDEN_APPLE());
        });
        $this->check("old permission names", fn() => \pocketmine\permission\PermissionManager::getInstance()->getPermission("pocketmine.command.pardon") !== null);
        $this->check("zipped resource pack", function() : bool{
            if(PHP_OS_FAMILY === "Windows" && !class_exists(\ZipArchive::class)){
                $this->getLogger()->info("fixture: Builder zip extension unavailable on Windows");
                return true;
            }
            $path = $this->getDataFolder() . "pack.zip";
            @mkdir($this->getDataFolder());
            $zip = new \ZipArchive();
            $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
            $zip->addFromString("manifest.json", json_encode([
                "format_version" => 2,
                "header" => ["name" => "Sounds", "description" => "", "uuid" => "e4e7c4b3-2c39-4d2c-8a61-6a7b9b4d1c11", "version" => [1, 0, 0]],
                "modules" => [["type" => "resources", "uuid" => "a1b2c3d4-2c39-4d2c-8a61-6a7b9b4d1c12", "version" => [1, 0, 0]]],
            ]));
            $zip->close();
            $pack = new \pocketmine\resourcepacks\ZippedResourcePack($path);
            return $pack->getPackName() === "Sounds" && $pack->getPackVersion() === "1.0.0";
        });
        $this->check("wood and armour types", fn() => \pocketmine\block\utils\WoodType::OAK instanceof \UnitEnum && class_exists(\pocketmine\item\ArmorTypeInfo::class));
        $this->check("custom armour properties", function() : bool{
            $info = new \pocketmine\item\ArmorTypeInfo(4, 123, 1, 3, true);
            $armor = new \pocketmine\item\Armor(new \pocketmine\item\ItemIdentifier(\pocketmine\item\ItemTypeIds::newId()), "Builder Armour", $info);
            return $armor->getDefensePoints() === 4 && $armor->getMaxDurability() === 123 && $armor->getArmorSlot() === 1
                && $armor->getToughness() === 3 && $armor->isFireProof();
        });
        $this->getLogger()->info("fixture: Builder {$this->passed} of {$this->total} checks passed");
    }
}
