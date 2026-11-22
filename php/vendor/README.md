# Bundled libraries

Copied as released, without their tests and command line tools. Each keeps its license file.

| Library | Version | Archive SHA-256 | License |
|---|---|---|---|
| [symfony/yaml](https://github.com/symfony/yaml) | v7.4.23 | `f54acbce8b86f9e56aca0de7f9209e9b9d8ef72e6b40992772b9103c4dbb8f3a` | MIT |
| [symfony/filesystem](https://github.com/symfony/filesystem) | v7.4.23 | `bd8e07d873f95aa56ccb0fb5c61a814e262954873d77bcfc2cca1a348f4c27df` | MIT |
| [pocketmine/math](https://github.com/pmmp/Math) | 1.0.0 | `8fc42ab26db14f76eeb08ee58700d9c9f74338537d62b000f0676e7c4304a5c9` | LGPL-3.0 |
| [pocketmine/nbt](https://github.com/pmmp/NBT) | 1.2.0 | `bddc55a3efa4d1e0ac23b2c7fb2b9dc8708f7ed2e411e0dcf97cdf6f61099524` | LGPL-3.0 |
| [pocketmine/color](https://github.com/pmmp/Color) | 0.3.1 | `57918710c63cadeb54041e9c249f91e5e6c36b237874d56dd488f8a0f3ab0c71` | LGPL-3.0 |
| [pocketmine/callback-validator](https://github.com/pmmp/CallbackValidator) | 1.0.4 | `bafb83af1857ae92384ddbb3ed7cf45215acdd262bff78c5eca9029911893cd0` | MIT |
| [symfony/deprecation-contracts](https://github.com/symfony/deprecation-contracts) | v3.8.1 | `23c2353ad960146de00ee3ec591d0b6a0828e1eb03d4131fcdd2a2ee7333d71e` | MIT |

The archives are GitHub's tag tarballs (`https://codeload.github.com/<owner>/<name>/tar.gz/refs/tags/<version>`).
symfony/yaml stands in for the yaml extension, which the server's PHP is built without. symfony/filesystem is the
`Path` and `Filesystem` PocketMine-MP ships and plugins use.

`php/src/log/`, `php/src/pocketmine/errorhandler/` and much of `php/src/pocketmine/` (events, commands, permissions,
chat, translations, utils) come from PocketMine-MP 5.44.3,
pocketmine/log 0.4.0 and pocketmine/errorhandler 0.7.1, under the LGPL v3 like this repository; their files keep their
copyright notices. `php/resources/locale/eng.ini` is this repository's own: PocketMine-MP's translation strings are
not bundled.
