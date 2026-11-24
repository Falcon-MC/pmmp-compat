#include "Network/Client/ClientNetworkSystem.h"
#include "Protocol/Packets/InventoryTransactionPacket.h"
#include "Protocol/Packets/ItemRegistryPacket.h"
#include "Protocol/Packets/TextPacket.h"

#include <algorithm>
#include <chrono>
#include <iostream>
#include <stdexcept>
#include <string>
#include <string_view>
#include <thread>

using namespace std::chrono_literals;

namespace {
    template<typename Test>
    void until(BedrockConnection &connection, Test test) {
        const auto deadline = std::chrono::steady_clock::now() + 8s;
        while (std::chrono::steady_clock::now() < deadline) {
            connection.update();
            connection.flush();
            while (auto packet = connection.receive()) {
                if (test(*packet))
                    return;
            }
            if (connection.isClosed())
                break;
            std::this_thread::sleep_for(10ms);
        }
        throw std::runtime_error("Custom item fixture did not respond: " + connection.getLastDecodeError());
    }

    void requireComponent(const ItemRegistryPacket &registry, const char *identifier, const char *component,
                          const char *field, int expected) {
        const auto entry = std::find_if(registry.mEntries.begin(), registry.mEntries.end(), [&](const auto &item) {
            return item.mIdentifier == identifier;
        });
        if (entry == registry.mEntries.end() || !entry->mComponentBased || entry->mRuntimeId == 0)
            throw std::runtime_error(std::string(identifier) + " missing from the client item registry");
        const Tag *components = entry->mComponentData.get("components");
        const Tag *definition = components == nullptr ? nullptr : components->get(component);
        const Tag *value = definition == nullptr ? nullptr : definition->get(field);
        if (value == nullptr || value->asInt() != expected)
            throw std::runtime_error(std::string(identifier) + " has the wrong component data for " + field);
    }

    void waitForText(BedrockConnection &connection, const char *message) {
        until(connection, [&](Packet &packet) {
            const auto *text = dynamic_cast<TextPacket *>(&packet);
            return text != nullptr && text->mMessage == message;
        });
    }
}

int main(int argc, char **argv) {
    if (argc != 3)
        return 2;
    try {
        ClientConnectionSettings settings;
        settings.mHost = "127.0.0.1";
        settings.mPort = static_cast<unsigned short>(std::stoi(argv[1]));
        settings.mProtocolVersion = 2225;
        settings.mGameVersion = "1.26.60";
        settings.mTransportLayer = TransportLayer::NetherNet;
        settings.mNetherNet.mSignalingType = NetherNetSignalingType::Http;
        settings.mIdentity.mDisplayName = "ItemFixture";
        settings.mChunkRadius = 5;
        settings.mTimeoutMs = 15000;
        auto result = ClientNetworkSystem::dial(settings);
        if (result.mConnection == nullptr)
            throw std::runtime_error(result.mError);

        auto &connection = *result.mConnection;
        const bool restart = std::string_view(argv[2]) == "restart";
        waitForText(connection, restart ? "custom-item-persisted" : "custom-item-ready");
        const auto &registry = connection.getItemRegistry();
        if (registry == nullptr)
            throw std::runtime_error("Missing item registry packet");
        requireComponent(*registry, "fixture:pulse_fruit", "minecraft:food", "nutrition", 4);
        requireComponent(*registry, "fixture:practice_pickaxe", "minecraft:durability", "max_durability", 123);
        requireComponent(*registry, "fixture:practice_helmet", "minecraft:wearable", "protection", 3);
        if (!restart) {
            InventoryTransactionPacket use;
            use.mTransactionType = InventoryTransactionType::ItemUse;
            use.mActionType = 1;
            connection.send(use);
            waitForText(connection, "custom-item-consumed");
        }
        connection.disconnect("Custom item fixture complete");
        connection.flush();
        return 0;
    } catch (const std::exception &error) {
        std::cerr << error.what() << '\n';
        return 1;
    }
}
