<?php

declare(strict_types=1);

/** @return Generator<string, SplFileInfo> */
function filesUnder(string $directory) : Generator{
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
    foreach($files as $name => $file){
        if($file->isFile() && !$file->isLink()){
            yield $name => $file;
        }
    }
}

/** @return list<string> */
function pocketMineReferences(string $source) : array{
    $tokens = array_values(array_filter(token_get_all($source), static fn($token) =>
        !is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
    $references = [];
    $imports = [];
    $usedNamespaces = [];
    $record = static function(string $name) use (&$references) : void{
        $name = ltrim($name, '\\');
        if(str_starts_with(strtolower($name), 'pocketmine\\')){
            $references[strtolower($name)] = $name;
        }
    };
    $finishNamespace = static function() use (&$imports, &$usedNamespaces, $record) : void{
        foreach(array_diff_key($imports, $usedNamespaces) as $name){
            // PHP also permits imports of a namespace, conventionally lowercase.
            if(preg_match('/(?:^|\\\\)[A-Z][^\\\\]*$/', $name)){
                $record($name);
            }
        }
        $imports = $usedNamespaces = [];
    };

    $skipThrough = -1;
    foreach($tokens as $index => $token){
        if($index <= $skipThrough || !is_array($token)){
            continue;
        }
        [$kind, $value] = $token;
        if($kind === T_NAMESPACE){
            $finishNamespace();
            $skipThrough = $index + 1;
            continue;
        }
        if($kind === T_USE && (($tokens[$index + 1] ?? null) !== '(')){
            $start = $index + 1;
            $end = $start;
            while(isset($tokens[$end]) && !in_array($tokens[$end], [';', '}'], true)){
                ++$end;
            }
            if($index > 0 && $tokens[$index - 1] === ')'){
                continue;
            }
            $statement = implode('', array_map(static fn($part) => is_array($part) ?
                ($part[0] === T_AS || $part[0] === T_FUNCTION || $part[0] === T_CONST ? ' ' . $part[1] . ' ' : $part[1]) :
                $part, array_slice($tokens, $start, $end - $start)));
            $skipThrough = $end;
            $prefix = '';
            if(str_contains($statement, '{')){
                [$prefix, $statement] = explode('{', $statement, 2);
            }
            foreach(explode(',', $statement) as $item){
                $item = trim($item);
                if($item === '' || preg_match('/^(?:const|function)\s/i', $item)){
                    continue;
                }
                [$name, $alias] = array_pad(preg_split('/\s+as\s+/i', $item, 2), 2, null);
                $name = ltrim($prefix . $name, '\\');
                $imports[strtolower($alias ?? substr(strrchr('\\' . $name, '\\'), 1))] = $name;
            }
            continue;
        }
        if(!in_array($kind, [T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED], true)){
            continue;
        }
        // Skip tokens belonging to an import statement or namespace declaration.
        $previous = $tokens[$index - 1] ?? null;
        if(is_array($previous) && in_array($previous[0], [T_NAMESPACE, T_USE, T_FUNCTION, T_CONST, T_AS], true)){
            continue;
        }
        if($kind === T_STRING){
            $alias = strtolower($value);
            if(isset($imports[$alias])){
                $record($imports[$alias]);
            }
            continue;
        }
        [$first, $suffix] = array_pad(explode('\\', $value, 2), 2, '');
        $alias = strtolower($first);
        $next = $tokens[$index + 1] ?? null;
        if($next === '(' && (!is_array($previous) || $previous[0] !== T_NEW)){
            continue;
        }
        if(isset($imports[$alias])){
            $usedNamespaces[$alias] = true;
            $record($imports[$alias] . '\\' . $suffix);
        }elseif(preg_match('/\\\\[A-Z][^\\\\]*$/', $value)){
            $record($value);
        }
    }

    $finishNamespace();
    natcasesort($references);
    return array_values($references);
}

if($argc < 2 || $argc > 3 || ($argc === 3 && $argv[2] !== '--json') || !is_dir($argv[1])){
    fwrite(STDERR, "Usage: php scripts/compatibility-report.php <plugins-directory> [--json]\n");
    exit(2);
}

$available = [];
foreach(filesUnder(__DIR__ . '/../php') as $file){
    if($file->getExtension() !== 'php'){
        continue;
    }
    $tokens = array_values(array_filter(token_get_all(file_get_contents($file->getPathname())), static fn($token) =>
        !is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
    $namespace = '';
    foreach($tokens as $index => $token){
        if(!is_array($token)){
            continue;
        }
        $next = $tokens[$index + 1] ?? null;
        if($token[0] === T_NAMESPACE){
            $namespace = is_array($next) ? strtolower($next[1]) . '\\' : '';
        }elseif(str_starts_with($namespace, 'pocketmine\\') &&
            in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true) && is_array($next) && $next[0] === T_STRING){
            $available[$namespace . strtolower($next[1])] = true;
        }
    }
}

$reports = [];
$entries = new FilesystemIterator($argv[1], FilesystemIterator::SKIP_DOTS);
foreach($entries as $entry){
    if($entry->isLink()){
        continue;
    }
    $path = $entry->getPathname();
    $name = $entry->getFilename();
    try{
        if($entry->isFile() && strtolower($entry->getExtension()) === 'phar'){
            $archive = new Phar($path);
            if(!isset($archive['plugin.yml'])){
                throw new RuntimeException('missing plugin.yml');
            }
            $files = new RecursiveIteratorIterator($archive);
        }elseif($entry->isDir() && is_file($path . '/plugin.yml')){
            $files = filesUnder($path);
        }else{
            continue;
        }
        $missing = [];
        foreach($files as $file){
            if(!$file->isFile() || strtolower($file->getExtension()) !== 'php'){
                continue;
            }
            $source = file_get_contents($file->getPathname());
            if($source === false){
                throw new RuntimeException('cannot read ' . $file->getFilename());
            }
            foreach(pocketMineReferences($source) as $class){
                if(!isset($available[strtolower($class)])){
                    $missing[strtolower($class)] = $class;
                }
            }
        }
        natcasesort($missing);
        $reports[$name] = ['missing_classes' => array_values($missing)];
    }catch(Throwable $error){
        $reports[$name] = ['error' => $error->getMessage()];
    }
}

ksort($reports, SORT_NATURAL | SORT_FLAG_CASE);
if($argc === 3){
    echo json_encode((object) $reports, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR) . "\n";
}else{
    foreach($reports as $name => $result){
        if(isset($result['error'])){
            echo "$name: cannot scan (" . $result['error'] . ")\n";
        }elseif($result['missing_classes'] === []){
            echo "$name: no missing PocketMine-MP classes detected\n";
        }else{
            echo "$name:\n  " . implode("\n  ", $result['missing_classes']) . "\n";
        }
    }
}
