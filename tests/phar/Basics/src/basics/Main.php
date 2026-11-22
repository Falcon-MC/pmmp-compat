<?php

declare(strict_types=1);

namespace basics;

use pocketmine\plugin\PluginBase;
use pocketmine\utils\Config;
use pocketmine\utils\ConfigLoadException;
use pocketmine\utils\TextFormat;
use pocketmine\utils\Utils;
use Symfony\Component\Filesystem\Path;

// what plugins do in onEnable: the logger, config.yml, resources, Config files and TextFormat
final class Main extends PluginBase{
    /** @var string[] */
    private array $failures = [];
    private int $checks = 0;

    private function check(string $name, bool $passed) : void{
        $this->checks++;
        if(!$passed){
            $this->failures[] = $name;
        }
    }

    protected function onEnable() : void{
        $logger = $this->getLogger();
        $logger->info("logger says hello");
        $logger->notice("logger gives notice");
        $logger->warning("logger warns");
        $logger->error("logger errs");
        $logger->debug("logger debug stays hidden");
        $logger->logException(new \RuntimeException("an exception logged on purpose"));

        $data = $this->getDataFolder();
        $this->check("saveDefaultConfig copies config.yml", $this->saveDefaultConfig() && is_file($data . "config.yml"));
        $this->check("saveDefaultConfig keeps an existing config.yml", !$this->saveDefaultConfig());
        $config = $this->getConfig();
        $this->check("getConfig reads a value", $config->get("greeting") === "hello");
        $this->check("getNested reads a nested value", $config->getNested("nested.answer") === 42);
        $this->check("getNested reads a list", $config->getNested("nested.list") === ["one", "two"]);
        $this->check("get gives the default for a missing key", $config->get("missing", "fallback") === "fallback");
        $config->set("visits", 3);
        $config->setNested("nested.added", true);
        $this->saveConfig();
        $this->reloadConfig();
        $this->check("saveConfig and reloadConfig keep the changes", $this->getConfig()->get("visits") === 3
            && $this->getConfig()->getNested("nested.added") === true);

        $this->check("saveResource copies a nested resource", $this->saveResource("extra/notes.txt")
            && file_get_contents($data . "extra/notes.txt") === "notes from the phar\n");
        $this->check("saveResource leaves an existing copy", !$this->saveResource("extra/notes.txt"));
        $stream = $this->getResource("extra/notes.txt");
        $this->check("getResource opens a stream inside the phar", is_resource($stream)
            && stream_get_contents($stream) === "notes from the phar\n");
        $this->check("getResource gives null for a missing resource", $this->getResource("nothing.txt") === null);
        $this->check("getResources lists the resources", count($this->getResources()) === 2);

        $json = new Config($data . "settings.json", Config::DETECT, ["volume" => 5]);
        $json->set("muted", false);
        $json->save();
        $this->check("a JSON config keeps its values", (new Config($data . "settings.json"))->getAll()
            === ["volume" => 5, "muted" => false]);
        $properties = new Config($data . "server.properties", Config::PROPERTIES, ["motd" => "Hi", "pvp" => true]);
        $properties->save();
        $reread = new Config($data . "server.properties", Config::PROPERTIES);
        $this->check("a properties config keeps its values", $reread->get("motd") === "Hi" && $reread->get("pvp") === true);
        $list = new Config($data . "banned.txt", Config::ENUM);
        $list->set("steve");
        $list->save();
        $this->check("an enum config keeps its entries", (new Config($data . "banned.txt"))->exists("steve"));

        // a config.yml written by PocketMine-MP itself, between the markers its YAML extension writes
        file_put_contents($data . "old.yml", "---\nname: old\nitems:\n- a\n- b\nsub:\n  key: value\n...\n");
        $old = new Config($data . "old.yml");
        $this->check("a config written by PocketMine-MP reads back", $old->get("items") === ["a", "b"]
            && $old->getNested("sub.key") === "value");
        file_put_contents($data . "indented.yml", "---\n  # a whole document indented\n  prefix: \"[GM]\"\n  menu:\n    title: Menu\n...\n");
        $indented = new Config($data . "indented.yml");
        $this->check("a document indented as a whole reads back", $indented->get("prefix") === "[GM]"
            && $indented->getNested("menu.title") === "Menu");
        file_put_contents($data . "broken.yml", "key: [unclosed\n");
        try{
            new Config($data . "broken.yml");
            $this->check("broken YAML is refused", false);
        }catch(ConfigLoadException){
            $this->check("broken YAML is refused", true);
        }

        $value = ["a" => [1, 2], "b" => "text", "c" => ["d" => null]];
        $this->check("yaml_emit and yaml_parse round trip", yaml_parse(yaml_emit($value)) === $value);
        $this->check("yaml_emit writes the markers", str_starts_with(yaml_emit($value), "---\n")
            && str_ends_with(yaml_emit($value), "...\n"));

        $this->check("TextFormat colorizes", TextFormat::colorize("&aGreen&r") === TextFormat::GREEN . "Green" . TextFormat::RESET);
        $this->check("TextFormat cleans", TextFormat::clean(TextFormat::RED . "Red" . TextFormat::BOLD . "!") === "Red!");
        $this->check("TextFormat has its colours", TextFormat::RED === "\u{00a7}c" && TextFormat::ESCAPE === "\u{00a7}");

        $this->check("mbstring counts characters", mb_strlen("héllo") === 5 && mb_strtoupper("é") === "É");
        try{
            file_get_contents($data . "does-not-exist");
            $this->check("warnings are thrown", false);
        }catch(\ErrorException){
            $this->check("warnings are thrown", true);
        }
        $this->check("Path joins", Path::join("a", "b", "../c") === "a/c");
        $this->check("Utils names the system", in_array(Utils::getOS(), ["linux", "mac", "win", "bsd"], true));

        $passed = $this->checks - count($this->failures);
        $logger->info("fixture: Basics $passed of $this->checks checks passed");
        foreach($this->failures as $failure){
            $logger->error("fixture: Basics check failed: $failure");
        }
    }
}
