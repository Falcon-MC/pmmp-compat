<?php

declare(strict_types=1);

namespace shopkeeper;

use pmmpcompat\Packets;
use pocketmine\block\VanillaBlocks;
use pocketmine\event\inventory\InventoryCloseEvent;
use pocketmine\event\inventory\InventoryOpenEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\inventory\Inventory;
use pocketmine\item\enchantment\EnchantmentInstance;
use pocketmine\item\enchantment\StringToEnchantmentParser;
use pocketmine\item\enchantment\VanillaEnchantments;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\network\mcpe\protocol\ContainerOpenPacket;
use pocketmine\network\mcpe\protocol\types\BlockPosition;
use pocketmine\network\mcpe\protocol\types\inventory\WindowTypes;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\ClosureTask;

// enchantments, the inventories a player wears and keeps, and a menu window as InvMenu opens one
final class Main extends PluginBase implements Listener{
    protected function onEnable() : void{
        $logger = $this->getLogger();
        $this->getServer()->getPluginManager()->registerEvents($this, $this);

        $sword = VanillaItems::DIAMOND_SWORD()->addEnchantment(new EnchantmentInstance(VanillaEnchantments::SHARPNESS(), 3));
        $back = Item::fromFalcon(falcon_item_normalise($sword->toFalcon()));
        $logger->info("fixture: Shopkeeper sword keeps sharpness " . $back->getEnchantmentLevel(VanillaEnchantments::SHARPNESS())
            . ", parser " . (StringToEnchantmentParser::getInstance()->parse("sharpness") === VanillaEnchantments::SHARPNESS() ? "agrees" : "differs"));
        $chest = TypeConverter::getInstance()->getBlockTranslator()->internalIdToNetworkId(VanillaBlocks::CHEST()->getStateId());
        $logger->info("fixture: Shopkeeper chest network id " . ($chest !== 0 ? "known" : "missing"));
    }

    public function onJoin(PlayerJoinEvent $event) : void{
        $player = $event->getPlayer();
        $player->getArmorInventory()->setHelmet(VanillaItems::DIAMOND_HELMET());
        $player->getOffHandInventory()->setItem(0, VanillaItems::ARROW()->setCount(16));
        $player->getEnderInventory()->setItem(0, VanillaItems::GOLD_INGOT()->setCount(3));
        $this->getLogger()->info("fixture: Shopkeeper dressed " . $player->getName() . ": " . $player->getArmorInventory()->getHelmet()->getName()
            . ", " . $player->getOffHandInventory()->getItem(0)->getCount() . " arrows, " . $player->getEnderInventory()->getItem(0)->getCount() . " gold in the ender chest");

        $manager = $player->getNetworkSession()->getInvManager();
        $manager?->getContainerOpenCallbacks()->add(function(int $id, Inventory $inventory) use ($player) : ?array{
            if(!$inventory instanceof Menu){
                return null;
            }
            $position = $player->getPosition()->floor()->add(0, 3, 0);
            return [ContainerOpenPacket::blockInv($id, WindowTypes::CONTAINER, BlockPosition::fromVector3($position))];
        });
        $menu = new Menu(27);
        $menu->setItem(4, VanillaItems::EMERALD()->setCount(2));
        $opened = $player->setCurrentWindow($menu);
        $this->getLogger()->info("fixture: Shopkeeper menu " . ($opened && $manager?->getWindowId($menu) !== null ? "opened" : "refused"));
        $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($player, $menu) : void{
            $this->getLogger()->info("fixture: Shopkeeper menu holds " . $menu->getItem(4)->getCount() . " emeralds before closing");
            $player->removeCurrentWindow();
        }), 2);
    }

    public function onOpen(InventoryOpenEvent $event) : void{
        $this->getLogger()->info("fixture: Shopkeeper saw " . (new \ReflectionClass($event->getInventory()))->getShortName() . " open");
    }

    public function onClose(InventoryCloseEvent $event) : void{
        $this->getLogger()->info("fixture: Shopkeeper saw " . (new \ReflectionClass($event->getInventory()))->getShortName() . " close");
    }
}
