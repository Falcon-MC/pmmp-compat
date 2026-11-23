<?php

declare(strict_types=1);

namespace pocketmine\entity;

use DaveRandom\CallbackValidator\CallbackType;
use DaveRandom\CallbackValidator\ParameterType;
use DaveRandom\CallbackValidator\ReturnType;
use pocketmine\data\SavedDataLoadingException;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\utils\SingletonTrait;
use pocketmine\utils\Utils;
use pocketmine\world\World;

/**
 * Where plugins register the entity classes they make, with the save names their data goes by.
 *
 * The register is kept as PocketMine-MP keeps it. The entities it makes run in PHP as on PocketMine-MP, drawn for
 * clients by PHP; Falcon saves them with their chunk and they are made again from that data when it loads.
 */
final class EntityFactory{
    use SingletonTrait;

    public const TAG_IDENTIFIER = "identifier";
    public const TAG_LEGACY_ID = "id";

    /** @var \Closure[] by save name */
    private array $creationFuncs = [];
    /** @var string[] the first save name of each class */
    private array $saveNames = [];

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
        $func = $this->creationFuncs[$saveId->getValue()];
        try{
            /** @var Entity $entity */
            $entity = $func($world, $nbt);
        }catch(\InvalidArgumentException|\TypeError $e){
            throw new SavedDataLoadingException($e->getMessage(), 0, $e);
        }
        return $entity;
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
