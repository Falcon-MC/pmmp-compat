<?php

declare(strict_types=1);

namespace pocketmine\permission;

/**
 * Falcon's name or address bans, as PocketMine-MP's list of them.
 *
 * Falcon keeps the list and kicks whoever it bans. The reason, source and expiry a plugin gives are kept here for the
 * entries it adds; an expiry is checked when the list is read.
 */
class BanList{
    /** @var BanEntry[] what plugins added, by lower-case name */
    private array $known = [];

    private bool $ip;

    /**
     * @param string|bool $file PocketMine-MP's ban file, whose name tells names from addresses; true for addresses
     */
    public function __construct(string|bool $file){
        $this->ip = is_bool($file) ? $file : str_contains(strtolower(basename($file)), "ip");
    }

    public function isEnabled() : bool{
        return true;
    }

    public function setEnabled(bool $flag) : void{
    }

    public function getEntry(string $name) : ?BanEntry{
        $this->removeExpired();
        $name = strtolower($name);
        if(!falcon_server_is_banned($name, $this->ip)){
            unset($this->known[$name]);
            return null;
        }
        return $this->known[$name] ??= new BanEntry($name);
    }

    /** @return BanEntry[] by lower-case name */
    public function getEntries() : array{
        $this->removeExpired();
        $entries = [];
        foreach(falcon_server_bans($this->ip) as $name){
            $key = strtolower($name);
            $entries[$key] = $this->known[$key] ?? new BanEntry($key);
        }
        return $entries;
    }

    public function isBanned(string $name) : bool{
        return $this->getEntry($name) !== null;
    }

    public function add(BanEntry $entry) : void{
        $name = strtolower($entry->getName());
        $this->known[$name] = $entry;
        falcon_server_ban($entry->getName(), $entry->getReason(), $entry->getSource(), $this->ip);
    }

    public function addBan(string $target, ?string $reason = null, ?\DateTime $expires = null, ?string $source = null) : BanEntry{
        $entry = new BanEntry($target);
        $entry->setSource($source ?? $entry->getSource());
        $entry->setExpires($expires);
        $entry->setReason($reason ?? $entry->getReason());
        $this->add($entry);
        return $entry;
    }

    public function remove(string $name) : void{
        $name = strtolower($name);
        unset($this->known[$name]);
        falcon_server_pardon($name, $this->ip);
    }

    public function removeExpired() : void{
        foreach($this->known as $name => $entry){
            if($entry->hasExpired()){
                $this->remove($name);
            }
        }
    }

    /** Falcon reads and writes its lists itself */
    public function load() : void{
    }

    public function save(bool $writeHeader = true) : void{
    }
}
