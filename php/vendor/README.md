# Bundled libraries

Copied as released, without their tests and command line tools. Each keeps its license file.

| Library | Version | Archive SHA-256 | License |
|---|---|---|---|
| [symfony/yaml](https://github.com/symfony/yaml) | v7.4.23 | `f54acbce8b86f9e56aca0de7f9209e9b9d8ef72e6b40992772b9103c4dbb8f3a` | MIT |
| [symfony/filesystem](https://github.com/symfony/filesystem) | v7.4.23 | `bd8e07d873f95aa56ccb0fb5c61a814e262954873d77bcfc2cca1a348f4c27df` | MIT |
| [symfony/deprecation-contracts](https://github.com/symfony/deprecation-contracts) | v3.8.1 | `23c2353ad960146de00ee3ec591d0b6a0828e1eb03d4131fcdd2a2ee7333d71e` | MIT |

The archives are GitHub's tag tarballs (`https://codeload.github.com/symfony/<name>/tar.gz/refs/tags/<version>`).
symfony/yaml stands in for the yaml extension, which the server's PHP is built without. symfony/filesystem is the
`Path` and `Filesystem` PocketMine-MP ships and plugins use.

`php/src/log/`, `php/src/pocketmine/errorhandler/` and parts of `php/src/pocketmine/` come from PocketMine-MP 5.44.3,
pocketmine/log 0.4.0 and pocketmine/errorhandler 0.7.1, under the LGPL v3 like this repository; their files keep their
copyright notices.
