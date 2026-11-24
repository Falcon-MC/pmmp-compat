#include "Network/Client/ClientNetworkSystem.h"
#include "Protocol/Packets/AddPlayerPacket.h"
#include "Protocol/Packets/ContainerClosePacket.h"
#include "Protocol/Packets/ContainerOpenPacket.h"
#include "Protocol/Packets/InventoryTransactionPacket.h"
#include "Protocol/Packets/ModalFormRequestPacket.h"
#include "Protocol/Packets/ModalFormResponsePacket.h"
#include "Protocol/Packets/NetworkStackLatencyPacket.h"
#include "Protocol/Packets/TextPacket.h"

#include <chrono>
#include <iostream>
#include <stdexcept>
#include <string>
#include <thread>

using namespace std::chrono_literals;

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
        settings.mIdentity.mDisplayName = "RoadRunner";
        settings.mChunkRadius = 5;
        settings.mTimeoutMs = 15000;
        auto result = ClientNetworkSystem::dial(settings);
        if (result.mConnection == nullptr)
            throw std::runtime_error(result.mError);

        auto &connection = *result.mConnection;
        const std::string scenario = argv[2];
        const bool inventory = scenario == "inventory";
        const bool shop = scenario == "shop";
        int opened = 0;
        size_t forms = 0;
        bool spawnedNpc = false;
        bool done = false;
        const std::string marker = shop ? "roadmap-shop-purchased"
                                   : inventory ? "roadmap-inventory-ready" : "roadmap-npc-hit";
        const auto deadline = std::chrono::steady_clock::now() + 12s;
        while (!done && std::chrono::steady_clock::now() < deadline && !connection.isClosed()) {
            connection.update();
            connection.flush();
            while (auto packet = connection.receive()) {
                if (auto *latency = dynamic_cast<NetworkStackLatencyPacket *>(packet.get())) {
                    if (latency->mFromServer) {
                        latency->mTimestamp *= 1000000;
                        latency->mFromServer = false;
                        connection.send(*latency);
                    }
                } else if (auto *close = dynamic_cast<ContainerClosePacket *>(packet.get())) {
                    if (close->mServerInitiated) {
                        close->mServerInitiated = false;
                        connection.send(*close);
                    }
                } else if (dynamic_cast<ContainerOpenPacket *>(packet.get()) != nullptr) {
                    ++opened;
                } else if (auto *form = dynamic_cast<ModalFormRequestPacket *>(packet.get())) {
                    if (shop) {
                        constexpr const char *expect[] = {"Produce", "Apple", "Amount"};
                        constexpr const char *choices[] = {"0", "0", "[null,false,\"2\"]"};
                        if (forms >= 3 || form->mFormData.find(expect[forms]) == std::string::npos)
                            throw std::runtime_error("Unexpected shop form: " + form->mFormData);
                        ModalFormResponsePacket answer;
                        answer.mFormId = form->mFormId;
                        answer.mHasFormData = true;
                        answer.mFormData = choices[forms++];
                        answer.mHasCancelReason = false;
                        connection.send(answer);
                    }
                } else if (auto *actor = dynamic_cast<AddPlayerPacket *>(packet.get())) {
                    if (!inventory && !shop && actor->mUsername == "RoadNPC" && !spawnedNpc) {
                        spawnedNpc = true;
                        InventoryTransactionPacket hit;
                        hit.mTransactionType = InventoryTransactionType::ItemUseOnEntity;
                        hit.mRuntimeActorId = actor->mRuntimeActorId;
                        hit.mActionType = 1;
                        connection.send(hit);
                    }
                } else if (auto *text = dynamic_cast<TextPacket *>(packet.get())) {
                    if (text->mMessage == marker)
                        done = true;
                }
            }
            std::this_thread::sleep_for(10ms);
        }
        if (!done || (shop ? forms != 3 : inventory ? opened < 2 : !spawnedNpc))
            throw std::runtime_error("Expected menu or actor packets were not received: " + connection.getLastDecodeError());
        connection.disconnect("Roadmap fixture complete");
        connection.flush();
        return 0;
    } catch (const std::exception &error) {
        std::cerr << error.what() << '\n';
        return 1;
    }
}
