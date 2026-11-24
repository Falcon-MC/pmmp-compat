#include <falcon/falcon_api.h>
#include <stdlib.h>
#include <string.h>

static const FalconServerApi *api;

static const char *native_reply(const char *request, void *context) {
    (void) context;
    return strcmp(request, "from-php") == 0 ? "from-native" : "";
}

static void start(void *context) {
    FalconPlugin *plugin = context;
    int found = 0;
    const char *reply = api->callService("roadmap.php", "from-native", &found);
    if (found && reply != NULL && strcmp(reply, "from-php") == 0)
        api->log(plugin, FALCON_LOG_INFO, "Native-to-PHP service passed");
    FalconLevel *level = api->serverLevel(FALCON_DIMENSION_OVERWORLD);
    if (level != NULL) {
        const FalconVec3 spawn = api->levelSpawnPosition(level);
        api->serverSpawnSimulatedPlayer("RoadViewer", level, spawn, 0, FALCON_GAME_MODE_SURVIVAL);
        if (getenv("ROADMAP_REAL_CLIENT") == NULL)
            api->serverSpawnSimulatedPlayer("RoadRunner", level, spawn, 0, FALCON_GAME_MODE_SURVIVAL);
    }
}

static void check_removed(void *context) {
    FalconPlugin *plugin = context;
    if (!api->hasService("roadmap.php"))
        api->log(plugin, FALCON_LOG_INFO, "Disabled plugin's service was removed");
}

static int enable(void *context) {
    FalconPlugin *plugin = context;
    return api->registerService(plugin, "roadmap.native", native_reply, NULL) != 0
        && api->scheduleTask(plugin, start, plugin, 30, 0) != 0
        && api->scheduleTask(plugin, check_removed, plugin, 145, 0) != 0;
}

FALCON_EXPORT int falcon_plugin_entry(const FalconServerApi *server, FalconPlugin *plugin,
                                      FalconPluginCallbacks *callbacks) {
    api = server;
    callbacks->onEnable = enable;
    callbacks->userData = plugin;
    return 1;
}
