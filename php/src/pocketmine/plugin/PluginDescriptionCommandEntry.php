<?php

declare(strict_types=1);

namespace pocketmine\plugin;

/** A command declared under plugin.yml's commands */
final class PluginDescriptionCommandEntry{
    /**
     * @param string[] $aliases
     */
    public function __construct(
        private ?string $description,
        private ?string $usageMessage,
        private array $aliases,
        private string $permission,
        private ?string $permissionDeniedMessage,
    ){
    }

    public function getDescription() : ?string{
        return $this->description;
    }

    public function getUsageMessage() : ?string{
        return $this->usageMessage;
    }

    /** @return string[] */
    public function getAliases() : array{
        return $this->aliases;
    }

    public function getPermission() : string{
        return $this->permission;
    }

    public function getPermissionDeniedMessage() : ?string{
        return $this->permissionDeniedMessage;
    }
}
