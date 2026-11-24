# Calling Falcon services from PHP

A PocketMine-MP plugin can use `pmmpcompat\PluginServices` on Falcon's server thread.
Service names share Falcon's registry. Choose a namespace based on your plugin name.
Requests and replies are strings. JSON is suitable when a service needs structured data.

```php
use pmmpcompat\PluginServices;

// Inside PluginBase::onEnable().
PluginServices::register($this, 'my-plugin:greeting', static fn(string $name) => "Hello $name");

// From an enabled plugin.
$reply = PluginServices::call('my-plugin:greeting', 'Alex');
```

`register()` returns false if a service with that name already exists or the owner is disabled.
`exists()` checks availability and `provider()` returns the registered owner's name.
`call()` returns null if Falcon cannot call the service. An empty response is a valid reply.
A plugin can call `unregister($this, $name)` to remove one of its services. Disabling the
plugin removes all the services it owns.

Native Falcon plugins can find and call PHP services with the same API they use for native
services. Requests, replies and names cannot contain NUL bytes.

`PluginCommand::getOwningPlugin()` returns the PHP plugin that registered the command.
