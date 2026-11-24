<?php

declare(strict_types=1);

namespace pocketmine\network\mcpe\protocol;

use pmmp\encoding\ByteBufferReader;
use pmmp\encoding\ByteBufferWriter;
use pmmpcompat\LegacyItemComponents;
use pocketmine\network\mcpe\protocol\types\ItemComponentPacketEntry;

/** Older plugins declare item components here. Falcon includes them in its login item registry. */
final class ItemComponentPacket extends DataPacket implements ClientboundPacket{
    public const NETWORK_ID = ProtocolInfo::ITEM_REGISTRY_PACKET;

    /** @var ItemComponentPacketEntry[] */
    public array $entries = [];

    /** @param ItemComponentPacketEntry[] $entries */
    public static function create(array $entries) : self{
        LegacyItemComponents::register($entries);
        $packet = new self();
        $packet->entries = $entries;
        return $packet;
    }

    protected function decodePayload(ByteBufferReader $in) : void{
        throw new PacketDecodeException("Item components are carried by ItemRegistryPacket");
    }

    protected function encodePayload(ByteBufferWriter $out) : void{
        throw new \LogicException("Falcon already publishes item components in its item registry");
    }

    public function handle(PacketHandlerInterface $handler) : bool{
        return false;
    }
}
