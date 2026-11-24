#include "Network/Client/ClientNetworkSystem.h"
#include "Protocol/Packets/BlockActorDataPacket.h"
#include "Protocol/Packets/ContainerClosePacket.h"
#include "Protocol/Packets/ContainerOpenPacket.h"
#include "Protocol/Packets/NetworkStackLatencyPacket.h"
#include "Protocol/Packets/TextPacket.h"

#include <chrono>
#include <iostream>
#include <stdexcept>
#include <thread>

using namespace std::chrono_literals;

int main(int argc, char **argv) {
    if (argc != 2) {
        std::cerr << "Usage: chest-client <server port>\n";
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
        settings.mIdentity.mDisplayName = "ChestFixture";
        settings.mChunkRadius = 5;
        settings.mTimeoutMs = 15000;
        auto result = ClientNetworkSystem::dial(settings);
        if (!result.mConnection)
            throw std::runtime_error(result.mError);
        auto &client = *result.mConnection;

        int opened = 0;
        bool chestTile = false;
        const auto deadline = std::chrono::steady_clock::now() + 12s;
        while (std::chrono::steady_clock::now() < deadline && !client.isClosed()) {
            client.update();
            client.flush();
            while (const auto packet = client.receive()) {
                if (const auto *latency = dynamic_cast<const NetworkStackLatencyPacket *>(packet.get())) {
                    if (latency->mFromServer) {
                        NetworkStackLatencyPacket reply;
                        reply.mTimestamp = latency->mTimestamp * 1000000;
                        reply.mFromServer = false;
                        client.send(reply);
                    }
                } else if (const auto *close = dynamic_cast<const ContainerClosePacket *>(packet.get())) {
                    ContainerClosePacket reply(*close);
                    reply.mServerInitiated = false;
                    client.send(reply);
                } else if (const auto *open = dynamic_cast<const ContainerOpenPacket *>(packet.get())) {
                    if (open->mWindowId != 0)
                        ++opened;
                } else if (dynamic_cast<const BlockActorDataPacket *>(packet.get()) != nullptr) {
                    chestTile = true;
                } else if (const auto *message = dynamic_cast<const TextPacket *>(packet.get())) {
                    if (message->mMessage == "chestshop-fixture-purchase-passed") {
                        if (opened < 2 || !chestTile)
                            throw std::runtime_error("missing category or purchase menu packets");
                        client.disconnect("Fixture complete");
                        client.flush();
                        std::cout << "ChestShop category and purchase windows, close acknowledgement and purchase passed\n";
                        return 0;
                    }
                }
            }
            std::this_thread::sleep_for(10ms);
        }
        throw std::runtime_error("the server did not confirm purchase; opened " + std::to_string(opened) + " windows");
    } catch (const std::exception &error) {
        std::cerr << "ChestShop client failed: " << error.what() << '\n';
        return 1;
    }
}
