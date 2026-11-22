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
| [altayofficial/BedrockProtocol](https://github.com/altayofficial/BedrockProtocol), `pocketmine/bedrock-protocol` | bedrock-1.26.60 (`3f074f2`) | the commit's GitHub tarball | LGPL-3.0 |
| [ramsey/uuid](https://github.com/ramsey/uuid) | 4.9.3 | `55857b37190a6f6cb1141aa7e0533170ef01871f40def01c1930adc20866af14` | MIT |
| [ramsey/collection](https://github.com/ramsey/collection) | 2.1.1 | `54b69e8af6b249f27bc8b787cca58b41e8aba78272af8d41518bd1723181e931` | MIT |
| [brick/math](https://github.com/brick/math) | 0.13.1 | `ad05e9fc391722f263acca6df51fdcdb66ab948f22cfd530972d782b8853d884` | MIT |
| [pocketmine/snooze](https://github.com/pmmp/Snooze) | 0.5.0 | `560974741a144501c1c61d043321a40c42809500409e06c0145ed91268a5144b` | LGPL-3.0 |
| [netresearch/jsonmapper](https://github.com/cweiske/jsonmapper) | v5.0.1 | `b5435e71f9b972c42365dccd9bb2b631d8efdd62081caf9d9f627bcf2e6aa69f` | OSL-3.0 |
| [adhocore/json-comment](https://github.com/adhocore/php-json-comment) | 1.2.1 | `28767d0818e2f0df5e81415064483957ab7b7980ca92820fdb43f2ac4a10c36a` | MIT |
| [symfony/deprecation-contracts](https://github.com/symfony/deprecation-contracts) | v3.8.1 | `23c2353ad960146de00ee3ec591d0b6a0828e1eb03d4131fcdd2a2ee7333d71e` | MIT |

The archives are GitHub's tag tarballs (`https://codeload.github.com/<owner>/<name>/tar.gz/refs/tags/<version>`).
symfony/yaml stands in for the yaml extension, which the server's PHP is built without. `Inline.php` is patched to read
unquoted numbers with a leading zero as YAML 1.1 octal, as that extension does (`00`, `010`). `Parser.php` is patched
to take `null`, `true` and `false` mapping keys as that extension does (`""`, `1`, `0`). symfony/filesystem is the
`Path` and `Filesystem` PocketMine-MP ships and plugins use.

`php/src/log/`, `php/src/pocketmine/errorhandler/` and much of `php/src/pocketmine/` (events, commands, permissions,
chat, translations, utils) come from PocketMine-MP 5.44.3,
pocketmine/log 0.4.0 and pocketmine/errorhandler 0.7.1, under the LGPL v3 like this repository; their files keep their
copyright notices. `php/resources/locale/eng.ini` is this repository's own: PocketMine-MP's translation strings are
not bundled.

`third_party/ext-encoding/` is [pmmp/ext-encoding](https://github.com/pmmp/ext-encoding) 1.0.0 (MIT, archive SHA-256
`0a69c4e879eeb3ba71ffcf9fbee1fa35f5bec8a6e4686c412f3b2ec43c4f7bfe`), built into the plugin rather than into PHP:
BedrockProtocol reads and writes packets with it.

`scripts/build-php.sh` builds [pmmp/ext-pmmpthread](https://github.com/pmmp/ext-pmmpthread) 6.3.0 (PHP License,
archive SHA-256 `c9896660c2d8ca56eb66de2ac2805e68aef39431e901b015511d212b052d3d26`) and
[igbinary](https://github.com/igbinary/igbinary) 3.2.16 (BSD-3-Clause, archive SHA-256
`941f1cf2ccbecdc1c221dbfae9213439d334be5d490a2f3da2be31e8a00b0cdb`) into PHP. On Windows the PHP is
[PocketMine-MP's own build](https://github.com/pmmp/PHP-Binaries) 8.3.29, with the official build's embed library and
headers.
