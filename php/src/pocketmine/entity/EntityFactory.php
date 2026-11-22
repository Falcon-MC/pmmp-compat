<?php

declare(strict_types=1);

namespace pocketmine\entity;

use DaveRandom\CallbackValidator\CallbackType;
use DaveRandom\CallbackValidator\ParameterType;
use DaveRandom\CallbackValidator\ReturnType;
use pmmpcompat\Log;
use pocketmine\data\SavedDataLoadingException;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\utils\SingletonTrait;
use pocketmine\utils\Utils;
use pocketmine\world\World;

/**
 * Where plugins register the entity classes they make, with the save names their data goes by.
 *
 * The register is kept as PocketMine-MP keeps it, so the plugins that register at startup start. An entity a plugin
 * defines in PHP is not one of Falcon's: making one from its data gives nothing yet, which createFromData() logs.
 */
final class EntityFactory{
    use SingletonTrait;

    public const TAG_IDENTIFIER = "identifier";
    public const TAG_LEGACY_ID = "id";

    /** @var \Closure[] by save name */
    private array $creationFuncs = [];
    /** @var string[] the first save name of each class */
    private array $saveNames = [];
    /** @var array<string, true> the classes whose creation was refused once, logged once */
    private array $refused = [];

    /**
     * @param string[] $saveNames
     * @phpstan-param class-string<Entity> $className
     * @phpstan-param \Closure(World $world, CompoundTag $nbt) : Entity $creationFunc
     */
    public function register(string $className, \Closure $creationFunc, array $saveNames) : void{
        if(count($saveNames) === 0){
            throw new \InvalidArgumentException("At least one save name must be provided");
        }
        Utils::testValidInstance($className, Entity::class);
        Utils::validateCallableSignature(new CallbackType(
            new ReturnType(Entity::class),
            new ParameterType("world", World::class),
            new ParameterType("nbt", CompoundTag::class)
        ), $creationFunc);

        foreach($saveNames as $name){
            $this->creationFuncs[$name] = $creationFunc;
        }
        $this->saveNames[$className] = reset($saveNames);
    }

    public function isRegistered(string $class) : bool{
        return isset($this->saveNames[$class]);
    }

    /**
     * @throws SavedDataLoadingException
     */
    public function createFromData(World $world, CompoundTag $nbt) : ?Entity{
        $saveId = $nbt->getTag(self::TAG_IDENTIFIER) ?? $nbt->getTag(self::TAG_LEGACY_ID);
        if(!$saveId instanceof StringTag || !isset($this->creationFuncs[$saveId->getValue()])){
            return null;
        }
        $name = $saveId->getValue();
        if(!isset($this->refused[$name])){
            $this->refused[$name] = true;
            Log::warning("An entity saved as $name was not made: entities defined by PocketMine-MP plugins do not run on Falcon yet");
        }
        return null;
    }

    public function injectSaveId(string $class, CompoundTag $saveData) : void{
        if(!isset($this->saveNames[$class])){
            throw new \InvalidArgumentException("Entity $class is not registered");
        }
        $saveData->setTag(self::TAG_IDENTIFIER, new StringTag($this->saveNames[$class]));
    }

    public function getSaveId(string $class) : string{
        if(!isset($this->saveNames[$class])){
            throw new \InvalidArgumentException("Entity $class is not registered");
        }
        return $this->saveNames[$class];
    }
}
