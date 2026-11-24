# Poggit economy transactions

BedrockEconomy 4.0.4 kept the legacy methods and renamed their implementation class to
`LegacyAPI`. The patcher restores its old `LegacyBEAPI` type name for the libPiggyEconomy
versions bundled with ChatGames, RankShopGUI, and XPShop.

Generate a patched copy from the original Poggit release with PHP CLI and Phar enabled.

```sh
php -d phar.readonly=0 scripts/patch-bedrockeconomy.php BedrockEconomy_4.0.4.phar BedrockEconomy_4.0.4-patched.phar
```

Stop the server and replace its BedrockEconomy PHAR with the patched copy. Keep
`plugin_data/BedrockEconomy/` as it holds the economy database and configuration.
Use `bedrockeconomy` as the economy provider in the three plugins and use RankSystem
with RankShopGUI.

ChestShop and BankGUI use the actual EconomyAPI plugin in separate server profiles.
The PocketMine-MP 5 version used in the test was EconomyAPI 6.0.0-PM5 at
[`d96e269`](https://github.com/ACM-PocketMine-MP/EconomyAPI/tree/d96e269de09da7494690b7fa459b5168880fc671).
Set ChestShop's `economy.plugin` to `EconomyAPI` and BankGUI's `economy-type.provider` to `economyapi`.

Place the following original archives and the EconomyAPI source folder in a fixture directory.

```text
BedrockEconomy_4.0.4.phar
ChatGames_1.1.0.phar
RankShopGUI_1.0.0.phar
RankSystem_1.1.4.phar
XPShop_2.1.0.phar
ChestShop_5.3.9.phar
BankGUI_1.0.0.phar
EconomyAPI/
```

```sh
bash tests/integration/PoggitEconomy/run.sh /path/to/FalconServer /path/to/dist/pmmp-compat /path/to/fixtures
```

The headless scenario seeds a local wallet for a simulated player. It checks a ChatGames
reward, a two-level XPShop sale, a RankShopGUI rank purchase, a ChestShop item purchase, and
a BankGUI deposit. Responses and clicks are supplied at the plugin event handlers.

An optional Bedrock protocol client also verifies that ChestShop opens its category and purchase windows in sequence.
The client acknowledges the first window's close and observes the chest block actor packets. The fixture injects the clicks
at the transaction event and verifies the wallet and the purchased diamonds.

```sh
bash tests/integration/SensitiveCompatibilityEvents/build-client.sh /path/to/Falcon/build /tmp/chest-client tests/integration/PoggitEconomy/chest-client.cpp
bash tests/integration/PoggitEconomy/run.sh /path/to/FalconServer /path/to/dist/pmmp-compat /path/to/fixtures /tmp/chest-client
```
