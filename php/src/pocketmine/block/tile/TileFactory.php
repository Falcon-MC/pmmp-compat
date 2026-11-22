<?php

declare(strict_types=1);

namespace pocketmine\block\tile;

use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\utils\SingletonTrait;
use pocketmine\utils\Utils;
use pocketmine\world\World;

/**
 * The tile classes by save name, as PocketMine-MP's, and which of Falcon's blocks carry which tile.
 */
final class TileFactory{
    use SingletonTrait;

    /** @var array<string, class-string<Tile>> */
    private array $knownTiles = [];
    /** @var array<class-string<Tile>, string> */
    private array $saveNames = [];

    /** the tile of each of Falcon's block entity blocks, by identifier or by its end */
    private const BLOCKS = [
        "minecraft:chest" => Chest::class,
        "minecraft:trapped_chest" => Chest::class,
        "minecraft:barrel" => Barrel::class,
        "minecraft:hopper" => Hopper::class,
        "minecraft:ender_chest" => EnderChest::class,
        "minecraft:furnace" => NormalFurnace::class,
        "minecraft:lit_furnace" => NormalFurnace::class,
        "minecraft:blast_furnace" => BlastFurnace::class,
        "minecraft:lit_blast_furnace" => BlastFurnace::class,
        "minecraft:smoker" => Smoker::class,
        "minecraft:lit_smoker" => Smoker::class,
        "minecraft:brewing_stand" => BrewingStand::class,
        "minecraft:beacon" => Beacon::class,
        "minecraft:bell" => Bell::class,
        "minecraft:bed" => Bed::class,
        "minecraft:cauldron" => Cauldron::class,
        "minecraft:powered_comparator" => Comparator::class,
        "minecraft:unpowered_comparator" => Comparator::class,
        "minecraft:daylight_detector" => DaylightSensor::class,
        "minecraft:daylight_detector_inverted" => DaylightSensor::class,
        "minecraft:enchanting_table" => EnchantTable::class,
        "minecraft:flower_pot" => FlowerPot::class,
        "minecraft:frame" => ItemFrame::class,
        "minecraft:glow_frame" => GlowingItemFrame::class,
        "minecraft:jukebox" => Jukebox::class,
        "minecraft:lectern" => Lectern::class,
        "minecraft:mob_spawner" => MonsterSpawner::class,
        "minecraft:skull" => MobHead::class,
        "minecraft:noteblock" => Note::class,
        "minecraft:campfire" => Campfire::class,
        "minecraft:soul_campfire" => Campfire::class,
        "minecraft:chiseled_bookshelf" => ChiseledBookshelf::class,
        "minecraft:spore_blossom" => SporeBlossom::class,
    ];

    private const ENDINGS = [
        "_shulker_box" => ShulkerBox::class,
        "shulker_box" => ShulkerBox::class,
        "_hanging_sign" => HangingSign::class,
        "_sign" => Sign::class,
        "_banner" => Banner::class,
        "_head" => MobHead::class,
        "_skull" => MobHead::class,
    ];

    public function __construct(){
        $this->register(Barrel::class, ["Barrel", "minecraft:barrel"]);
        $this->register(Banner::class, ["Banner", "minecraft:banner"]);
        $this->register(Beacon::class, ["Beacon", "minecraft:beacon"]);
        $this->register(Bed::class, ["Bed", "minecraft:bed"]);
        $this->register(Bell::class, ["Bell", "minecraft:bell"]);
        $this->register(BlastFurnace::class, ["BlastFurnace", "minecraft:blast_furnace"]);
        $this->register(BrewingStand::class, ["BrewingStand", "minecraft:brewing_stand"]);
        $this->register(Campfire::class, ["Campfire", "minecraft:campfire"]);
        $this->register(Cauldron::class, ["Cauldron", "minecraft:cauldron"]);
        $this->register(Chest::class, ["Chest", "minecraft:chest"]);
        $this->register(ChiseledBookshelf::class, ["ChiseledBookshelf", "minecraft:chiseled_bookshelf"]);
        $this->register(Comparator::class, ["Comparator", "minecraft:comparator"]);
        $this->register(DaylightSensor::class, ["DaylightDetector", "minecraft:daylight_detector"]);
        $this->register(EnchantTable::class, ["EnchantTable", "minecraft:enchanting_table"]);
        $this->register(EnderChest::class, ["EnderChest", "minecraft:ender_chest"]);
        $this->register(FlowerPot::class, ["FlowerPot", "minecraft:flower_pot"]);
        $this->register(NormalFurnace::class, ["Furnace", "minecraft:furnace"]);
        $this->register(GlowingItemFrame::class, ["GlowItemFrame"]);
        $this->register(HangingSign::class, ["HangingSign", "minecraft:hanging_sign"]);
        $this->register(Hopper::class, ["Hopper", "minecraft:hopper"]);
        $this->register(ItemFrame::class, ["ItemFrame"]);
        $this->register(Jukebox::class, ["Jukebox", "minecraft:jukebox"]);
        $this->register(Lectern::class, ["Lectern", "minecraft:lectern"]);
        $this->register(MonsterSpawner::class, ["MobSpawner", "minecraft:mob_spawner"]);
        $this->register(MobHead::class, ["Skull", "minecraft:skull"]);
        $this->register(Note::class, ["Music", "minecraft:noteblock"]);
        $this->register(ShulkerBox::class, ["ShulkerBox", "minecraft:shulker_box"]);
        $this->register(Sign::class, ["Sign", "minecraft:sign"]);
        $this->register(Smoker::class, ["Smoker", "minecraft:smoker"]);
        $this->register(SporeBlossom::class, ["SporeBlossom", "minecraft:spore_blossom"]);
    }

    /**
     * @param string[] $saveNames
     * @phpstan-param class-string<Tile> $className
     */
    public function register(string $className, array $saveNames = []) : void{
        Utils::testValidInstance($className, Tile::class);
        $shortName = (new \ReflectionClass($className))->getShortName();
        if(!in_array($shortName, $saveNames, true)){
            $saveNames[] = $shortName;
        }
        foreach($saveNames as $save){
            $this->knownTiles[$save] = $className;
        }
        $this->saveNames[$className] = reset($saveNames);
    }

    /** @return class-string<Tile>|null */
    public function getClassForBlock(string $identifier) : ?string{
        if(isset(self::BLOCKS[$identifier])){
            return self::BLOCKS[$identifier];
        }
        foreach(self::ENDINGS as $ending => $class){
            if(str_ends_with($identifier, $ending)){
                return $class;
            }
        }
        return null;
    }

    /** @internal */
    public function createFromData(World $world, CompoundTag $nbt) : ?Tile{
        $class = $this->knownTiles[$nbt->getString(Tile::TAG_ID, "")] ?? null;
        if($class === null){
            return null;
        }
        /** @var Tile $tile */
        $tile = new $class($world, new Vector3($nbt->getInt(Tile::TAG_X), $nbt->getInt(Tile::TAG_Y), $nbt->getInt(Tile::TAG_Z)));
        $tile->readSaveData($nbt);
        return $tile;
    }

    public function getSaveId(string $class) : string{
        if(isset($this->saveNames[$class])){
            return $this->saveNames[$class];
        }
        throw new \InvalidArgumentException("Tile $class is not registered");
    }
}
