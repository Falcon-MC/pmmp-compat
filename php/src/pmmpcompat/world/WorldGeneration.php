<?php

declare(strict_types=1);

namespace pmmpcompat\world;

use pmmpcompat\Bridge;
use pmmpcompat\Log;
use pocketmine\event\world\ChunkPopulateEvent;
use pocketmine\nbt\LittleEndianNbtSerializer;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\TreeRoot;
use pocketmine\utils\Filesystem;
use pocketmine\world\generator\Generator;
use pocketmine\world\World;
use pocketmine\world\WorldCreationOptions;

/** Prepares Bedrock world metadata before Falcon opens a new world. */
final class WorldGeneration{
    private const GENERATOR_FILE = 'compat-generator.json';
    /** @var array<string, Generator> by world folder */
    private static array $generators = [];

    private function __construct(){
    }

    /** @internal The directory is resolved only for a single valid world name. */
    public static function directory(string $name) : ?string{
        if($name === '' || strlen($name) > 64 || $name === '.' || $name === '..' ||
            strpbrk($name, "/\\:*?\"<>|") !== false || preg_match('/[\x00-\x1f]/', $name)){
            return null;
        }
        return \pocketmine\Server::getInstance()->getDataPath() . 'worlds/' . $name;
    }

    public static function prepare(string $directory, string $name, WorldCreationOptions $options) : void{
        $class = $options->getGeneratorClass();
        $custom = !is_a($class, FalconGenerator::class, true);
        $tag = CompoundTag::create()
            ->setString('LevelName', $name)
            ->setInt('Difficulty', $options->getDifficulty())
            ->setLong('RandomSeed', $options->getSeed());

        if($custom || $class === FalconFlatGenerator::class){
            $layers = $class === FalconFlatGenerator::class ? [
                ['block_name' => 'minecraft:air', 'count' => 64],
                ['block_name' => 'minecraft:bedrock', 'count' => 1],
                ['block_name' => 'minecraft:dirt', 'count' => 2],
                ['block_name' => 'minecraft:grass_block', 'count' => 1],
            ] : [['block_name' => 'minecraft:air', 'count' => 1]];
            $tag->setInt('Generator', 2)->setString('FlatWorldLayers', json_encode([
                'world_version' => 'version.post_1_18',
                'encoding_version' => 6,
                'biome_id' => 1,
                'block_layers' => $layers,
            ], JSON_THROW_ON_ERROR));
        }
        if(($spawn = $options->getRequestedSpawn()) !== null){
            $tag->setInt('SpawnX', $spawn->getFloorX())->setInt('SpawnY', $spawn->getFloorY())
                ->setInt('SpawnZ', $spawn->getFloorZ());
        }elseif($custom){
            // Empty terrain has no native land spawn. Custom generators may set their own after creation.
            $tag->setInt('SpawnX', 0)->setInt('SpawnY', 64)->setInt('SpawnZ', 0);
        }

        $data = (new LittleEndianNbtSerializer())->write(new TreeRoot($tag));
        Filesystem::safeFilePutContents($directory . '/level.dat', pack('VV', 10, strlen($data)) . $data);
        if($custom){
            $preset = $options->getGeneratorOptions();
            $generator = new $class($options->getSeed(), $preset);
            Bridge::listenFor(ChunkPopulateEvent::class);
            Filesystem::safeFilePutContents($directory . '/' . self::GENERATOR_FILE,
                json_encode(['class' => $class, 'seed' => $options->getSeed(), 'preset' => $preset], JSON_THROW_ON_ERROR));
            self::$generators[$name] = $generator;
        }
    }

    /** @internal */
    public static function forget(string $name) : void{
        unset(self::$generators[$name]);
    }

    /** @internal Restores a previously registered generator when Falcon opens its world. */
    public static function load(string $name) : void{
        if(array_key_exists($name, self::$generators)){
            return;
        }
        $directory = self::directory($name);
        if($directory === null || !is_file($file = $directory . '/' . self::GENERATOR_FILE)){
            return;
        }
        try{
            $data = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            $class = $data['class'];
            if(!is_subclass_of($class, Generator::class)){
                throw new \RuntimeException("Generator $class is unavailable");
            }
            self::$generators[$name] = new $class($data['seed'], $data['preset']);
            Bridge::listenFor(ChunkPopulateEvent::class);
        }catch(\Throwable $error){
            Log::critical("Could not restore the generator for '$name': " . $error->getMessage());
        }
    }

    public static function hasCustomGenerator(string $name) : bool{
        return isset(self::$generators[$name]);
    }

    /** @internal Runs PHP generators for fresh terrain before clients receive it. */
    public static function onChunkPopulated(World $world, int $x, int $z) : void{
        $generator = self::$generators[$world->getFolderName()] ?? null;
        if($generator !== null){
            $generator->generateChunk($world, $x, $z);
            $generator->populateChunk($world, $x, $z);
        }
    }
}
