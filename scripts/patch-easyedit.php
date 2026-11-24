<?php

declare(strict_types=1);

if ($argc !== 3) {
    fwrite(STDERR, "Usage: php -d phar.readonly=0 scripts/patch-easyedit.php <original.phar> <patched.phar>\n");
    exit(1);
}

$source = realpath($argv[1]);
$output = $argv[2];
$expectedHash = '40f44573aa2cc42f87b1947a766fa9e7df8ea240109e4f280ba9e5b8277899ff';
if ($source === false || !is_file($source) || hash_file('sha256', $source) !== $expectedHash) {
    fwrite(STDERR, "Expected the original EasyEdit 3.1.0 PHAR from Poggit.\n");
    exit(1);
}
if (!str_ends_with($output, '.phar') || !is_dir(dirname($output)) || file_exists($output) || is_link($output)) {
    fwrite(STDERR, "Choose a new .phar path in an existing directory.\n");
    exit(1);
}
if (ini_get('phar.readonly')) {
    fwrite(STDERR, "Run PHP with -d phar.readonly=0.\n");
    exit(1);
}

function replaceEasyEditCode(Phar $phar, string $file, string $from, string $to): void {
    $entry = 'src/platz1de/EasyEdit/' . $file;
    $code = str_replace($from, $to, $phar[$entry]->getContent(), $replacements);
    if ($replacements !== 1) {
        throw new RuntimeException("Could not identify the expected code in $file");
    }
    $phar[$entry] = $code;
}

$temporary = dirname($output) . '/.easyedit-' . bin2hex(random_bytes(8)) . '.phar';
$status = 0;
try {
    if (!copy($source, $temporary)) {
        throw new RuntimeException('Could not copy the original archive');
    }
    $phar = new Phar($temporary);
    $phar->startBuffering();

    // A worker can access its logger even when the main plugin singleton is unavailable there.
    replaceEasyEditCode($phar, 'world/HeightMapCache.php', <<<'PHP'
EasyEdit::getInstance()->getLogger()->error("Failed to load terrain-ignored-blocks, ignoring only air");
			self::$ignore = [BlockTypeIds::AIR];
PHP, <<<'PHP'
\platz1de\EasyEdit\thread\EditThread::getInstance()->getLogger()->warning("Failed to load terrain-ignored-blocks, ignoring only air");
			self::$ignore = [Block::EMPTY_STATE_ID];
PHP);

    replaceEasyEditCode($phar, 'environment/MainThreadHandler.php', <<<'PHP'
$c = clone $c;
			foreach ($c->getTiles() as $tile) {
				$c->removeTile($tile);
			}
PHP, <<<'PHP'
$c = \pmmpcompat\world\ChunkEdit::snapshot($chunk->getWorld(), $x, $z);
PHP);
    replaceEasyEditCode($phar, 'environment/MainThreadHandler.php',
        'return new InjectingSubChunkController($manager);', 'return new ChunkController($manager);');
    replaceEasyEditCode($phar, 'thread/chunk/ChunkRequestExecutor.php', <<<'PHP'
ChunkInformation::fromChunk(LoaderManager::getChunk($world, $x, $z))->putData($stream);
PHP, <<<'PHP'
$source = LoaderManager::getChunk($world, $x, $z);
		$tiles = array_map(static fn(\pocketmine\block\tile\Tile $tile) => $tile->saveNBT(), $source->getTiles());
		(new ChunkInformation(\pmmpcompat\world\ChunkEdit::snapshot($world, $x, $z), $tiles))->putData($stream);
PHP);

    // Falcon sends the actual changed blocks. The edited snapshot must not replace a whole live chunk.
    replaceEasyEditCode($phar, 'utils/LoaderManager.php', <<<'PHP'
$preparedInjections = [];
		foreach ($injections as $hash => $injection) {
			World::getBlockXYZ($hash, $x, $y, $z);
			$preparedInjections[$x][$z][$y] = $injection;
		}

		foreach ($chunks as $hash => $chunk) {
			World::getXZ($hash, $x, $z);
			self::injectChunk($world, $x, $z, $chunk, $preparedInjections[$x][$z] ?? []);
			$world->unloadChunk($x, $z);
		}
PHP, <<<'PHP'
\pmmpcompat\world\ChunkEdit::apply($world, array_map(static fn(ChunkInformation $chunk) => $chunk->getChunk(), $chunks));
PHP);
    $phar->stopBuffering();
    unset($phar);
    if (!link($temporary, $output)) {
        throw new RuntimeException('Could not create the patched archive at the requested path');
    }
    fwrite(STDOUT, "Created $output\n");
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    $status = 1;
} finally {
    if (is_file($temporary)) {
        unlink($temporary);
    }
}
exit($status);
