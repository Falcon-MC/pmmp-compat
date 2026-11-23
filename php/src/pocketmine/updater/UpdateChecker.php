<?php

declare(strict_types=1);

namespace pocketmine\updater;

use pocketmine\Server;

class UpdateChecker{
    private ?UpdateInfo $update = null;

    public function __construct(private Server $server){
    }

    /** @internal Called when Falcon's startup update check finds a later release. */
    public function recordRelease(string $json) : bool{
        if(!$this->server->getConfigGroup()->getPropertyBool("auto-updater.enabled", true)){
            return false;
        }
        $data = json_decode($json, true);
        if(!is_array($data) || !is_string($data["tag_name"] ?? null) || $data["tag_name"] === ""){
            return false;
        }

        $info = new UpdateInfo();
        $info->php_version = PHP_VERSION;
        $info->base_version = ltrim($data["tag_name"], "vV");
        $info->is_dev = (bool) ($data["prerelease"] ?? false);
        $info->channel = $info->is_dev ? "beta" : "stable";
        $info->git_commit = (string) ($data["target_commitish"] ?? "");
        $info->mcpe_version = $this->server->getVersion();
        $info->build = 0;
        $info->date = strtotime((string) ($data["published_at"] ?? "")) ?: 0;
        $info->details_url = (string) ($data["html_url"] ?? "");
        $info->download_url = $info->details_url;
        $info->source_url = (string) ($data["tarball_url"] ?? "");
        $this->update = $info;
        return true;
    }

    public function hasUpdate() : bool{
        return $this->update !== null;
    }

    public function getUpdateInfo() : ?UpdateInfo{
        return $this->update;
    }

    public function getChannel() : string{
        return $this->update?->channel ?? "stable";
    }

    public function showConsoleUpdate() : void{
        if($this->update !== null){
            $this->server->getLogger()->warning("A newer version of Falcon is available: " . $this->update->details_url);
        }
    }
}
