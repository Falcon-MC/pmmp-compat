<?php

declare(strict_types=1);

namespace economyprobe;

use antbag\chatgames\Main as ChatGames;
use ClickedTran\BankGUI\BankGUI;
use ClickedTran\BankGUI\gui\GUIManager as BankMenu;
use ClickedTran\BankGUI\language\LanguageManager;
use ClickedTran\BankGUI\libs\muqsit\invmenu\transaction\SimpleInvMenuTransaction as BankTransaction;
use ClickedTran\rankshop\gui\GUIManager as RankMenu;
use ClickedTran\rankshop\libs\muqsit\invmenu\InvMenuHandler as RankMenus;
use ClickedTran\rankshop\RankShopGUI;
use cooldogedev\BedrockEconomy\api\BedrockEconomyAPI;
use muqsit\chestshop\category\CategoryEntry;
use muqsit\chestshop\category\CategoryPage;
use muqsit\chestshop\Loader as ChestShop;
use onebone\economyapi\EconomyAPI;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerLoginEvent;
use pocketmine\inventory\SimpleInventory;
use pocketmine\inventory\transaction\action\SlotChangeAction;
use pocketmine\inventory\transaction\InventoryTransaction;
use pocketmine\item\VanillaItems;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\ClosureTask;
use tobydev27\XPShop\Main as XPShop;

final class Probe extends PluginBase implements Listener{
    protected function onEnable() : void{
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
    }

    public function onJoin(PlayerJoinEvent $event) : void{
        $player = $event->getPlayer();
        $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($player) : void{
            $plugins = $this->getServer()->getPluginManager();
            if($plugins->getPlugin('BedrockEconomy') !== null){
                $chat = $plugins->getPlugin('ChatGames');
                $rank = $plugins->getPlugin('RankShopGUI');
                $xp = $plugins->getPlugin('XPShop');
                self::require($chat instanceof ChatGames && $chat->isEnabled(), 'ChatGames startup');
                self::require($rank instanceof RankShopGUI && $rank->isEnabled(), 'RankShopGUI startup');
                self::require($xp instanceof XPShop && $xp->isEnabled(), 'XPShop startup');
                BedrockEconomyAPI::CLOSURE()->insert('9821746001', $player->getName(), 100, 0,
                    function() use ($player, $chat, $rank, $xp) : void{
                        $this->chat($player, $chat, $rank, $xp);
                    }, static function($error) : void{
                        throw new \RuntimeException('Fixture account insertion failed: ' . $error);
                    });
                return;
            }
            $api = EconomyAPI::getInstance();
            self::require($api->setMoney($player, 100, true) === EconomyAPI::RET_SUCCESS, 'EconomyAPI seed');
            $chest = $plugins->getPlugin('ChestShop');
            if($chest !== null){
                self::require($chest instanceof ChestShop && $chest->isEnabled(), 'ChestShop startup');
                $this->chest($player, $api);
            }
            $bank = $plugins->getPlugin('BankGUI');
            if($bank !== null){
                self::require($bank instanceof BankGUI && $bank->isEnabled(), 'BankGUI startup');
                $this->bank($player, $api, $bank);
            }
        }), 12);
    }

    private function chat(Player $player, ChatGames $chat, RankShopGUI $rank, XPShop $xp) : void{
        $config = $chat->getConfig();
        $config->set('Online-Players', 0);
        $config->set('Min-Reward', 25);
        $config->set('Max-Reward', 25);
        $chat->scrambleWord();
        $word = (new \ReflectionProperty(ChatGames::class, 'word'))->getValue($chat);
        self::require(is_string($word) && $word !== '', 'Started a real ChatGames round');
        $player->chat($word);
        $this->later(function() use ($player, $chat, $rank, $xp) : void{
            $chat->getEconomyProvider()->getMoney($player, function(int|float $money) use ($player, $rank, $xp) : void{
                self::require($money === 125, 'ChatGames reward expected 125, got ' . $money);
                $this->getLogger()->info('ChatGames transaction passed: wallet 100 -> 125');
                $this->xp($player, $rank, $xp);
            });
        });
    }

    private function xp(Player $player, RankShopGUI $rank, XPShop $xp) : void{
        $player->getXpManager()->setXpLevel(10);
        $player->setOp(true);
        self::require($this->getServer()->dispatchCommand($player, 'xpshop'), 'XPShop command');
        $this->answerForm($player, '0');
        $this->answerForm($player, '[2]');
        $this->later(function() use ($player, $rank, $xp) : void{
            $xp->getEconomyProvider()->getMoney($player, function(int|float $money) use ($player, $rank) : void{
                self::require($money === 135 && $player->getXpManager()->getXpLevel() === 8, 'XPShop sale expected 135 and 8 XP levels, got ' . $money . ' and ' . $player->getXpManager()->getXpLevel());
                $this->getLogger()->info('XPShop transaction passed: wallet 125 -> 135, XP levels 10 -> 8');
                $this->rank($player, $rank);
            });
        });
    }

    private function rank(Player $player, RankShopGUI $plugin) : void{
        $rankProvider = $plugin->getRankProvider();
        $old = $rankProvider->getRank($player);
        self::require($old === 'Guest', 'RankSystem initial rank was ' . $old);
        self::require($rankProvider->getRankData('Admin') !== null, 'RankSystem target rank missing');
        if(RankMenus::getPlayerManager()->getNullable($player) === null){
            (new PlayerLoginEvent($player, ''))->call();
        }
        $menu = new RankMenu();
        $item = VanillaItems::PAPER()->setCustomName('Offer required Guest next rank Admin price $40');
        $menu->menuConfirm($player, $item);
        $session = RankMenus::getPlayerManager()->get($player);
        $confirmation = $session->getCurrent()?->menu;
        self::require($confirmation !== null, 'RankShopGUI confirmation menu');
        $inventory = $confirmation->getInventory();
        $button = $inventory->getItem(14);
        $air = VanillaItems::AIR();
        $action = new SlotChangeAction($inventory, 14, $button, $air);
        $transaction = new InventoryTransaction($player, [$action]);
        $confirmation->handleInventoryTransaction($player, $button, $air, $action, $transaction);
        $this->later(function() use ($player, $plugin, $rankProvider) : void{
            $plugin->getEconomy()->getMoney($player, function(int|float $money) use ($player, $rankProvider) : void{
                $newRank = $rankProvider->getRank($player);
                self::require($money === 95 && $newRank === 'Admin', 'RankShopGUI purchase expected 95 and Admin, got ' . $money . ' and ' . $newRank);
                $this->getLogger()->info('RankShopGUI transaction passed: wallet 135 -> 95, rank Guest -> Admin');
            });
        });
    }

    private function chest(Player $player, EconomyAPI $api) : void{
        $item = VanillaItems::DIAMOND()->setCount(3);
        $entry = new CategoryEntry($item, 20.0);
        $purchase = new \ReflectionMethod(CategoryPage::class, 'attemptPurchase');
        $purchase->invoke(new CategoryPage([$entry]), $player, $entry);
        self::require((float) $api->myMoney($player) === 80.0 && $player->getInventory()->contains($item), 'ChestShop purchase did not debit wallet and deliver diamonds');
        $this->getLogger()->info('ChestShop transaction passed: wallet 100 -> 80, 3 diamonds delivered');
    }

    private function bank(Player $player, EconomyAPI $api, BankGUI $bank) : void{
        $api->setMoney($player, 100, true);
        $bank->getBankManager($player)->createPlayerData();
        $before = $bank->getBankManager($player)->getMoney();
        $item = VanillaItems::PAPER()->setCustomName(LanguageManager::getTranslate('bank.deposit.half'));
        $air = VanillaItems::AIR();
        $inventory = new SimpleInventory(27);
        $inventory->setItem(0, $item);
        $action = new SlotChangeAction($inventory, 0, $item, $air);
        $transaction = new InventoryTransaction($player, [$action]);
        (new BankMenu())->menuBankListener(new BankTransaction($player, $item, $air, $action, $transaction));
        $balance = $bank->getBankManager($player)->getMoney();
        self::require((float) $api->myMoney($player) === 50.0 && (float) $balance === (float) $before + 50.0, 'BankGUI deposit did not transfer 50 to the bank');
        $this->getLogger()->info('BankGUI transaction passed: wallet 100 -> 50, bank ' . $before . ' -> ' . $balance);
    }

    private function answerForm(Player $player, string $response) : void{
        $pending = (new \ReflectionProperty(Player::class, 'forms'))->getValue($player);
        $id = array_key_last($pending);
        self::require($id !== null && $player->hasPendingForm($id), 'Expected an outstanding XPShop form');
        $player->onFalconFormResponse($id, $response, false);
    }

    private function later(\Closure $callback) : void{
        $this->getScheduler()->scheduleDelayedTask(new ClosureTask($callback), 10);
    }

    private static function require(bool $condition, string $problem) : void{
        if(!$condition){
            throw new \RuntimeException('Economy probe failed: ' . $problem);
        }
    }
}
