# Sensitive event integration fixture

The fixture starts a fresh Falcon server with pmmp-compat and connects a headless Bedrock protocol 2225 client. The PHP plugin checks these server-side effects.

- Cancelling packets before decoding and after decoding stops them before gameplay. A changed outgoing chat packet reaches the client.
- Creative picks keep the plugin's changes to the result for a block and a dropped item. The player's air swing reaches the listener.
- The plugin redirects an overworld respawn anchor from exploding to saving the respawn point. Cancelled bone meal leaves the wheat unchanged and the second use applies growth with the player and new block state in the event.
- The cancelled bow shot spends no ammo or durability. The successful arrow launches at the requested speed and its damage event identifies the arrow and shooter. A plugin-created snowball reaches the client and a cancelled replacement despawns.
- Canceled and non-block-breaking TNT explosions preserve their targets. A control explosion destroys its target.
- Cancelling an inventory transaction preserves the ingredient for retry. The rejected craft also leaves the ingredient available. The enchanting window produces one open and one close event.
- A custom enchantment offer reaches the client. The rejected selection leaves the offer and payment available for a successful retry, producing the requested enchantment.

Build the client using an existing Falcon CMake build with the default FetchContent layout. This build command was tested on macOS.

```sh
bash tests/integration/SensitiveCompatibilityEvents/build-client.sh /path/to/Falcon/build /tmp/sensitive-events-client
python3 tests/integration/SensitiveCompatibilityEvents/run.py \
  /path/to/Falcon/build/Falcon.Server/FalconServer \
  /path/to/dist/pmmp-compat \
  /tmp/sensitive-events-client
```

The runner logs the temporary instance directory, checks the result marker and shuts down the server. Pass `--port` to select a different local port.
