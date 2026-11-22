<?php

declare(strict_types=1);

// The yaml extension's functions, on top of symfony/yaml: PocketMine-MP and its plugins call them directly.
// The server's PHP is built without that extension, so these never clash with it.

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Inline;
use Symfony\Component\Yaml\Yaml;

const YAML_ANY_ENCODING = 0;
const YAML_UTF8_ENCODING = 1;
const YAML_UTF16LE_ENCODING = 2;
const YAML_UTF16BE_ENCODING = 3;
const YAML_ANY_BREAK = 0;
const YAML_CR_BREAK = 1;
const YAML_LN_BREAK = 2;
const YAML_CRLN_BREAK = 3;

/**
 * The documents of a YAML stream, split on --- and ended by ... as libyaml reads them.
 *
 * @return string[]
 */
function pmmpcompat_yaml_documents(string $input) : array{
    $input = str_replace(["\r\n", "\r"], "\n", $input);
    if(str_starts_with($input, "\xEF\xBB\xBF")){
        $input = substr($input, 3);
    }
    $documents = [];
    $current = [];
    $started = false;
    foreach(explode("\n", $input) as $line){
        if(preg_match('/^---(?:\s+(.*))?$/', $line, $match) === 1){
            if($started || count(array_filter($current, static fn(string $l) : bool => trim($l) !== '' && !str_starts_with(ltrim($l), '#'))) > 0){
                $documents[] = implode("\n", $current);
            }
            $current = isset($match[1]) && $match[1] !== '' ? [$match[1]] : [];
            $started = true;
            continue;
        }
        if(preg_match('/^\.\.\.\s*$/', $line) === 1){
            $documents[] = implode("\n", $current);
            $current = [];
            $started = false;
            continue;
        }
        if(!$started && str_starts_with($line, '%')){
            continue;
        }
        $current[] = $line;
    }
    if($started || count($documents) === 0 || trim(implode('', $current)) !== ''){
        $documents[] = implode("\n", $current);
    }
    return $documents;
}

/**
 * A document indented as a whole, which libyaml reads and symfony/yaml does not, moved back to the left edge.
 */
function pmmpcompat_yaml_dedent(string $document) : string{
    $lines = explode("\n", $document);
    $indent = null;
    foreach($lines as $line){
        if(trim($line) === ''){
            continue;
        }
        $width = strlen($line) - strlen(ltrim($line, ' '));
        $indent = $indent === null ? $width : min($indent, $width);
    }
    if($indent === null || $indent === 0){
        return $document;
    }
    return implode("\n", array_map(static fn(string $line) : string => substr($line, min($indent, strlen($line))), $lines));
}

/**
 * @param int $pos which document: 0 for the first, -1 for all of them as a list
 * @param mixed[] $callbacks not supported: tags are not resolved through callbacks
 */
function yaml_parse(string $input, int $pos = 0, mixed &$ndocs = null, array $callbacks = []) : mixed{
    try{
        $documents = array_map(static fn(string $document) : mixed => Yaml::parse(pmmpcompat_yaml_dedent($document)),
            pmmpcompat_yaml_documents($input));
    }catch(ParseException $e){
        falcon_warning('yaml_parse(): ' . $e->getMessage());
        return false;
    }
    $ndocs = count($documents);
    if($pos === -1){
        return $documents;
    }
    if(!array_key_exists($pos, $documents)){
        falcon_warning("yaml_parse(): end of stream reached without finding document $pos");
        return false;
    }
    return $documents[$pos];
}

/**
 * @param mixed[] $callbacks not supported
 */
function yaml_parse_file(string $filename, int $pos = 0, mixed &$ndocs = null, array $callbacks = []) : mixed{
    $input = @file_get_contents($filename);
    if($input === false){
        falcon_warning("yaml_parse_file(): could not read $filename");
        return false;
    }
    return yaml_parse($input, $pos, $ndocs, $callbacks);
}

/**
 * Block style, as libyaml writes it, between --- and ...
 *
 * @param mixed[] $callbacks not supported
 */
function yaml_emit(mixed $data, int $encoding = YAML_ANY_ENCODING, int $linebreak = YAML_ANY_BREAK, array $callbacks = []) : string{
    if(is_array($data) && count($data) > 0){
        $body = "---\n" . Yaml::dump($data, PHP_INT_MAX, 2, Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE);
    }else{
        $body = '--- ' . Inline::dump($data, Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE) . "\n";
    }
    $body .= "...\n";
    return match($linebreak){
        YAML_CR_BREAK => str_replace("\n", "\r", $body),
        YAML_CRLN_BREAK => str_replace("\n", "\r\n", $body),
        default => $body,
    };
}

/**
 * @param mixed[] $callbacks not supported
 */
function yaml_emit_file(string $filename, mixed $data, int $encoding = YAML_ANY_ENCODING, int $linebreak = YAML_ANY_BREAK, array $callbacks = []) : bool{
    return @file_put_contents($filename, yaml_emit($data, $encoding, $linebreak, $callbacks)) !== false;
}
