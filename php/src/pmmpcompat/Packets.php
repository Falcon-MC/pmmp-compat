<?php

declare(strict_types=1);

namespace pmmpcompat;

use pmmp\encoding\ByteBufferReader;
use pmmp\encoding\ByteBufferWriter;
use pmmp\encoding\DataDecodeException;
use pmmp\encoding\VarInt;
use pocketmine\network\mcpe\protocol\Packet;
use pocketmine\network\mcpe\protocol\PacketDecodeException;
use pocketmine\network\mcpe\protocol\PacketPool;

/**
 * Packets between BedrockProtocol's objects and Falcon's payloads, which carry no header: Falcon writes it.
 *
 * BedrockProtocol is PocketMine-MP's, for its game version; a packet whose layout changed since reads or writes wrong
 * for Falcon's newer clients.
 */
final class Packets{
    private function __construct(){
    }

    /** @return array{int, string} the packet id and the payload */
    public static function encode(Packet $packet) : array{
        $writer = new ByteBufferWriter();
        $packet->encode($writer);
        $reader = new ByteBufferReader($writer->getData());
        VarInt::readUnsignedInt($reader);
        return [$packet->pid(), substr($writer->getData(), $reader->getOffset())];
    }

    /** null for an id BedrockProtocol does not know, or a payload it cannot read */
    public static function decode(int $packetId, string $payload) : ?Packet{
        $packet = PacketPool::getInstance()->getPacketById($packetId);
        if($packet === null){
            return null;
        }
        $writer = new ByteBufferWriter();
        VarInt::writeUnsignedInt($writer, $packetId);
        try{
            $packet->decode(new ByteBufferReader($writer->getData() . $payload));
        }catch(DataDecodeException|PacketDecodeException){
            return null;
        }
        return $packet;
    }
}
