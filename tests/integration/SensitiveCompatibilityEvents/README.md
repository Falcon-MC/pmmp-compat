# Sensitive event integration fixture

The fixture starts a fresh Falcon server with an installed pmmp-compat plugin and connects a headless Bedrock protocol 2225 client. The client performs two shots, crafts the same recipe twice, and retries an enchantment. The PHP plugin checks these server-side effects.

- The canceled bow shot spends no ammo or durability. The successful arrow launches at the plugin's requested speed.
- Canceled and non-block-breaking TNT explosions preserve their targets. A control explosion destroys its target.
- The rejected craft leaves the ingredient available for a successful retry.
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
