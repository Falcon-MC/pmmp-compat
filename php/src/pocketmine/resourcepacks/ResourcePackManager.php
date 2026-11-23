<?php

declare(strict_types=1);

namespace pocketmine\resourcepacks;

/** The PHP plugin contribution to Falcon’s resource pack stack. */
class ResourcePackManager{
    /** @var ResourcePack[] */
    private array $resourcePacks = [];
    /** @var ResourcePack[] by lower-case uuid */
    private array $uuidList = [];
    /** @var string[] by lower-case uuid */
    private array $encryptionKeys = [];
    private bool $serverForceResources = false;
    private array $lastPublication = [[], [], false];

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
        $byId = [];
        foreach($resourceStack as $pack){
            $uuid = strtolower($pack->getPackId());
            if(isset($byId[$uuid])){
                throw new \InvalidArgumentException("Cannot load two resource packs with the same UUID ($uuid)");
            }
            $byId[$uuid] = $pack;
        }
        $this->uuidList = $byId;
        $this->resourcePacks = array_values($resourceStack);
    }

    /**
     * @internal Called after plugin startup and on ticks. Some plugins write the PocketMine fields by reflection.
     */
    public function publish() : void{
        $state = [$this->resourcePacks, $this->encryptionKeys, $this->serverForceResources];
        if($state === $this->lastPublication){
            return;
        }
        $this->lastPublication = $state;
        try{
            if(count($this->resourcePacks) > 128){
                throw new ResourcePackException("A plugin stack may contain at most 128 packs");
            }
            $total = 0;
            $packs = [];
            foreach($this->resourcePacks as $pack){
                $size = $pack->getPackSize();
                if($size <= 0 || $size > 128 * 1024 * 1024 - $total){
                    throw new ResourcePackException("Plugin resource pack stack exceeds the size limit");
                }
                $total += $size;
                $data = "";
                while(strlen($data) < $size){
                    $limit = min(1024 * 1024, $size - strlen($data));
                    $chunk = $pack->getPackChunk(strlen($data), $limit);
                    if($chunk === "" || strlen($chunk) > $limit){
                        throw new ResourcePackException("Invalid chunk in resource pack " . $pack->getPackName());
                    }
                    $data .= $chunk;
                }
                $packs[] = [$data, $this->getPackEncryptionKey($pack->getPackId()) ?? ""];
            }
            if(!falcon_server_replace_resource_packs($packs, $this->serverForceResources)){
                throw new ResourcePackException("Falcon did not accept the plugin resource pack stack");
            }
        }catch(\Throwable $e){
            \pmmpcompat\Log::warning("Could not publish plugin resource packs: " . $e->getMessage());
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
