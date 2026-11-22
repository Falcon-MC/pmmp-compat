<?php

declare(strict_types=1);

namespace pmmpcompat;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml as SymfonyYaml;

/** YAML without the yaml extension, which the server's PHP is built without */
final class Yaml{
    private function __construct(){
    }

    /** the value, or false on a syntax error, as yaml_parse() gives */
    public static function parse(string $text) : mixed{
        try{
            return SymfonyYaml::parse($text);
        }catch(ParseException){
            return false;
        }
    }
}
