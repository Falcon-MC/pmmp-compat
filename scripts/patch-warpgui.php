<?php

declare(strict_types=1);

if($argc !== 3){
    fwrite(STDERR, "Usage: php -d phar.readonly=0 scripts/patch-warpgui.php <original.phar> <patched.phar>\n");
    exit(1);
}

$source = realpath($argv[1]);
$output = $argv[2];
$expectedHash = '9431343327f2141a1fce350676474c26be4e137e538d8922b00ee90cbb9a0f11';
if($source === false || !is_file($source) || hash_file('sha256', $source) !== $expectedHash){
    fwrite(STDERR, "Expected the original WarpGUI 1.3.0 PHAR from Poggit.\n");
    exit(1);
}
if(!str_ends_with($output, '.phar') || !is_dir(dirname($output)) || file_exists($output) || is_link($output)){
    fwrite(STDERR, "Choose a new .phar path in an existing directory.\n");
    exit(1);
}
if(ini_get('phar.readonly')){
    fwrite(STDERR, "Run PHP with -d phar.readonly=0.\n");
    exit(1);
}

$temporary = dirname($output) . '/.warpgui-' . bin2hex(random_bytes(8)) . '.phar';
$status = 0;
try{
    if(!copy($source, $temporary)){
        throw new RuntimeException('Could not copy the original archive');
    }
    $archive = new Phar($temporary);
    $command = 'src/ClickedTran/WarpGUI/commands/WarpGUICommands.php';
    $updated = $archive[$command]->getContent();
    foreach(['Create', 'Help', 'Setup', 'Remove', 'List'] as $subcommand){
        $constructor = "new {$subcommand}WarpCommand(";
        $updated = str_replace($constructor . '"', $constructor . '$this->getOwningPlugin(), "', $updated, $replacements);
        if($replacements !== 1){
            throw new RuntimeException("Could not identify the $subcommand subcommand constructor");
        }
    }
    $archive[$command] = $updated;
    $manifest = $archive['plugin.yml']->getContent();
    $parent = '  warpgui.command:';
    $newline = str_contains($manifest, "\r\n") ? "\r\n" : "\n";
    $setup = implode($newline, ['  warpgui.command.setup:', '    default: op', $parent]);
    $updated = str_replace($parent, $setup, $manifest, $replacements);
    if($replacements !== 1){
        throw new RuntimeException('Could not identify the setup command permission');
    }
    $archive['plugin.yml'] = $updated;
    unset($archive);
    if(!link($temporary, $output)){
        throw new RuntimeException('Could not create the patched archive at the requested path');
    }
    fwrite(STDOUT, "Created $output\n");
}catch(Throwable $error){
    fwrite(STDERR, $error->getMessage() . "\n");
    $status = 1;
}finally{
    if(is_file($temporary)){
        unlink($temporary);
    }
}
exit($status);
