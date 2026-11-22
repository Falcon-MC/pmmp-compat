<?php

declare(strict_types=1);

namespace pocketmine\network\mcpe\protocol\types\command;

/**
 * The command enum of older protocol versions, which split it into hard and soft enums: plugins built for those
 * (Commando among them) still make one, and pass it where a hard enum goes now.
 */
class CommandEnum extends CommandHardEnum{
    /**
     * @param string[] $enumValues
     */
    public function __construct(
        string $enumName,
        array $enumValues,
        private bool $isSoft = false
    ){
        parent::__construct($enumName, array_values($enumValues));
    }

    public function isSoft() : bool{
        return $this->isSoft;
    }

    /** the soft enum the protocol sends for one marked soft */
    public function toSoftEnum() : CommandSoftEnum{
        return new CommandSoftEnum($this->getName(), array_map(static fn($value) => (string) $value, $this->getValues()));
    }
}
