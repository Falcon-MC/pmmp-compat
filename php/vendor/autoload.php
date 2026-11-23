<?php

declare(strict_types=1);

// what a PocketMine-MP thread loads before its class loaders, as a composer autoloader would: the bundled
// libraries' functions; the runtime loads it on the main thread too
require_once __DIR__ . '/symfony/deprecation-contracts/function.php';
if(!function_exists('yaml_parse')){
    require_once __DIR__ . '/../src/functions/yaml.php';
}
require_once __DIR__ . '/ramsey/uuid/functions.php';
spl_autoload_register(static function(string $class) : void{
    if(str_starts_with($class, 'JsonMapper_')){
        $file = __DIR__ . '/netresearch/jsonmapper/' . str_replace('_', '/', $class) . '.php';
        if(is_file($file)){
            require $file;
        }
    }
});

// the paths PocketMine-MP's CoreConstants gives every thread; the server's data folders are the runtime's
foreach([
    'PATH' => dirname(__DIR__) . '/',
    'RESOURCE_PATH' => dirname(__DIR__) . '/resources/',
    'COMPOSER_AUTOLOADER_PATH' => __FILE__,
    'LOCALE_DATA_PATH' => dirname(__DIR__) . '/resources/locale/',
    'BEDROCK_ITEM_UPGRADE_SCHEMA_PATH' => dirname(__DIR__) . '/resources/bedrock-item-upgrade-schema/',
    'BEDROCK_BLOCK_UPGRADE_SCHEMA_PATH' => dirname(__DIR__) . '/resources/bedrock-block-upgrade-schema/',
    'BEDROCK_DATA_PATH' => dirname(__DIR__) . '/resources/bedrock-data/',
] as $name => $path){
    if(!defined('pocketmine\\' . $name)){
        define('pocketmine\\' . $name, $path);
    }
}
