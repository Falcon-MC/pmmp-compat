<?php

declare(strict_types=1);

namespace compatplayers;

use jojoe77777\FormAPI\CustomForm;
use jojoe77777\FormAPI\ModalForm;
use jojoe77777\FormAPI\SimpleForm;
use pmmpcompat\Packets;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerDisplayNameChangeEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerKickEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\server\DataPacketSendEvent;
use pocketmine\lang\Translatable;
use pocketmine\network\mcpe\protocol\ClientboundCloseFormPacket;
use pocketmine\network\mcpe\protocol\SetTitlePacket;
use pocketmine\network\mcpe\protocol\TextPacket;
use pocketmine\network\mcpe\protocol\ToastRequestPacket;
use pocketmine\permission\PermissionManager;
use pocketmine\player\GameMode;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\ClosureTask;

final class Main extends PluginBase implements Listener{
    private array $packets = [];
    private int $displayNameChanges = 0;
    private string $uuid;

    protected function onEnable() : void{
        PermissionManager::getInstance()->getPermission('fixture.always')->addChild('fixture.child', true);
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
    }

    public function onJoin(PlayerJoinEvent $event) : void{
        $this->getScheduler()->scheduleDelayedTask(new ClosureTask(fn() => $this->test($event->getPlayer())), 2);
    }

    public function onDisplayNameChange(PlayerDisplayNameChangeEvent $event) : void{
        if($event->getOldName() === 'Visitor' && $event->getNewName() === 'Display'){
            ++$this->displayNameChanges;
        }
    }

    public function onKick(PlayerKickEvent $event) : void{
        if($event->getDisconnectReason() === 'Cancelled kick'){
            $event->cancel();
        }
    }

    public function onQuit(PlayerQuitEvent $event) : void{
        $player = $event->getPlayer();
        $last = $player->getLocation();
        $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($player, $last) : void{
            self::check(!$player->isOnline() && !$player->getNetworkSession()->isConnected(), 'offline session');
            self::check($player->getUniqueId()->toString() === $this->uuid, 'stable UUID');
            self::check($player->getLocation()->equals($last), 'offline location');
            self::check($player->getPosition()->equals($last->asPosition()), 'offline position');
            self::check($player->getWorld() === $last->getWorld(), 'offline world');
            self::check((new \ReflectionProperty(Player::class, 'forms'))->getValue($player) === [], 'offline form cleanup');
            $this->getLogger()->info('player compatibility offline passed');
        }), 1);
    }

    public function onPacket(DataPacketSendEvent $event) : void{
        foreach($event->getPackets() as $packet){
            if($packet instanceof SetTitlePacket || $packet instanceof TextPacket ||
                $packet instanceof ToastRequestPacket || $packet instanceof ClientboundCloseFormPacket){
                [$id, $payload] = Packets::encode($packet);
                $this->packets[] = Packets::decode($id, $payload);
            }
        }
    }

    private function test(Player $player) : void{
        self::check($player->getNetworkSession()->getPlayer() === $player, 'online session');
        self::check($player->getName() === 'Visitor' && $player->getDisplayName() === 'Visitor', 'initial name');
        $this->uuid = $player->getUniqueId()->toString();
        $player->setDisplayName('Display');
        $player->setNameTag('Name tag');
        self::check($player->getName() === 'Visitor' && $player->getDisplayName() === 'Display' &&
            $this->displayNameChanges === 1 && $player->getNameTag() === 'Name tag', 'independent name and display state');
        self::check($player->getPosition()->getWorld() === $player->getWorld(), 'online world');
        self::check($player->setGamemode(GameMode::CREATIVE) && $player->getGamemode() === GameMode::CREATIVE &&
            !$player->setGamemode(GameMode::CREATIVE), 'game mode changes');
        self::check(!$player->transfer('localhost', 4294967297), 'out-of-range transfer port');
        self::check($player->transfer('localhost'), 'valid transfer queued');
        self::check(!$player->kick('Cancelled kick') && $player->isOnline(), 'cancelled kick');

        self::check(!$player->isOp() && !$player->hasPermission('fixture.operator'), 'non-operator');
        self::check($player->hasPermission('fixture.always') && $player->hasPermission('fixture.child') &&
            $player->hasPermission('fixture.nonoperator') && !$player->hasPermission('fixture.never') &&
            !$player->hasPermission('fixture.unspecified'), 'manifest defaults');
        self::check(PermissionManager::getInstance()->getPermission('fixture.child') !== null, 'permission registry');
        self::check($player->hasPermission('fixture.group') && !$player->hasPermission('fixture.denied'), 'Falcon group');
        $attachment = $player->addAttachment($this, 'fixture.group', false);
        self::check(!$player->hasPermission('fixture.group'), 'attachment denial');
        $player->removeAttachment($attachment);
        self::check($player->hasPermission('fixture.group'), 'attachment removal');
        $player->setOp(true);
        self::check($player->isOp() && $player->hasPermission('fixture.operator') &&
            !$player->hasPermission('fixture.nonoperator') &&
            !$player->hasPermission('fixture.undeclared'), 'operator defaults');
        $player->setOp(false);
        self::check(!$player->isOp() && !$player->hasPermission('fixture.operator'), 'operator revocation');

        $this->testMessages($player);
        $this->testForms($player);
        $this->getLogger()->info('player compatibility online passed');
    }

    private function testMessages(Player $player) : void{
        $this->packets = [];
        $player->sendMessage('raw');
        $player->sendMessage(new Translatable('chat.type.text', ['name', 'message']));
        $player->sendTip('tip');
        $player->sendPopup('popup');
        $player->sendTitle('title', 'subtitle', 7, 13, 17);
        $player->sendSubTitle('standalone');
        $player->sendActionBarMessage('actionbar');
        $player->sendToastNotification('toast', 'body');
        $player->removeTitles();
        $player->resetTitles();
        $this->checkText(TextPacket::TYPE_RAW, 'raw');
        $translation = $this->checkText(TextPacket::TYPE_TRANSLATION, 'chat.type.text');
        self::check($translation->parameters === ['name', 'message'], 'translation arguments');
        $this->checkText(TextPacket::TYPE_TIP, 'tip');
        $this->checkText(TextPacket::TYPE_POPUP, 'popup');
        $timing = $this->checkTitle(SetTitlePacket::TYPE_SET_ANIMATION_TIMES, '');
        self::check([$timing->fadeInTime, $timing->stayTime, $timing->fadeOutTime] === [7, 13, 17], 'title timings');
        $this->checkTitle(SetTitlePacket::TYPE_SET_SUBTITLE, 'subtitle');
        $this->checkTitle(SetTitlePacket::TYPE_SET_TITLE, 'title');
        $this->checkTitle(SetTitlePacket::TYPE_SET_SUBTITLE, 'standalone');
        $this->checkTitle(SetTitlePacket::TYPE_SET_ACTIONBAR_MESSAGE, 'actionbar');
        $toast = array_shift($this->packets);
        self::check($toast instanceof ToastRequestPacket && $toast->getTitle() === 'toast' && $toast->getBody() === 'body', 'toast');
        $this->checkTitle(SetTitlePacket::TYPE_CLEAR_TITLE, '');
        $this->checkTitle(SetTitlePacket::TYPE_RESET_TITLE, '');
        self::check($this->packets === [], 'no duplicate packets');
    }

    private function checkText(int $type, string $message) : TextPacket{
        $packet = array_shift($this->packets);
        self::check($packet instanceof TextPacket && $packet->type === $type && $packet->message === $message, 'text packet ' . $message);
        return $packet;
    }

    private function checkTitle(int $type, string $message) : SetTitlePacket{
        $packet = array_shift($this->packets);
        self::check($packet instanceof SetTitlePacket && $packet->type === $type && $packet->text === $message, 'title packet ' . $type);
        return $packet;
    }

    private function testForms(Player $player) : void{
        $responses = [];
        $callback = static function(Player $responder, mixed $value) use ($player, &$responses) : void{
            self::check($responder === $player, 'form responder');
            $responses[] = $value;
        };
        $simple = new SimpleForm($callback);
        $simple->addButton('Button', label: 'button-key');
        $modal = new ModalForm($callback);
        $custom = new CustomForm($callback);
        $custom->addInput('Input', label: 'input-key');
        $pending = new \ReflectionProperty(Player::class, 'forms');
        foreach([
            [$simple, '0', false],
            [$modal, 'false', false],
            [$custom, '["hello"]', false],
            [$modal, '', true],
            [$simple, '{', false],
            [$simple, '4', false],
            [$custom, '[["nested"]]', false],
            [$custom, json_encode([str_repeat('a', 11 * 1024)], JSON_THROW_ON_ERROR), false]
        ] as [$form, $json, $cancelled]){
            $player->sendForm($form);
            $id = array_key_last($pending->getValue($player));
            self::check($id !== null && $player->hasPendingForm($id), 'Falcon returned pending form ID');
            $player->onFalconFormResponse($id, $json, $cancelled);
            self::check(!$player->hasPendingForm($id) && !$player->onFormSubmit($id, null), 'form ID consumed once');
        }
        self::check($responses === ['button-key', false, ['input-key' => 'hello'], null], 'form response types');
        $player->closeAllForms();
        self::check(array_shift($this->packets) instanceof ClientboundCloseFormPacket, 'close-all packet');
        $player->sendForm($simple);
        self::check($pending->getValue($player) !== [], 'form pending before disconnect');
    }

    private static function check(bool $condition, string $case) : void{
        if(!$condition){
            throw new \RuntimeException("Compatibility fixture failed: $case");
        }
    }
}
