<?php

declare(strict_types=1);

namespace pocketmine\plugin;

/** What a plugin's plugin.yml says about it */
class PluginDescription{
    private const KEY_NAME = 'name';
    private const KEY_VERSION = 'version';
    private const KEY_MAIN = 'main';
    private const KEY_SRC_NAMESPACE_PREFIX = 'src-namespace-prefix';
    private const KEY_API = 'api';
    private const KEY_MCPE_PROTOCOL = 'mcpe-protocol';
    private const KEY_OS = 'os';
    private const KEY_DEPEND = 'depend';
    private const KEY_SOFTDEPEND = 'softdepend';
    private const KEY_LOADBEFORE = 'loadbefore';
    private const KEY_EXTENSIONS = 'extensions';
    private const KEY_WEBSITE = 'website';
    private const KEY_DESCRIPTION = 'description';
    private const KEY_LOGGER_PREFIX = 'prefix';
    private const KEY_LOAD = 'load';
    private const KEY_AUTHOR = 'author';
    private const KEY_AUTHORS = 'authors';
    private const KEY_PERMISSIONS = 'permissions';

    private const KEY_COMMANDS = 'commands';
    private const KEY_COMMAND_PERMISSION = 'permission';
    private const KEY_COMMAND_DESCRIPTION = self::KEY_DESCRIPTION;
    private const KEY_COMMAND_USAGE = 'usage';
    private const KEY_COMMAND_ALIASES = 'aliases';
    private const KEY_COMMAND_PERMISSION_MESSAGE = 'permission-message';

    /** @var mixed[] */
    private array $map;

    private string $name;
    private string $main;
    private string $srcNamespacePrefix = '';
    /** @var string[] */
    private array $api;
    /** @var int[] */
    private array $compatibleMcpeProtocols = [];
    /** @var string[] */
    private array $compatibleOperatingSystems = [];
    /** @var string[][] extension name => version constraints */
    private array $extensions = [];
    /** @var string[] */
    private array $depend = [];
    /** @var string[] */
    private array $softDepend = [];
    /** @var string[] */
    private array $loadBefore = [];
    private string $version;
    /** @var PluginDescriptionCommandEntry[] */
    private array $commands = [];
    private string $description = '';
    /** @var string[] */
    private array $authors = [];
    private string $website = '';
    private string $prefix = '';
    private PluginEnableOrder $order;

    /**
     * @param mixed[]|string $yamlString plugin.yml's text, or its parsed contents
     */
    public function __construct(array|string $yamlString){
        if(is_string($yamlString)){
            $map = yaml_parse($yamlString);
            if($map === false){
                throw new PluginDescriptionParseException('YAML parsing error in plugin manifest');
            }
            if(!is_array($map)){
                throw new PluginDescriptionParseException('Invalid structure of plugin manifest, expected array but have '
                    . get_debug_type($map));
            }
        }else{
            $map = $yamlString;
        }
        $this->loadMap($map);
    }

    /** @param mixed[] $plugin */
    private function loadMap(array $plugin) : void{
        $this->map = $plugin;

        foreach([self::KEY_NAME, self::KEY_VERSION, self::KEY_MAIN] as $key){
            if(!isset($plugin[$key]) || !is_scalar($plugin[$key])){
                throw new PluginDescriptionParseException("Invalid Plugin \"$key\"");
            }
        }

        $this->name = (string) $plugin[self::KEY_NAME];
        if(preg_match('/^[A-Za-z0-9 _.-]+$/', $this->name) === 0){
            throw new PluginDescriptionParseException('Invalid Plugin name');
        }
        $this->name = str_replace(' ', '_', $this->name);
        $this->version = (string) $plugin[self::KEY_VERSION];
        $this->main = (string) $plugin[self::KEY_MAIN];
        if(stripos($this->main, 'pocketmine\\') === 0){
            throw new PluginDescriptionParseException('Invalid Plugin main, cannot start within the PocketMine namespace');
        }

        $this->srcNamespacePrefix = (string) ($plugin[self::KEY_SRC_NAMESPACE_PREFIX] ?? '');

        $this->api = array_map('strval', (array) ($plugin[self::KEY_API] ?? []));
        $this->compatibleMcpeProtocols = array_map('intval', (array) ($plugin[self::KEY_MCPE_PROTOCOL] ?? []));
        $this->compatibleOperatingSystems = array_map('strval', (array) ($plugin[self::KEY_OS] ?? []));

        if(isset($plugin[self::KEY_COMMANDS]) && is_array($plugin[self::KEY_COMMANDS])){
            foreach($plugin[self::KEY_COMMANDS] as $commandName => $commandData){
                if(!is_string($commandName)){
                    throw new PluginDescriptionParseException('Invalid Plugin commands, key must be the name of the command');
                }
                if(!is_array($commandData)){
                    throw new PluginDescriptionParseException("Command $commandName has invalid properties");
                }
                if(!isset($commandData[self::KEY_COMMAND_PERMISSION]) || !is_string($commandData[self::KEY_COMMAND_PERMISSION])){
                    throw new PluginDescriptionParseException("Command $commandName does not have a valid permission set");
                }
                $this->commands[$commandName] = new PluginDescriptionCommandEntry(
                    $commandData[self::KEY_COMMAND_DESCRIPTION] ?? null,
                    $commandData[self::KEY_COMMAND_USAGE] ?? null,
                    (array) ($commandData[self::KEY_COMMAND_ALIASES] ?? []),
                    $commandData[self::KEY_COMMAND_PERMISSION],
                    $commandData[self::KEY_COMMAND_PERMISSION_MESSAGE] ?? null
                );
            }
        }

        if(isset($plugin[self::KEY_DEPEND])){
            $this->depend = array_values(array_map('strval', (array) $plugin[self::KEY_DEPEND]));
        }
        if(isset($plugin[self::KEY_EXTENSIONS])){
            $extensions = (array) $plugin[self::KEY_EXTENSIONS];
            // a plain list names the extensions without a version
            $isLinear = $extensions === array_values($extensions);
            foreach($extensions as $name => $constraints){
                if($isLinear){
                    $name = $constraints;
                    $constraints = '*';
                }
                $this->extensions[(string) $name] = array_values(array_map('strval',
                    is_array($constraints) ? $constraints : [$constraints]));
            }
        }

        $this->softDepend = array_values(array_map('strval', (array) ($plugin[self::KEY_SOFTDEPEND] ?? [])));
        $this->loadBefore = array_values(array_map('strval', (array) ($plugin[self::KEY_LOADBEFORE] ?? [])));
        $this->website = (string) ($plugin[self::KEY_WEBSITE] ?? '');
        $this->description = (string) ($plugin[self::KEY_DESCRIPTION] ?? '');
        $this->prefix = (string) ($plugin[self::KEY_LOGGER_PREFIX] ?? '');

        if(isset($plugin[self::KEY_LOAD])){
            $order = is_string($plugin[self::KEY_LOAD]) ? PluginEnableOrder::fromString($plugin[self::KEY_LOAD]) : null;
            if($order === null){
                throw new PluginDescriptionParseException('Invalid Plugin "' . self::KEY_LOAD . '"');
            }
            $this->order = $order;
        }else{
            $this->order = PluginEnableOrder::POSTWORLD;
        }

        $this->authors = [];
        if(isset($plugin[self::KEY_AUTHOR])){
            if(is_array($plugin[self::KEY_AUTHOR])){
                $this->authors = array_values(array_map('strval', $plugin[self::KEY_AUTHOR]));
            }else{
                $this->authors[] = (string) $plugin[self::KEY_AUTHOR];
            }
        }
        if(isset($plugin[self::KEY_AUTHORS])){
            foreach((array) $plugin[self::KEY_AUTHORS] as $author){
                $this->authors[] = (string) $author;
            }
        }
    }

    public function getFullName() : string{
        return $this->name . ' v' . $this->version;
    }

    /** @return string[] */
    public function getCompatibleApis() : array{
        return $this->api;
    }

    /** @return int[] */
    public function getCompatibleMcpeProtocols() : array{
        return $this->compatibleMcpeProtocols;
    }

    /** @return string[] */
    public function getCompatibleOperatingSystems() : array{
        return $this->compatibleOperatingSystems;
    }

    /** @return string[] */
    public function getAuthors() : array{
        return $this->authors;
    }

    public function getPrefix() : string{
        return $this->prefix;
    }

    /** @return PluginDescriptionCommandEntry[] */
    public function getCommands() : array{
        return $this->commands;
    }

    /** @return string[][] */
    public function getRequiredExtensions() : array{
        return $this->extensions;
    }

    /** @return string[] */
    public function getDepend() : array{
        return $this->depend;
    }

    public function getDescription() : string{
        return $this->description;
    }

    /** @return string[] */
    public function getLoadBefore() : array{
        return $this->loadBefore;
    }

    public function getMain() : string{
        return $this->main;
    }

    public function getSrcNamespacePrefix() : string{
        return $this->srcNamespacePrefix;
    }

    public function getName() : string{
        return $this->name;
    }

    public function getOrder() : PluginEnableOrder{
        return $this->order;
    }

    /** @return string[] */
    public function getSoftDepend() : array{
        return $this->softDepend;
    }

    public function getVersion() : string{
        return $this->version;
    }

    public function getWebsite() : string{
        return $this->website;
    }

    /** @return mixed[] the whole of plugin.yml, keys this class does not read included */
    public function getMap() : array{
        return $this->map;
    }
}
