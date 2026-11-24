<?php

declare(strict_types=1);

$archives = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
foreach($archives as $path => $target){
    try{
        $archive = new Phar($path);
        if(!isset($archive['plugin.yml'])){
            throw new RuntimeException('missing plugin.yml');
        }
        $prefix = 'phar://' . $archive->getPath() . '/';
        $files = new RecursiveIteratorIterator($archive);
        foreach($files as $file){
            if(!$file->isFile() || !in_array(strtolower($file->getExtension()), ['php', 'yml'], true)){
                continue;
            }
            $source = $file->getPathname();
            if(!str_starts_with($source, $prefix)){
                throw new RuntimeException('unexpected archive path');
            }
            $relative = substr($source, strlen($prefix));
            if(preg_match('~(^|/)(?:\.|\.\.)(?:/|$)|^/|[\\\\:]~', $relative)){
                throw new RuntimeException('invalid archive entry ' . $relative);
            }
            $dest = $target . '/' . $relative;
            if(!is_dir(dirname($dest)) && !mkdir(dirname($dest), 0700, true)){
                throw new RuntimeException('could not create extraction directory');
            }
            $contents = file_get_contents($source);
            if($contents === false || file_put_contents($dest, $contents) === false){
                throw new RuntimeException('could not extract ' . $relative);
            }
        }
        if(!touch($target . '/.complete')){
            throw new RuntimeException('could not mark extraction complete');
        }
    }catch(Throwable $error){
        fwrite(STDERR, basename($path) . ': ' . $error->getMessage() . "\n");
    }
}
