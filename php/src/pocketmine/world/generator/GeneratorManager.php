<?php

declare(strict_types=1);

namespace pocketmine\world\generator;

use pocketmine\utils\SingletonTrait;
use pocketmine\utils\Utils;

/**
 * The world generators by name, as PocketMine-MP's register of them.
 *
 * Falcon generates its own worlds: "normal", "flat", "nether" and "the_end" stand for its generators, and the
 * generators plugins register are kept here, for the plugins that look them up.
 */
final class GeneratorManager{
    use SingletonTrait;

    /** @var GeneratorManagerEntry[] by lower-case name */
    private array $list = [];

    public function __construct(){
        $none = static fn(string $options) => null;
        foreach(["normal" => \pmmpcompat\world\FalconGenerator::class, "flat" => \pmmpcompat\world\FalconGenerator::class,
            "nether" => \pmmpcompat\world\FalconGenerator::class, "the_end" => \pmmpcompat\world\FalconGenerator::class] as $name => $class){
            $this->list[$name] = new GeneratorManagerEntry($class, $none, true);
        }
        $this->addAlias("normal", "default");
        $this->addAlias("nether", "hell");
    }

    /**
     * @phpstan-param class-string<Generator> $class
     * @phpstan-param \Closure(string $generatorOptions) : ?InvalidGeneratorOptionsException $presetValidator
     */
    public function addGenerator(string $class, string $name, \Closure $presetValidator, bool $overwrite = false, bool $fast = false) : void{
        Utils::testValidInstance($class, Generator::class);
        $name = strtolower($name);
        if(!$overwrite && isset($this->list[$name])){
            throw new \InvalidArgumentException("Alias \"$name\" is already assigned");
        }
        $this->list[$name] = new GeneratorManagerEntry($class, $presetValidator, $fast);
    }

    public function addAlias(string $name, string $alias) : void{
        $name = strtolower($name);
        $alias = strtolower($alias);
        if(!isset($this->list[$name])){
            throw new \InvalidArgumentException("Alias \"$name\" is not assigned");
        }
        if(isset($this->list[$alias])){
            throw new \InvalidArgumentException("Alias \"$alias\" is already assigned");
        }
        $this->list[$alias] = $this->list[$name];
    }

    /** @return string[] */
    public function getGeneratorList() : array{
        return array_keys($this->list);
    }

    public function getGenerator(string $name) : ?GeneratorManagerEntry{
        return $this->list[strtolower($name)] ?? null;
    }

    /** @phpstan-param class-string<Generator> $class */
    public function getGeneratorName(string $class) : string{
        Utils::testValidInstance($class, Generator::class);
        foreach($this->list as $name => $entry){
            if($entry->getGeneratorClass() === $class){
                return $name;
            }
        }
        throw new \InvalidArgumentException("Generator class $class is not registered");
    }
}
