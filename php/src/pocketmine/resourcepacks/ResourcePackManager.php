<?php

declare(strict_types=1);

namespace pocketmine\resourcepacks;

/**
 * The resource packs the players are sent, as PocketMine-MP's manager of them.
 *
 * Falcon sends its own packs, from its resource_packs folder; packs a plugin adds here are kept in the stack it
 * reads back, for the plugins that look at it.
 */
class ResourcePackManager{
    /** @var ResourcePack[] */
    private array $resourcePacks = [];
    /** @var ResourcePack[] by lower-case uuid */
    private array $uuidList = [];
    /** @var string[] by lower-case uuid */
    private array $encryptionKeys = [];
    private bool $serverForceResources = false;

    public function __construct(private string $path){
    }

    public function getPath() : string{
        return $this->path;
    }

    public function resourcePacksRequired() : bool{
        return $this->serverForceResources;
    }

    public function setResourcePacksRequired(bool $value) : void{
        $this->serverForceResources = $value;
    }

    /** @return ResourcePack[] */
    public function getResourceStack() : array{
        return $this->resourcePacks;
    }

    /** @param ResourcePack[] $resourceStack */
    public function setResourceStack(array $resourceStack) : void{
        $this->resourcePacks = [];
        $this->uuidList = [];
        foreach($resourceStack as $pack){
            $uuid = strtolower($pack->getPackId());
            if(isset($this->uuidList[$uuid])){
                throw new \InvalidArgumentException("Cannot load two resource pack with the same UUID ($uuid)");
            }
            $this->uuidList[$uuid] = $pack;
            $this->resourcePacks[] = $pack;
        }
    }

    public function getPackById(string $id) : ?ResourcePack{
        return $this->uuidList[strtolower($id)] ?? null;
    }

    /** @return string[] */
    public function getPackIdList() : array{
        return array_keys($this->uuidList);
    }

    public function getPackEncryptionKey(string $id) : ?string{
        return $this->encryptionKeys[strtolower($id)] ?? null;
    }

    public function setPackEncryptionKey(string $id, ?string $key) : void{
        $id = strtolower($id);
        if($key === null){
            unset($this->encryptionKeys[$id]);
        }else{
            $this->encryptionKeys[$id] = $key;
        }
    }
}
