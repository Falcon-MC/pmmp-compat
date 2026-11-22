<?php

declare(strict_types=1);

namespace pocketmine\command;

use pmmpcompat\Log;
use pocketmine\command\utils\CommandStringHelper;
use pocketmine\command\utils\InvalidCommandSyntaxException;
use pocketmine\lang\KnownTranslationFactory;
use pocketmine\lang\Translatable;
use pocketmine\Server;
use pocketmine\utils\TextFormat;

/**
 * The commands PocketMine-MP plugins register, by label, alias and fallbackPrefix:label as PocketMine-MP keeps them.
 *
 * Each command is also a Falcon command under its label, so players and the console type it as usual and Falcon
 * hands it back here. Falcon's own commands stay Falcon's: Server::dispatchCommand() falls back to them.
 */
class SimpleCommandMap implements CommandMap{
    /** @var Command[] */
    protected array $knownCommands = [];

    public function __construct(
        private Server $server
    ){
    }

    /**
     * @param Command[] $commands
     */
    public function registerAll(string $fallbackPrefix, array $commands) : void{
        foreach($commands as $command){
            $this->register($fallbackPrefix, $command);
        }
    }

    public function register(string $fallbackPrefix, Command $command, ?string $label = null) : bool{
        if(count($command->getPermissions()) === 0){
            throw new \InvalidArgumentException("Commands must have a permission set");
        }

        $label = trim($label ?? $command->getLabel());
        $fallbackPrefix = strtolower(trim($fallbackPrefix));

        $registered = $this->registerAlias($command, false, $fallbackPrefix, $label);

        $aliases = $command->getAliases();
        foreach($aliases as $index => $alias){
            if(!$this->registerAlias($command, true, $fallbackPrefix, $alias)){
                unset($aliases[$index]);
            }
        }
        $command->setAliases(array_values($aliases));

        if(!$registered){
            $command->setLabel($fallbackPrefix . ":" . $label);
        }

        $command->register($this);
        $this->registerInFalcon($command);
        return $registered;
    }

    private function registerInFalcon(Command $command) : void{
        $label = $command->getLabel();
        $description = $command->getDescription();
        $usage = $command->getUsage();
        $language = $this->server->getLanguage();
        if(!falcon_register_command($label,
            $description instanceof Translatable ? $language->translate($description) : $description,
            $usage instanceof Translatable ? $language->translate($usage) : $usage)){
            return;
        }
        if(count($command->getAliases()) > 0){
            falcon_command_set_aliases($label, $command->getAliases());
        }
    }

    public function unregister(Command $command) : bool{
        foreach($this->knownCommands as $label => $known){
            if($known === $command){
                unset($this->knownCommands[$label]);
            }
        }
        $command->unregister($this);
        return true;
    }

    private function registerAlias(Command $command, bool $isAlias, string $fallbackPrefix, string $label) : bool{
        $this->knownCommands[$fallbackPrefix . ":" . $label] = $command;
        if($isAlias && isset($this->knownCommands[$label])){
            return false;
        }
        if(isset($this->knownCommands[$label]) && $this->knownCommands[$label]->getLabel() === $label){
            return false;
        }
        if(!$isAlias){
            $command->setLabel($label);
        }
        $this->knownCommands[$label] = $command;
        return true;
    }

    /**
     * @param bool $reportUnknown tell the sender when no PocketMine-MP command has that name
     */
    public function dispatch(CommandSender $sender, string $commandLine, bool $reportUnknown = true) : bool{
        $args = CommandStringHelper::parseQuoteAware($commandLine);
        $sentCommandLabel = array_shift($args);
        if($sentCommandLabel !== null && ($target = $this->getCommand($sentCommandLabel)) !== null){
            $this->execute($sender, $target, $sentCommandLabel, $args);
            return true;
        }
        if($reportUnknown){
            $sender->sendMessage(KnownTranslationFactory::pocketmine_command_notFound($sentCommandLabel ?? "", "/help")
                ->prefix(TextFormat::RED));
        }
        return false;
    }

    /**
     * @internal what dispatch() and Falcon's command handler both run
     * @param string[] $args
     */
    public function execute(CommandSender $sender, Command $target, string $label, array $args) : void{
        try{
            if($target->testPermission($sender)){
                $target->execute($sender, $label, $args);
            }
        }catch(InvalidCommandSyntaxException){
            $sender->sendMessage($sender->getLanguage()->translate(
                KnownTranslationFactory::commands_generic_usage($target->getUsage())));
        }catch(\Throwable $e){
            Log::critical("An error happened running /$label for " . $sender->getName() . ": " . $e->getMessage());
            Log::exception($e);
            $sender->sendMessage((new Translatable("commands.generic.exception"))->prefix(TextFormat::RED));
        }
    }

    public function clearCommands() : void{
        foreach($this->knownCommands as $command){
            $command->unregister($this);
        }
        $this->knownCommands = [];
    }

    public function getCommand(string $name) : ?Command{
        return $this->knownCommands[$name] ?? null;
    }

    /**
     * @return Command[]
     */
    public function getCommands() : array{
        return $this->knownCommands;
    }
}
