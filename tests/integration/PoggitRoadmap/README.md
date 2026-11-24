# Poggit roadmap regression scenarios

The runner takes an installed pmmp-compat bundle, its matching Falcon binary and SDK checkout,
a Poggit release archive checkout, and the EconomyAPI 6.0.0-PM5 source plugin folder. It
copies the pinned PHARs from the supplied archive without changing them.

```sh
bash tests/integration/SensitiveCompatibilityEvents/build-client.sh /path/to/Falcon/build /tmp/roadmap-client \
    tests/integration/PoggitRoadmap/client.cpp

ROADMAP_CLIENT=/tmp/roadmap-client bash tests/integration/PoggitRoadmap/run.sh \
    /path/to/FalconServer /path/to/pmmp-compat /path/to/PluginAPI \
    /path/to/release-archive /path/to/EconomyAPI
```

Pass one of `builder`, `worlds`, `shop`, `skyblock`, `slapper`, `visibility`, or
`inventory` as a final argument to run only that profile. Each profile uses a new directory.
The runner reports the location of its logs. Set `PMMP_COMPAT_TEST_PORT` to choose the first
loopback port.

The protocol client completes the real ShopUI form exchange, attacks Slapper's advertised actor
and verifies that InvSee opens both inventory views. The server fixture checks the economy
balance, delivered items, Slapper command and saved NBT, and the contents of the InvSee views.
The other profiles verify BuilderTools' edits and async schematic, world creation/reload and
teleport, SkyBlock island terrain and player destination, and the native visibility state.
A native probe verifies the PHP service registry in both directions and after plugin disable.
