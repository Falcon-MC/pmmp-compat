#include "Network/Client/ClientNetworkSystem.h"
#include "Protocol/Packets/AddActorPacket.h"
#include "Protocol/Packets/ActorPickRequestPacket.h"
#include "Protocol/Packets/BlockPickRequestPacket.h"
#include "Protocol/Packets/ContainerClosePacket.h"
#include "Protocol/Packets/ContainerOpenPacket.h"
#include "Protocol/Packets/CraftingDataPacket.h"
#include "Protocol/Packets/InventoryTransactionPacket.h"
#include "Protocol/Packets/ItemRegistryPacket.h"
#include "Protocol/Packets/ItemStackRequestPacket.h"
#include "Protocol/Packets/ItemStackResponsePacket.h"
#include "Protocol/Packets/PlayerAuthInputPacket.h"
#include "Protocol/Packets/PlayerEnchantOptionsPacket.h"
#include "Protocol/Packets/TextPacket.h"

#include <chrono>
#include <initializer_list>
#include <iostream>
#include <sstream>
#include <stdexcept>
#include <string>
#include <thread>
#include <utility>

using namespace std::chrono_literals;

namespace {
    void expect(bool condition, const char *message) {
        if (!condition)
            throw std::runtime_error(message);
    }

    template<typename Test>
    void until(BedrockConnection &connection, Test test, const char *description) {
        const auto deadline = std::chrono::steady_clock::now() + 6s;
        while (std::chrono::steady_clock::now() < deadline) {
            connection.update();
            connection.flush();
            while (auto packet = connection.receive()) {
                if (test(*packet))
                    return;
            }
            expect(!connection.isClosed(), "connection closed");
            std::this_thread::sleep_for(10ms);
        }
        throw std::runtime_error(std::string(description) + ": " + connection.getLastDecodeError());
    }

    void tick(BedrockConnection &connection, std::chrono::milliseconds duration) {
        const auto deadline = std::chrono::steady_clock::now() + duration;
        while (std::chrono::steady_clock::now() < deadline) {
            connection.update();
            connection.flush();
            std::this_thread::sleep_for(10ms);
        }
    }

    ItemStackRequestSlotData slot(ContainerSlotType type, int index) {
        ItemStackRequestSlotData result;
        result.mContainer = type;
        result.mContainerName.mContainer = type;
        result.mSlot = index;
        return result;
    }

    ItemStackRequestAction action(ItemStackRequestActionType type, int count, ItemStackRequestSlotData source,
                                 ItemStackRequestSlotData destination = {}) {
        ItemStackRequestAction result;
        result.mType = type;
        result.mCount = count;
        result.mSource = source;
        result.mDestination = destination;
        return result;
    }

    ItemStackRequestAction recipe(int id) {
        ItemStackRequestAction result;
        result.mType = ItemStackRequestActionType::CraftRecipe;
        result.mRecipeNetworkId = id;
        result.mNumberOfRequestedCrafts = 1;
        return result;
    }

    void send(BedrockConnection &connection, int id, std::initializer_list<ItemStackRequestAction> actions) {
        ItemStackRequest request;
        request.mRequestId = id;
        request.mActions.assign(actions);
        ItemStackRequestPacket packet;
        packet.mRequests.push_back(std::move(request));
        connection.send(packet);
        connection.flush();
    }

    void response(BedrockConnection &connection, int id, bool success) {
        until(connection, [&](Packet &packet) {
            auto *response = dynamic_cast<ItemStackResponsePacket *>(&packet);
            if (response == nullptr)
                return false;
            for (const auto &entry : response->mEntries) {
                if (entry.mRequestId != id)
                    continue;
                if ((entry.mResult == 0) != success)
                    throw std::runtime_error("unexpected inventory result " + std::to_string(entry.mResult)
                                             + " for request " + std::to_string(id));
                return true;
            }
            return false;
        }, "inventory response timeout");
    }

    void shoot(BedrockConnection &connection) {
        InventoryTransactionPacket action;
        action.mTransactionType = InventoryTransactionType::ItemUse;
        action.mActionType = 1;
        connection.send(action);
        tick(connection, 600ms);
        action.mTransactionType = InventoryTransactionType::ItemRelease;
        action.mActionType = 0;
        connection.send(action);
        tick(connection, 250ms);
    }

    struct InteractionTargets {
        Vector3i block;
        Vector3i anchor;
        Vector3i crop;
        uint64_t actor = 0;
        Vector3f player;
    };

    void exerciseInteraction(BedrockConnection &connection, const InteractionTargets &targets) {
        InventoryTransactionPacket useAnchor;
        useAnchor.mTransactionType = InventoryTransactionType::ItemUse;
        useAnchor.mActionType = 0;
        useAnchor.mHotbarSlot = 0;
        useAnchor.mBlockFace = 1;
        useAnchor.mBlockPosition = targets.anchor;
        useAnchor.mClickPosition = Vector3f(0.5f, 1.0f, 0.5f);
        connection.send(useAnchor);

        InventoryTransactionPacket fertilize = useAnchor;
        fertilize.mBlockPosition = targets.crop;
        fertilize.mHotbarSlot = 6;
        connection.send(fertilize);
        until(connection, [](Packet &packet) {
            const auto *text = dynamic_cast<TextPacket *>(&packet);
            return text != nullptr && text->mMessage == "sensitive-fixture-growth-cancelled";
        }, "the first bone meal action did not expose a cancellable grow event");
        fertilize.mClickPosition.x = 0.6f;
        connection.send(fertilize);

        BlockPickRequestPacket block;
        block.mBlockPosition = targets.block;
        block.mAddUserData = false;
        block.mHotbarSlot = 7;
        for (int attempt = 0; attempt < 3; ++attempt)
            connection.send(block);

        ActorPickRequestPacket actor;
        actor.mRuntimeActorId = targets.actor;
        actor.mHotbarSlot = 8;
        actor.mWithData = false;
        connection.send(actor);

        TextPacket verify;
        verify.mType = TextPacket::Type::Chat;
        verify.mSourceName = "EventFixture";
        verify.mMessage = "sensitive-fixture-interaction-verify";
        connection.send(verify);
        until(connection, [](Packet &packet) {
            const auto *text = dynamic_cast<TextPacket *>(&packet);
            return text != nullptr && text->mMessage == "sensitive-fixture-interaction-ok";
        }, "the server did not confirm interaction and outbound packet changes");
    }

    void exercise(BedrockConnection &connection) {
        constexpr auto inventory = ContainerSlotType::Inventory;
        constexpr auto crafting = ContainerSlotType::CraftingInput;
        constexpr auto created = ContainerSlotType::CreatedOutput;
        constexpr auto input = ContainerSlotType::EnchantingInput;
        constexpr auto material = ContainerSlotType::EnchantingMaterial;
        constexpr auto place = ItemStackRequestActionType::Place;
        constexpr auto consume = ItemStackRequestActionType::Consume;
        constexpr auto take = ItemStackRequestActionType::Take;

        int oakPlanks = 0;
        for (const auto &entry : connection.getItemRegistry()->mEntries) {
            if (entry.mIdentifier == "minecraft:oak_planks") {
                oakPlanks = entry.mRuntimeId;
                break;
            }
        }
        expect(oakPlanks != 0, "oak planks missing from the server's item registry");
        int oakRecipe = 0;
        Vector3i table;
        InteractionTargets interaction;
        bool tableReady = false;
        bool interactionReady = false;
        until(connection, [&](Packet &packet) {
            if (const auto *text = dynamic_cast<TextPacket *>(&packet)) {
                const std::string prefix = "sensitive-fixture-table ";
                if (text->mMessage.starts_with(prefix)) {
                    std::istringstream coordinates(text->mMessage.substr(prefix.size()));
                    tableReady = bool(coordinates >> table.x >> table.y >> table.z);
                }
                const std::string interactionPrefix = "sensitive-fixture-interaction ";
                if (text->mMessage.starts_with(interactionPrefix)) {
                    std::istringstream values(text->mMessage.substr(interactionPrefix.size()));
                    interactionReady = bool(values >> interaction.block.x >> interaction.block.y >> interaction.block.z
                        >> interaction.anchor.x >> interaction.anchor.y >> interaction.anchor.z
                        >> interaction.crop.x >> interaction.crop.y >> interaction.crop.z >> interaction.actor
                        >> interaction.player.x >> interaction.player.y >> interaction.player.z);
                }
            }
            if (const auto *recipes = dynamic_cast<CraftingDataPacket *>(&packet)) {
                for (const auto &candidate : recipes->mShapedRecipes) {
                    if (candidate.mInputs.size() != 1 || candidate.mInputs[0].mItemId != "minecraft:oak_log"
                        || candidate.mOutputs.size() != 1 || candidate.mOutputs[0].mCount != 4)
                        continue;
                    if (candidate.mOutputs[0].mRuntimeId == oakPlanks) {
                        oakRecipe = candidate.mRecipeNetId;
                        break;
                    }
                }
            }
            return tableReady && interactionReady && oakRecipe != 0;
        }, "fixture table, interactions or recipe missing");

        exerciseInteraction(connection, interaction);

        shoot(connection);
        shoot(connection);
        shoot(connection);
        until(connection, [](Packet &packet) {
            const auto *actor = dynamic_cast<const AddActorPacket *>(&packet);
            return actor != nullptr && actor->mIdentifier == "minecraft:snowball";
        }, "replacement snowball did not reach the client");
        shoot(connection);

        TextPacket verifyProjectiles;
        verifyProjectiles.mType = TextPacket::Type::Chat;
        verifyProjectiles.mSourceName = "EventFixture";
        verifyProjectiles.mMessage = "sensitive-fixture-projectiles-verify";
        connection.send(verifyProjectiles);
        until(connection, [](Packet &packet) {
            const auto *actor = dynamic_cast<const AddActorPacket *>(&packet);
            expect(actor == nullptr || actor->mIdentifier != "minecraft:snowball", "cancelled snowball reached the client");
            const auto *text = dynamic_cast<const TextPacket *>(&packet);
            return text != nullptr && text->mMessage == "sensitive-fixture-projectiles-ok";
        }, "projectile outcomes not confirmed");

        send(connection, -1, {action(place, 1, slot(inventory, 2), slot(crafting, 28))});
        response(connection, -1, false);
        send(connection, -3, {action(place, 1, slot(inventory, 2), slot(crafting, 28))});
        response(connection, -3, true);
        for (const int id : {-5, -7}) {
            send(connection, id, {recipe(oakRecipe), action(consume, 1, slot(crafting, 28)),
                                  action(take, 4, slot(created, 50), slot(inventory, 5))});
            response(connection, id, id == -7);
        }

        InventoryTransactionPacket open;
        open.mTransactionType = InventoryTransactionType::ItemUse;
        open.mActionType = 0;
        open.mHotbarSlot = 8;
        open.mBlockFace = 1;
        open.mBlockPosition = table;
        open.mClickPosition = Vector3f(0.5f, 1.0f, 0.5f);
        connection.send(open);
        int8_t enchantWindow = 0;
        until(connection, [&](Packet &packet) {
            if (const auto *window = dynamic_cast<ContainerOpenPacket *>(&packet);
                    window != nullptr && window->mType == ContainerType::Enchantment) {
                enchantWindow = window->mWindowId;
                return true;
            }
            return false;
        }, "enchanting table did not open");

        send(connection, -9, {action(place, 3, slot(inventory, 4), slot(material, 0))});
        response(connection, -9, true);
        send(connection, -11, {action(place, 1, slot(inventory, 3), slot(input, 0))});
        int enchantRecipe = 0;
        bool inputPlaced = false;
        until(connection, [&](Packet &packet) {
            if (const auto *options = dynamic_cast<PlayerEnchantOptionsPacket *>(&packet); options && !options->mOptions.empty()) {
                expect(options->mOptions.size() == 1, "plugin enchant option count was ignored");
                const auto &option = options->mOptions[0];
                expect(option.mEnchantName == "Fixture Sharpness" && option.mCost == 4, "plugin enchant option was ignored");
                enchantRecipe = option.mEnchantNetId;
            }
            if (const auto *response = dynamic_cast<ItemStackResponsePacket *>(&packet)) {
                for (const auto &entry : response->mEntries) {
                    if (entry.mRequestId == -11) {
                        expect(entry.mResult == 0, "book could not be placed in the enchanting table");
                        inputPlaced = true;
                    }
                }
            }
            return enchantRecipe > 0 && inputPlaced;
        }, "modified enchant option was not delivered");
        for (const int id : {-13, -15}) {
            send(connection, id, {recipe(enchantRecipe), action(consume, 1, slot(input, 0)),
                                  action(consume, 1, slot(material, 0)), action(take, 1, slot(created, 50), slot(inventory, 6))});
            response(connection, id, id == -15);
        }

        send(connection, -17, {action(take, 2, slot(material, 0), slot(inventory, 4))});
        response(connection, -17, true);
        ContainerClosePacket close;
        close.mWindowId = enchantWindow;
        close.mType = ContainerType::Enchantment;
        connection.send(close);

        // The TNT has an 80-tick fuse.
        tick(connection, 4500ms);
        PlayerAuthInputPacket swing;
        swing.mPosition = interaction.player;
        swing.mPosition.y += 1.62f;
        swing.mInputData.push_back((int32_t) PlayerAuthInputData::MissedSwing);
        swing.mTick = 1;
        connection.send(swing);
        until(connection, [](Packet &packet) {
            const auto *text = dynamic_cast<TextPacket *>(&packet);
            return text != nullptr && text->mMessage == "sensitive-fixture-miss";
        }, "queued missed-swing input did not reach the listener");

        TextPacket verify;
        verify.mType = TextPacket::Type::Chat;
        verify.mSourceName = "EventFixture";
        verify.mMessage = "sensitive-fixture-verify";
        connection.send(verify);
        until(connection, [](Packet &packet) {
            const auto *text = dynamic_cast<TextPacket *>(&packet);
            return text != nullptr && text->mMessage == "sensitive-fixture-ok";
        }, "the server did not confirm the fixture outcomes");
    }
}

int main(int argc, char **argv) {
    if (argc != 2) {
        std::cerr << "Usage: event-client <server port>\n";
        return 2;
    }
    try {
        ClientConnectionSettings settings;
        settings.mHost = "127.0.0.1";
        settings.mPort = static_cast<unsigned short>(std::stoi(argv[1]));
        settings.mProtocolVersion = 2225;
        settings.mGameVersion = "1.26.60";
        settings.mTransportLayer = TransportLayer::NetherNet;
        settings.mNetherNet.mSignalingType = NetherNetSignalingType::Http;
        settings.mIdentity.mDisplayName = "EventFixture";
        settings.mChunkRadius = 5;
        settings.mTimeoutMs = 15000;
        auto result = ClientNetworkSystem::dial(settings);
        if (result.mConnection == nullptr)
            throw std::runtime_error(result.mError);
        exercise(*result.mConnection);
        result.mConnection->disconnect("Fixture complete");
        result.mConnection->flush();
        return 0;
    } catch (const std::exception &error) {
        std::cerr << "Sensitive event client failed: " << error.what() << '\n';
        return 1;
    }
}
