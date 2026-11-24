#include "Network/Client/ClientNetworkSystem.h"
#include "Protocol/Packets/AddPlayerPacket.h"
#include "Protocol/Packets/CommandRequestPacket.h"
#include "Protocol/Packets/EmotePacket.h"
#include "Protocol/Packets/InventoryTransactionPacket.h"
#include "Protocol/Packets/MovePlayerPacket.h"
#include "Protocol/Packets/PlayerAuthInputPacket.h"
#include "Protocol/Packets/RequestAbilityPacket.h"
#include "Protocol/Packets/SetActorDataPacket.h"
#include "Protocol/Packets/StartGamePacket.h"
#include "Protocol/Packets/TextPacket.h"
#include "Protocol/Packets/UpdateAbilitiesPacket.h"

#include <chrono>
#include <cmath>
#include <iostream>
#include <sstream>
#include <stdexcept>
#include <thread>

using namespace std::chrono_literals;

namespace {
    auto connect(int port, const std::string &name) {
        ClientConnectionSettings settings;
        settings.mHost = "127.0.0.1";
        settings.mPort = static_cast<unsigned short>(port);
        settings.mProtocolVersion = 2225;
        settings.mGameVersion = "1.26.60";
        settings.mTransportLayer = TransportLayer::NetherNet;
        settings.mNetherNet.mSignalingType = NetherNetSignalingType::Http;
        settings.mIdentity.mDisplayName = name;
        settings.mChunkRadius = 5;
        settings.mTimeoutMs = 15000;
        auto result = ClientNetworkSystem::dial(settings);
        if (!result.mConnection)
            throw std::runtime_error(result.mError);
        return std::move(result.mConnection);
    }

    template<typename Predicate>
    void until(BedrockConnection &client, Predicate ready, const char *description) {
        const auto deadline = std::chrono::steady_clock::now() + 6s;
        while (std::chrono::steady_clock::now() < deadline) {
            client.update();
            client.flush();
            while (const auto packet = client.receive()) {
                if (ready(*packet))
                    return;
            }
            if (client.isClosed())
                throw std::runtime_error(client.getDisconnectReason());
            std::this_thread::sleep_for(10ms);
        }
        throw std::runtime_error(description);
    }

    bool message(const Packet &packet, const std::string &text) {
        const auto *value = dynamic_cast<const TextPacket *>(&packet);
        return value != nullptr && value->mMessage == text;
    }

    bool flight(const Packet &packet, bool allowed, bool flying) {
        const auto *value = dynamic_cast<const UpdateAbilitiesPacket *>(&packet);
        if (value == nullptr || value->mAbilities.mAbilityLayers.empty())
            return false;
        const uint32_t mask = value->mAbilities.mAbilityLayers.front().mAbilityValues;
        const bool receivedAllow = (mask & (1u << static_cast<unsigned int>(Ability::MayFly))) != 0;
        const bool receivedFlying = (mask & (1u << static_cast<unsigned int>(Ability::Flying))) != 0;
        return allowed == receivedAllow && flying == receivedFlying;
    }

    bool scaledDimensions(const EntityDataMap &metadata, float factor) {
        bool scale = false;
        bool width = false;
        bool height = false;
        for (const auto &entry: metadata.mEntries) {
            if (entry.mFormat != EntityDataFormat::Float)
                continue;
            if (entry.mId == 38)
                scale = std::abs(entry.mFloatValue - factor) < 0.001;
            if (entry.mId == 53)
                width = std::abs(entry.mFloatValue - 0.6f * factor) < 0.001;
            if (entry.mId == 54)
                height = std::abs(entry.mFloatValue - 1.8f * factor) < 0.001;
        }
        return scale && width && height;
    }

    void send(BedrockConnection &client, const Packet &packet) {
        client.send(packet);
        client.flush();
    }

    void test(int port) {
        auto attacker = connect(port, "GameplayAttacker");
        auto target = connect(port, "GameplayTarget");
        const auto attackerId = (int64_t) attacker->getStartGame()->mRuntimeActorId;
        until(*attacker, [&](const Packet &packet) {
            const auto *metadata = dynamic_cast<const SetActorDataPacket *>(&packet);
            return metadata != nullptr && metadata->mRuntimeActorId == attackerId && scaledDimensions(metadata->mMetadata, 1.5f);
        }, "scaled player did not receive its new dimensions");
        until(*target, [&](const Packet &packet) {
            const auto *spawn = dynamic_cast<const AddPlayerPacket *>(&packet);
            return spawn != nullptr && spawn->mRuntimeActorId == attackerId && scaledDimensions(spawn->mMetadata, 1.5f);
        }, "late-joining player did not receive the scaled spawn dimensions");

        Vector3i bed;
        bool bedReady = false;
        bool normalScale = false;
        bool pvpReady = false;
        until(*attacker, [&](const Packet &packet) {
            if (const auto *metadata = dynamic_cast<const SetActorDataPacket *>(&packet)) {
                normalScale |= metadata->mRuntimeActorId == attackerId && scaledDimensions(metadata->mMetadata, 1.0f);
            }
            if (const auto *text = dynamic_cast<const TextPacket *>(&packet)) {
                const std::string prefix = "gameplay-fixture-bed ";
                if (text->mMessage.starts_with(prefix)) {
                    std::istringstream coordinates(text->mMessage.substr(prefix.size()));
                    bedReady = bool(coordinates >> bed.x >> bed.y >> bed.z);
                }
            }
            pvpReady |= message(packet, "gameplay-fixture-pvp-ready");
            return bedReady && normalScale && pvpReady;
        }, "position, motion, bed, speed or teleport setup failed");

        CommandRequestPacket command;
        command.mCommand = "/fly";
        send(*attacker, command);
        until(*attacker, [](const Packet &packet) { return flight(packet, true, false); }, "BlazinFly did not grant native flight permission");

        RequestAbilityPacket toggle;
        toggle.mAbility = Ability::Flying;
        toggle.mType = AbilityValueType::Boolean;
        toggle.mBoolValue = true;
        send(*attacker, toggle);
        until(*attacker, [](const Packet &packet) { return message(packet, "gameplay-fixture-flight-cancelled"); }, "flight transition cancellation missing");
        until(*attacker, [](const Packet &packet) { return flight(packet, true, false); }, "cancelled flight changed native state");
        send(*attacker, toggle);
        until(*attacker, [](const Packet &packet) { return flight(packet, true, true); }, "survival flight rejected by Falcon");

        EmotePacket emote;
        emote.mRuntimeActorId = attacker->getStartGame()->mRuntimeActorId;
        emote.mEmoteId = "fixture-replace";
        send(*attacker, emote);
        until(*target, [](const Packet &packet) {
            const auto *received = dynamic_cast<const EmotePacket *>(&packet);
            return received != nullptr && received->mEmoteId == "fixture-replaced";
        }, "emote replacement did not reach a second client");

        InventoryTransactionPacket attack;
        attack.mTransactionType = InventoryTransactionType::ItemUseOnEntity;
        attack.mActionType = 1;
        attack.mRuntimeActorId = target->getStartGame()->mRuntimeActorId;
        send(*attacker, attack);
        bool nativeFlightRevoked = false;
        bool popupReceived = false;
        bool damageVerified = false;
        until(*attacker, [&](const Packet &packet) {
            nativeFlightRevoked |= flight(packet, false, false);
            const auto *text = dynamic_cast<const TextPacket *>(&packet);
            popupReceived |= text != nullptr && text->mType == TextPacket::Type::Popup
                             && text->mMessage.find("GameplayTarget") != std::string::npos
                             && text->mMessage.find("❤") != std::string::npos;
            damageVerified |= message(packet, "gameplay-fixture-combat-passed");
            return nativeFlightRevoked && popupReceived && damageVerified;
        }, "combat did not revoke flight, deliver the XGT popup or reduce target HP");

        InventoryTransactionPacket useBed;
        useBed.mTransactionType = InventoryTransactionType::ItemUse;
        useBed.mActionType = 0;
        useBed.mBlockPosition = bed;
        useBed.mBlockFace = 1;
        useBed.mClickPosition = Vector3f(0.5f, 0.5f, 0.5f);
        send(*attacker, useBed);
        until(*attacker, [](const Packet &packet) { return message(packet, "gameplay-fixture-bed-cancelled"); },
              "bed entry event not delivered");

        PlayerAuthInputPacket movement;
        movement.mPosition = attacker->getStartGame()->mPlayerPosition;
        movement.mTick = 1;
        movement.mInputData = {
            (int32_t) PlayerAuthInputData::StartJumping,
            (int32_t) PlayerAuthInputData::StartSneaking,
            (int32_t) PlayerAuthInputData::StartGliding,
            (int32_t) PlayerAuthInputData::StartSwimming,
        };
        send(*attacker, movement);
        bool jumped = false;
        bool sneakCancelled = false;
        bool glideCancelled = false;
        bool swimCancelled = false;
        until(*attacker, [&](const Packet &packet) {
            jumped |= message(packet, "gameplay-fixture-jump");
            sneakCancelled |= message(packet, "gameplay-fixture-sneak-cancelled");
            glideCancelled |= message(packet, "gameplay-fixture-glide-cancelled");
            swimCancelled |= message(packet, "gameplay-fixture-swim-cancelled");
            return jumped && sneakCancelled && glideCancelled && swimCancelled;
        }, "jump, sneak, glide or swim movement callbacks missing");

        for (int64_t tick = 2; tick <= 6; ++tick) {
            movement.mTick = tick;
            movement.mInputData = {(int32_t) PlayerAuthInputData::Up};
            movement.mMotionY = 1.0f;
            movement.mRawMoveVectorY = 1.0f;
            movement.mPosition.z += 0.2f;
            send(*attacker, movement);
        }
        until(*attacker, [](const Packet &packet) { return message(packet, "gameplay-fixture-move-cancelled"); },
              "player move cancellation missing");

        TextPacket verifyTeleport;
        verifyTeleport.mType = TextPacket::Type::Chat;
        verifyTeleport.mSourceName = "GameplayAttacker";
        verifyTeleport.mMessage = "gameplay-fixture-teleport";
        send(*attacker, verifyTeleport);
        bool facingApplied = false;
        bool teleportVerified = false;
        until(*attacker, [&](const Packet &packet) {
            if (const auto *position = dynamic_cast<const MovePlayerPacket *>(&packet)) {
                facingApplied |= position->mMode == MovePlayerMode::Teleport && position->mRotation.x == -18
                                 && position->mRotation.y == 63;
            }
            teleportVerified |= message(packet, "gameplay-fixture-teleport-passed");
            return teleportVerified && facingApplied;
        }, "teleport position, facing or world redirect did not apply");

        target->disconnect("Fixture complete");
        target->flush();
        attacker->disconnect("Fixture complete");
        attacker->flush();
        std::cout << "BlazinFly flight grant, toggle cancellation, survival flight and combat reset passed\n"
                  << "XGTShowPlayerHP hearts reached the attacking client after real PvP\n"
                  << "PlayerEmoteEvent replacement reached the other client, and bed entry was cancelled\n"
                  << "Move, jump, sneak, glide and swim callbacks passed\n"
                  << "Teleport cancellation result, facing and world redirect passed\n"
                  << "Player scale, body dimensions and reset reached current and later viewers\n";
    }
}

int main(int argc, char **argv) {
    if (argc != 2) {
        std::cerr << "Usage: gameplay-client <server port>\n";
        return 2;
    }
    try {
        test(std::stoi(argv[1]));
        return 0;
    } catch (const std::exception &error) {
        std::cerr << "Gameplay client failed: " << error.what() << '\n';
        return 1;
    }
}
