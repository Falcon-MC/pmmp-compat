<?php

declare(strict_types=1);

namespace pmmpcompat;

use pocketmine\event\Cancellable;
use pocketmine\event\Event;
use pocketmine\event\EventPriority;
use pocketmine\event\HandlerListManager;
use pocketmine\event\player\PlayerChatEvent;
use pocketmine\event\player\PlayerEmoteEvent;
use pocketmine\event\player\PlayerGameModeChangeEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerJumpEvent;
use pocketmine\event\player\PlayerLoginEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\player\PlayerToggleFlightEvent;
use pocketmine\event\player\PlayerToggleSneakEvent;
use pocketmine\event\player\PlayerToggleSprintEvent;
use pocketmine\event\server\CommandEvent;
use pocketmine\player\GameMode;
use pocketmine\lang\Translatable;
use pocketmine\player\chat\StandardChatFormatter;
use pocketmine\Server;

/**
 * Where Falcon's events and commands come in.
 *
 * Falcon calls once for each of its priorities, lowest first, and the listeners of the matching PocketMine-MP
 * priority run then; MONITOR listeners run right after HIGHEST, since Falcon lets nothing change past that. The event
 * object lives from the LOWEST call to the HIGHEST one, and what listeners change goes back to Falcon at each step,
 * so Falcon's other plugins see it.
 */
final class Bridge{
    // the event types of falcon_api.h
    private const PLAYER_JOIN = 1;
    private const PLAYER_QUIT = 2;
    private const PLAYER_CHAT = 3;
    private const PLAYER_COMMAND = 21;
    private const SERVER_COMMAND = 22;
    private const PLAYER_GAME_MODE_CHANGE = 24;
    private const PLAYER_PRE_LOGIN = 31;
    private const PLAYER_TOGGLE_SNEAK = 32;
    private const PLAYER_TOGGLE_SPRINT = 33;
    private const PLAYER_TOGGLE_FLIGHT = 34;
    private const PLAYER_JUMP = 39;
    private const PLAYER_EMOTE = 76;

    /** what the runtime listens to; each becomes a PocketMine-MP event in create() */
    private const LISTENED = [
        self::PLAYER_JOIN, self::PLAYER_QUIT, self::PLAYER_CHAT, self::PLAYER_COMMAND, self::SERVER_COMMAND,
        self::PLAYER_GAME_MODE_CHANGE, self::PLAYER_PRE_LOGIN, self::PLAYER_TOGGLE_SNEAK, self::PLAYER_TOGGLE_SPRINT,
        self::PLAYER_TOGGLE_FLIGHT, self::PLAYER_JUMP, self::PLAYER_EMOTE,
    ];

    /** @var array<int, array{event: Event, message: string, recipients: object[], sender: ?object}> by handle */
    private static array $inFlight = [];

    private function __construct(){
    }

    /** @internal once the plugins are enabled: Falcon starts passing its events */
    public static function listen() : void{
        foreach(self::LISTENED as $type){
            falcon_listen($type);
        }
    }

    /** @internal each of Falcon's ticks */
    public static function tick() : void{
        try{
            Server::getInstance()->tick();
        }catch(\Throwable $e){
            Log::critical('A tick of the PocketMine-MP plugins failed: ' . $e->getMessage());
            Log::exception($e);
        }
    }

    public static function event(int $type, int $priority, int $handle) : void{
        try{
            if($priority === EventPriority::LOWEST){
                $event = self::create($type, $handle);
                if($event === null){
                    return;
                }
                self::$inFlight[$handle] = $event;
            }
            if(!isset(self::$inFlight[$handle])){
                return;
            }
            $event = self::$inFlight[$handle]['event'];
            self::callListeners($event, $priority);
            if($priority === EventPriority::HIGHEST){
                self::callListeners($event, EventPriority::MONITOR);
            }
            self::applyToFalcon($type, $handle, $priority === EventPriority::HIGHEST);
        }catch(\Throwable $e){
            Log::critical('Could not pass an event to the PocketMine-MP plugins: ' . $e->getMessage());
            Log::exception($e);
        }finally{
            if($priority === EventPriority::HIGHEST){
                unset(self::$inFlight[$handle]);
                if($type === self::PLAYER_QUIT){
                    Players::leave(falcon_event_player($handle));
                }
            }
        }
    }

    /** @return array{event: Event, message: string, recipients: object[], sender: ?object}|null */
    private static function create(int $type, int $handle) : ?array{
        $server = Server::getInstance();
        $message = falcon_event_message($handle);
        switch($type){
            case self::PLAYER_JOIN:
                $player = Players::get(falcon_event_player($handle));
                return ['event' => new PlayerJoinEvent($player, $message), 'message' => $message, 'recipients' => [],
                    'sender' => null];
            case self::PLAYER_QUIT:
                $player = Players::get(falcon_event_player($handle));
                $quitMessage = $player->takeQuitMessage();
                $event = new PlayerQuitEvent($player, $quitMessage ?? $message, falcon_event_cause($handle));
                // a kick that named a leave message: it is the starting one, and Falcon has to know
                return ['event' => $event, 'message' => $quitMessage === null ? $message : "\0", 'recipients' => [],
                    'sender' => null];
            case self::PLAYER_CHAT:
                $player = Players::get(falcon_event_player($handle));
                $recipients = $server->getBroadcastChannelSubscribers(Server::BROADCAST_CHANNEL_USERS);
                $event = new PlayerChatEvent($player, $message, $recipients, new StandardChatFormatter());
                if(falcon_event_is_cancelled($handle)){
                    $event->cancel();
                }
                return ['event' => $event, 'message' => $message, 'recipients' => $recipients, 'sender' => $player];
            case self::PLAYER_COMMAND:
            case self::SERVER_COMMAND:
                $player = falcon_event_player($handle);
                $sender = $player !== 0 ? Players::get($player) : $server->getConsoleSender();
                return self::state(new CommandEvent($sender, $message), $message, $handle);
            case self::PLAYER_PRE_LOGIN:
                // Falcon's last word before the player is let in, as PocketMine-MP's login event
                return self::state(new PlayerLoginEvent(Players::get(falcon_event_player($handle)), $message), $message, $handle);
            case self::PLAYER_GAME_MODE_CHANGE:
                $gameMode = self::gameMode(falcon_event_game_mode($handle));
                if($gameMode === null){
                    return null;
                }
                return self::state(new PlayerGameModeChangeEvent(Players::get(falcon_event_player($handle)), $gameMode), $message, $handle);
            case self::PLAYER_TOGGLE_SNEAK:
                return self::state(new PlayerToggleSneakEvent(Players::get(falcon_event_player($handle)), falcon_event_state($handle)), $message, $handle);
            case self::PLAYER_TOGGLE_SPRINT:
                return self::state(new PlayerToggleSprintEvent(Players::get(falcon_event_player($handle)), falcon_event_state($handle)), $message, $handle);
            case self::PLAYER_TOGGLE_FLIGHT:
                return self::state(new PlayerToggleFlightEvent(Players::get(falcon_event_player($handle)), falcon_event_state($handle)), $message, $handle);
            case self::PLAYER_JUMP:
                return self::state(new PlayerJumpEvent(Players::get(falcon_event_player($handle))), $message, $handle);
            case self::PLAYER_EMOTE:
                return self::state(new PlayerEmoteEvent(Players::get(falcon_event_player($handle)), $message), $message, $handle);
        }
        return null;
    }

    /** @return array{event: Event, message: string, recipients: object[], sender: ?object} */
    private static function state(Event $event, string $message, int $handle) : array{
        // cancelled already when a Falcon plugin listening below LOWEST did
        if($event instanceof Cancellable && falcon_event_is_cancelled($handle)){
            $event->cancel();
        }
        return ['event' => $event, 'message' => $message, 'recipients' => [], 'sender' => null];
    }

    /** Falcon's game mode numbers, as falcon_api.h gives them */
    public static function gameMode(int $falcon) : ?GameMode{
        return match($falcon){
            0 => GameMode::SURVIVAL,
            1 => GameMode::CREATIVE,
            2 => GameMode::ADVENTURE,
            6 => GameMode::SPECTATOR,
            default => null,
        };
    }

    public static function falconGameMode(GameMode $gameMode) : int{
        return match($gameMode){
            GameMode::SURVIVAL => 0,
            GameMode::CREATIVE => 1,
            GameMode::ADVENTURE => 2,
            GameMode::SPECTATOR => 6,
        };
    }

    private static function callListeners(Event $event, int $priority) : void{
        foreach(HandlerListManager::global()->getHandlersFor($event::class) as $listener){
            if($listener->getPriority() !== $priority){
                continue;
            }
            try{
                if(!falcon_guard(static fn() => $listener->callEvent($event))){
                    Log::critical('A listener of ' . $event->getEventName() . ' from '
                        . $listener->getPlugin()->getName() . ' stopped on a fatal error or exit(), see above');
                }
            }catch(\Throwable $e){
                Log::critical('A listener of ' . $event->getEventName() . ' from ' . $listener->getPlugin()->getName()
                    . ' failed: ' . $e->getMessage());
                Log::exception($e);
            }
        }
    }

    private static function text(Translatable|string $message) : string{
        return $message instanceof Translatable ? Server::getInstance()->getLanguage()->translate($message) : $message;
    }

    private static function applyToFalcon(int $type, int $handle, bool $last) : void{
        $state = &self::$inFlight[$handle];
        $event = $state['event'];
        if($event instanceof Cancellable){
            falcon_event_set_cancelled($handle, $event->isCancelled());
        }
        if($event instanceof PlayerJoinEvent){
            self::applyMessage($handle, $state, self::text($event->getJoinMessage()));
        }elseif($event instanceof PlayerQuitEvent){
            self::applyMessage($handle, $state, self::text($event->getQuitMessage()));
        }elseif($event instanceof CommandEvent){
            self::applyMessage($handle, $state, $event->getCommand());
        }elseif($event instanceof PlayerLoginEvent){
            self::applyMessage($handle, $state, self::text($event->getKickMessage()));
            if($last && $event->isCancelled()){
                Players::leave(falcon_event_player($handle));
            }
        }elseif($event instanceof PlayerEmoteEvent){
            self::applyMessage($handle, $state, $event->getEmoteId());
        }elseif($event instanceof PlayerChatEvent){
            if($event->getMessage() !== $state['message']){
                falcon_event_set_message($handle, $event->getMessage());
                $state['message'] = $event->getMessage();
            }
            if($last && !$event->isCancelled() && self::needsOwnBroadcast($event, $state)){
                // a format, a name or recipients Falcon's chat would not give: the message goes out from here
                falcon_event_set_cancelled($handle, true);
                $line = $event->getFormatter()->format($event->getPlayer()->getDisplayName(), $event->getMessage());
                Server::getInstance()->broadcastMessage($line, $event->getRecipients());
            }
        }
    }

    /** @param array{message: string} $state */
    private static function applyMessage(int $handle, array &$state, string $message) : void{
        if($message !== $state['message']){
            falcon_event_set_message($handle, $message);
            $state['message'] = $message;
        }
    }

    /** @param array{recipients: object[], sender: ?object} $state */
    private static function needsOwnBroadcast(PlayerChatEvent $event, array $state) : bool{
        $player = $event->getPlayer();
        if(get_class($event->getFormatter()) !== StandardChatFormatter::class || $player !== $state['sender']
            || $player->getDisplayName() !== $player->getName()){
            return true;
        }
        $recipients = $event->getRecipients();
        if(count($recipients) !== count($state['recipients'])){
            return true;
        }
        foreach($recipients as $recipient){
            if(!in_array($recipient, $state['recipients'], true)){
                return true;
            }
        }
        return false;
    }

    /**
     * A PocketMine-MP command Falcon received, under the label it was registered with.
     *
     * @param string[] $args
     */
    public static function command(string $label, int $sender, array $args) : bool{
        try{
            $server = Server::getInstance();
            $command = $server->getCommandMap()->getCommand($label);
            if($command === null){
                return false;
            }
            $player = falcon_sender_player($sender);
            $source = $player !== 0 ? Players::get($player) : $server->getConsoleSender();
            $server->getCommandMap()->execute($source, $command, $label, $args);
            return true;
        }catch(\Throwable $e){
            Log::critical("Could not run /$label: " . $e->getMessage());
            Log::exception($e);
            return true;
        }
    }
}
