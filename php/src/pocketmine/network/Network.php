<?php

declare(strict_types=1);

namespace pocketmine\network;

use pocketmine\event\server\NetworkInterfaceRegisterEvent;
use pocketmine\event\server\NetworkInterfaceUnregisterEvent;

/**
 * The network interfaces plugins add next to Falcon's own: an RCON or a query server of their own, ticked with the
 * server. Players connect through Falcon, never through these.
 */
class Network{
    /** @var NetworkInterface[] */
    private array $interfaces = [];
    /** @var AdvancedNetworkInterface[] */
    private array $advancedInterfaces = [];
    /** @var RawPacketHandler[] */
    private array $rawPacketHandlers = [];
    private string $name = "";

    /** @return NetworkInterface[] */
    public function getInterfaces() : array{
        return $this->interfaces;
    }

    public function getConnectionCount() : int{
        return count(\pmmpcompat\Players::online());
    }

    public function tick() : void{
        foreach($this->interfaces as $interface){
            $interface->tick();
        }
    }

    public function registerInterface(NetworkInterface $interface) : bool{
        $ev = new NetworkInterfaceRegisterEvent($interface);
        $ev->call();
        if($ev->isCancelled()){
            return false;
        }
        $interface->start();
        $this->interfaces[$hash = spl_object_id($interface)] = $interface;
        if($interface instanceof AdvancedNetworkInterface){
            $this->advancedInterfaces[$hash] = $interface;
            $interface->setNetwork($this);
            foreach($this->rawPacketHandlers as $handler){
                $interface->addRawPacketFilter($handler->getPattern());
            }
        }
        $interface->setName($this->name);
        return true;
    }

    public function unregisterInterface(NetworkInterface $interface) : void{
        if(!isset($this->interfaces[$hash = spl_object_id($interface)])){
            throw new \InvalidArgumentException("Interface " . get_class($interface) . " is not registered on this network");
        }
        (new NetworkInterfaceUnregisterEvent($interface))->call();
        unset($this->interfaces[$hash], $this->advancedInterfaces[$hash]);
        $interface->shutdown();
    }

    public function setName(string $name) : void{
        $this->name = $name;
        foreach($this->interfaces as $interface){
            $interface->setName($name);
        }
    }

    public function getName() : string{
        return $this->name;
    }

    public function updateName() : void{
        $this->setName($this->name);
    }

    public function sendPacket(string $address, int $port, string $payload) : void{
        foreach($this->advancedInterfaces as $interface){
            $interface->sendRawPacket($address, $port, $payload);
        }
    }

    public function blockAddress(string $address, int $timeout = 300) : void{
        foreach($this->advancedInterfaces as $interface){
            $interface->blockAddress($address, $timeout);
        }
    }

    public function unblockAddress(string $address) : void{
        foreach($this->advancedInterfaces as $interface){
            $interface->unblockAddress($address);
        }
    }

    public function registerRawPacketHandler(RawPacketHandler $handler) : void{
        $this->rawPacketHandlers[spl_object_id($handler)] = $handler;
        foreach($this->advancedInterfaces as $interface){
            $interface->addRawPacketFilter($handler->getPattern());
        }
    }

    public function unregisterRawPacketHandler(RawPacketHandler $handler) : void{
        unset($this->rawPacketHandlers[spl_object_id($handler)]);
    }

    public function processRawPacket(AdvancedNetworkInterface $interface, string $address, int $port, string $packet) : void{
        foreach($this->rawPacketHandlers as $handler){
            if(preg_match($handler->getPattern(), $packet) === 1){
                $handler->handle($interface, $address, $port, $packet);
            }
        }
    }

    public function onShutdown() : void{
        foreach($this->interfaces as $interface){
            $interface->shutdown();
        }
        $this->interfaces = [];
        $this->advancedInterfaces = [];
    }
}
