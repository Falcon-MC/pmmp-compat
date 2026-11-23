<?php

declare(strict_types=1);

namespace commander;

use pmmpcompat\Packets;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\server\DataPacketSendEvent;
use pocketmine\network\mcpe\protocol\AvailableCommandsPacket;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\network\mcpe\protocol\serializer\AvailableCommandsPacketAssembler;
use pocketmine\network\mcpe\protocol\serializer\AvailableCommandsPacketDisassembler;
use pocketmine\network\mcpe\protocol\types\command\CommandHardEnum;
use pocketmine\network\mcpe\protocol\types\command\CommandOverload;
use pocketmine\network\mcpe\protocol\types\command\CommandParameter;
use pocketmine\network\mcpe\protocol\types\command\CommandSoftEnum;
use pocketmine\plugin\PluginBase;

/**
 * The parameters a client is shown for plugin commands: a Commando-style command whose hook rewrites the command list,
 * a command known by its usage only, and a plugin that edits the list itself on its way out.
 *
 * The simulated player has no network connection. Replay a captured Falcon list through its NetworkSession.
 */
final class Main extends PluginBase implements Listener{
    /** what Falcon writes for the hidden, note, ping and shop commands, the last with the alias store */
    private const FALCON_LIST = "020473686f700573746f72650000011273686f70436f6d6d616e64416c696173657302000000000100000000040668696464"
        . "656e144e6f742073686f776e20746f20706c6179657273000003616e79ffffffff000100010461726773460010000100046e"
        . "6f74652954616b65732061206e6f74652c2064657363726962656420627920697473207573616765206f6e6c79000003616e"
        . "79ffffffff0001000104617267734600100001000470696e670c50696e6773206120686f7374000003616e79ffffffff0001"
        . "000104617267734600100001000473686f700e4275797320616e642073656c6c73000003616e790000000000010001046172"
        . "67734600100001000000";

    private int $listsSent = 0;

    protected function onEnable() : void{
        $this->register(new ShopCommand(new CommandSoftEnum("ShopItems", ["apple", "bread", "cake"])));
        $this->register(new class("ping", "Pings a host", "/ping <host>") extends Command{
            public function execute(CommandSender $sender, string $commandLabel, array $args){
            }
        });
        $this->register(new class("hidden", "Not shown to players", "/hidden") extends Command{
            public function execute(CommandSender $sender, string $commandLabel, array $args){
            }
        });
        (new PacketHooker())->register($this);
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
    }

    private function register(Command $command) : void{
        $command->setPermission("commander.command.note");
        $this->getServer()->getCommandMap()->register("commander", $command);
    }

    public function onJoin(PlayerJoinEvent $event) : void{
        $session = $event->getPlayer()->getNetworkSession();
        $session->syncAvailableCommands();
        if(falcon_player_execute_command($event->getPlayer()->getHandle(), "store buy apple")){
            $this->getLogger()->info("fixture: Commander registered shop and its alias in Falcon");
        }
        $list = Packets::decode(ProtocolInfo::AVAILABLE_COMMANDS_PACKET, (string) hex2bin(self::FALCON_LIST));
        if(!$list instanceof AvailableCommandsPacket){
            $this->getLogger()->info("fixture: Commander could not read Falcon's list");
            return;
        }
        $names = array_map(static fn($data) => $data->getName(), $list->commandData);
        sort($names);
        $this->getLogger()->info("fixture: Commander decoded the captured command list: " . implode(", ", $names));
        $session->sendDataPacket($list);
        $this->getLogger()->info("fixture: Commander saw " . $this->listsSent . " list go out");
    }

    /**
     * a plugin editing the list itself: ping gets its parameters, hidden is taken out
     *
     * @priority HIGH
     */
    public function onSend(DataPacketSendEvent $event) : void{
        foreach($event->getPackets() as $packet){
            if(!$packet instanceof AvailableCommandsPacket){
                continue;
            }
            $list = AvailableCommandsPacketDisassembler::disassemble($packet);
            $kept = [];
            foreach($list->commandData as $data){
                if($data->getName() === "hidden"){
                    continue;
                }
                if($data->getName() === "ping"){
                    $data->overloads = [new CommandOverload(false, [
                        CommandParameter::standard("host", AvailableCommandsPacket::ARG_TYPE_STRING),
                        CommandParameter::postfixed("count", "x", 0, true),
                    ])];
                }
                $kept[] = $data;
            }
            $edited = AvailableCommandsPacketAssembler::assemble($kept, $list->unusedHardEnums, $list->unusedSoftEnums);
            foreach(get_object_vars($edited) as $field => $value){
                $packet->$field = $value;
            }
        }
    }

    /**
     * what goes out, read back as the client reads it
     *
     * @priority MONITOR
     */
    public function onSent(DataPacketSendEvent $event) : void{
        foreach($event->getPackets() as $packet){
            if(!$packet instanceof AvailableCommandsPacket){
                continue;
            }
            $this->listsSent++;
            [$id, $payload] = Packets::encode($packet);
            $read = Packets::decode($id, $payload);
            if(!$read instanceof AvailableCommandsPacket){
                $this->getLogger()->info("fixture: Commander sent a list the client cannot read");
                continue;
            }
            $commands = [];
            foreach(AvailableCommandsPacketDisassembler::disassemble($read)->commandData as $data){
                $commands[$data->getName()] = $data;
            }
            foreach(["shop", "note", "ping"] as $name){
                if(!isset($commands[$name])){
                    $this->getLogger()->info("fixture: Commander $name is missing");
                    continue;
                }
                $aliases = $commands[$name]->getAliases();
                $overloads = array_map(static fn(CommandOverload $overload) => implode(" ", array_map(self::describe(...), $overload->getParameters())),
                    $commands[$name]->getOverloads());
                $this->getLogger()->info("fixture: Commander $name" . ($aliases !== null ? " (" . implode(", ", $aliases->getValues()) . ")" : "")
                    . ": " . implode(" / ", $overloads));
            }
            $soft = [];
            foreach($read->softEnums as $enum){
                $soft[] = $enum->getName() . ": " . implode(", ", $enum->getValues());
            }
            $this->getLogger()->info("fixture: Commander hidden " . (isset($commands["hidden"]) ? "shown" : "gone") . ", soft enums " . implode("; ", $soft));
        }
    }

    private static function describe(CommandParameter $parameter) : string{
        if($parameter->enum instanceof CommandSoftEnum){
            $type = "soft " . $parameter->enum->getName();
        }elseif($parameter->enum instanceof CommandHardEnum){
            $type = implode("|", $parameter->enum->getValues());
        }elseif($parameter->postfix !== null){
            $type = "int " . $parameter->postfix;
        }else{
            $type = match($parameter->paramType){
                AvailableCommandsPacket::ARG_TYPE_INT => "int",
                AvailableCommandsPacket::ARG_TYPE_TARGET => "target",
                AvailableCommandsPacket::ARG_TYPE_RAWTEXT => "text",
                AvailableCommandsPacket::ARG_TYPE_STRING => "string",
                default => "type " . $parameter->paramType,
            };
        }
        return ($parameter->isOptional ? "[" : "<") . $parameter->paramName . ": " . $type . ($parameter->isOptional ? "]" : ">");
    }
}
