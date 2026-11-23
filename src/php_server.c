// bans, the allow list, the MOTD, slots, ticks and difficulty for the PHP side
#include "php_bridge_internal.h"

#include <zend_API.h>
#include <string.h>

#define NAME_ARGUMENT(name) \
    zend_string *name; \
    ZEND_PARSE_PARAMETERS_START(1, 1) \
        Z_PARAM_STR(name) \
    ZEND_PARSE_PARAMETERS_END()

static void return_names(zval *return_value, uint32_t (*count)(void), const char *(*at)(uint32_t)) {
    const uint32_t total = count();
    array_init_size(return_value, total);
    for (uint32_t i = 0; i < total; ++i) {
        const char *name = at(i);
        if (name != NULL)
            add_next_index_string(return_value, name);
    }
}

// --- name and address bans: Falcon's own lists, kicking whoever is banned ---

PHP_FUNCTION(falcon_server_ban) {
    zend_string *name, *reason, *source;
    bool ip = false;
    ZEND_PARSE_PARAMETERS_START(3, 4)
        Z_PARAM_STR(name)
        Z_PARAM_STR(reason)
        Z_PARAM_STR(source)
        Z_PARAM_OPTIONAL
        Z_PARAM_BOOL(ip)
    ZEND_PARSE_PARAMETERS_END();
    RETURN_BOOL((ip ? falcon->serverBanIp : falcon->serverBan)(ZSTR_VAL(name), ZSTR_VAL(reason), ZSTR_VAL(source)) != 0);
}

PHP_FUNCTION(falcon_server_pardon) {
    zend_string *name;
    bool ip = false;
    ZEND_PARSE_PARAMETERS_START(1, 2)
        Z_PARAM_STR(name)
        Z_PARAM_OPTIONAL
        Z_PARAM_BOOL(ip)
    ZEND_PARSE_PARAMETERS_END();
    RETURN_BOOL((ip ? falcon->serverPardonIp : falcon->serverPardon)(ZSTR_VAL(name)) != 0);
}

PHP_FUNCTION(falcon_server_is_banned) {
    zend_string *name;
    bool ip = false;
    ZEND_PARSE_PARAMETERS_START(1, 2)
        Z_PARAM_STR(name)
        Z_PARAM_OPTIONAL
        Z_PARAM_BOOL(ip)
    ZEND_PARSE_PARAMETERS_END();
    RETURN_BOOL((ip ? falcon->serverIsIpBanned : falcon->serverIsBanned)(ZSTR_VAL(name)) != 0);
}

PHP_FUNCTION(falcon_server_bans) {
    bool ip = false;
    ZEND_PARSE_PARAMETERS_START(0, 1)
        Z_PARAM_OPTIONAL
        Z_PARAM_BOOL(ip)
    ZEND_PARSE_PARAMETERS_END();
    if (ip)
        return_names(return_value, falcon->serverIpBanCount, falcon->serverBannedIp);
    else
        return_names(return_value, falcon->serverBanCount, falcon->serverBannedName);
}

// --- the allow list ---

PHP_FUNCTION(falcon_server_allow) {
    zend_string *name;
    bool allowed;
    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_STR(name)
        Z_PARAM_BOOL(allowed)
    ZEND_PARSE_PARAMETERS_END();
    RETURN_BOOL((allowed ? falcon->serverAllowListAdd : falcon->serverAllowListRemove)(ZSTR_VAL(name)) != 0);
}

PHP_FUNCTION(falcon_server_allow_list) {
    ZEND_PARSE_PARAMETERS_NONE();
    return_names(return_value, falcon->serverAllowListCount, falcon->serverAllowListName);
}

PHP_FUNCTION(falcon_server_allow_list_enabled) {
    ZEND_PARSE_PARAMETERS_NONE();
    RETURN_BOOL(falcon->serverAllowListEnabled() != 0);
}

PHP_FUNCTION(falcon_server_set_allow_list_enabled) {
    bool enabled;
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_BOOL(enabled)
    ZEND_PARSE_PARAMETERS_END();
    falcon->serverSetAllowListEnabled(enabled ? 1 : 0);
}

// --- the server's name, slots, ticks and difficulty ---

PHP_FUNCTION(falcon_server_motd) {
    ZEND_PARSE_PARAMETERS_NONE();
    const char *motd = falcon->serverMotd();
    RETURN_STRING(motd != NULL ? motd : "");
}

PHP_FUNCTION(falcon_server_set_motd) {
    NAME_ARGUMENT(motd);
    falcon->serverSetMotd(ZSTR_VAL(motd));
}

PHP_FUNCTION(falcon_server_max_players) {
    ZEND_PARSE_PARAMETERS_NONE();
    RETURN_LONG((zend_long) falcon->serverMaxPlayers());
}

PHP_FUNCTION(falcon_server_set_max_players) {
    zend_long count;
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_LONG(count)
    ZEND_PARSE_PARAMETERS_END();
    RETURN_BOOL(falcon->serverSetMaxPlayers((uint32_t) (count > 0 ? count : 0)) != 0);
}

// [ticks per second, milliseconds per tick]
PHP_FUNCTION(falcon_server_tps) {
    ZEND_PARSE_PARAMETERS_NONE();
    array_init_size(return_value, 2);
    add_next_index_double(return_value, falcon->serverTps());
    add_next_index_double(return_value, falcon->serverMillisecondsPerTick());
}

PHP_FUNCTION(falcon_server_shutdown) {
    ZEND_PARSE_PARAMETERS_NONE();
    falcon->serverShutdown();
}

PHP_FUNCTION(falcon_server_difficulty) {
    ZEND_PARSE_PARAMETERS_NONE();
    RETURN_LONG(FALCON_HAS(serverDifficulty) ? (zend_long) falcon->serverDifficulty() : FALCON_DIFFICULTY_NORMAL);
}

PHP_FUNCTION(falcon_server_set_difficulty) {
    zend_long difficulty;
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_LONG(difficulty)
    ZEND_PARSE_PARAMETERS_END();
    RETURN_BOOL(FALCON_HAS(serverSetDifficulty) && falcon->serverSetDifficulty((FalconDifficulty) difficulty) != 0);
}

// the game rule of a level as Falcon writes it, "true", "3"; null for none of that name
PHP_FUNCTION(falcon_level_game_rule) {
    zend_long level;
    zend_string *name;
    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_LONG(level)
        Z_PARAM_STR(name)
    ZEND_PARSE_PARAMETERS_END();
    const char *value = FALCON_HAS(levelGameRule) ? falcon->levelGameRule(HANDLE(FalconLevel, level), ZSTR_VAL(name)) : NULL;
    if (value == NULL)
        RETURN_NULL();
    RETURN_STRING(value);
}

PHP_FUNCTION(falcon_level_set_game_rule) {
    zend_long level;
    zend_string *name, *value;
    ZEND_PARSE_PARAMETERS_START(3, 3)
        Z_PARAM_LONG(level)
        Z_PARAM_STR(name)
        Z_PARAM_STR(value)
    ZEND_PARSE_PARAMETERS_END();
    RETURN_BOOL(FALCON_HAS(levelSetGameRule) &&
                falcon->levelSetGameRule(HANDLE(FalconLevel, level), ZSTR_VAL(name), ZSTR_VAL(value)) != 0);
}

PHP_FUNCTION(falcon_level_biome) {
    zend_long level, x, y, z;
    ZEND_PARSE_PARAMETERS_START(4, 4)
        Z_PARAM_LONG(level)
        Z_PARAM_LONG(x)
        Z_PARAM_LONG(y)
        Z_PARAM_LONG(z)
    ZEND_PARSE_PARAMETERS_END();
    const char *biome = FALCON_HAS(levelBiome)
        ? falcon->levelBiome(HANDLE(FalconLevel, level), (FalconBlockPos){(int32_t) x, (int32_t) y, (int32_t) z}) : NULL;
    RETURN_STRING(biome != NULL ? biome : "");
}

PHP_FUNCTION(falcon_event_resource_packs) {
    zend_long handle;
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_LONG(handle)
    ZEND_PARSE_PARAMETERS_END();
    array_init(return_value);
#ifdef FALCON_API_RESOURCE_PACK_OFFER
    if (!FALCON_HAS(eventResourcePackCount) || !FALCON_HAS(eventResourcePackAt))
        return;
    FalconEvent *event = HANDLE(FalconEvent, handle);
    const uint32_t count = falcon->eventResourcePackCount(event);
    for (uint32_t i = 0; i < count; ++i) {
        FalconOfferedResourcePack pack;
        if (!falcon->eventResourcePackAt(event, i, &pack))
            continue;
        zval entry;
        array_init(&entry);
        add_next_index_string(&entry, pack.id);
        add_next_index_string(&entry, pack.name);
        add_next_index_string(&entry, pack.version);
        add_next_index_string(&entry, pack.contentKey);
        add_next_index_stringl(&entry, pack.sha256, 32);
        add_next_index_long(&entry, (zend_long) pack.size);
        add_next_index_stringl(&entry, pack.data, pack.dataSize);
        add_next_index_zval(return_value, &entry);
    }
#endif
}

PHP_FUNCTION(falcon_event_replace_resource_pack_offer) {
    zend_long handle;
    HashTable *entries;
    bool required;
    ZEND_PARSE_PARAMETERS_START(3, 3)
        Z_PARAM_LONG(handle)
        Z_PARAM_ARRAY_HT(entries)
        Z_PARAM_BOOL(required)
    ZEND_PARSE_PARAMETERS_END();
#ifdef FALCON_API_RESOURCE_PACK_OFFER
    enum { MAX_PACKS = 128, MAX_BYTES = 128 * 1024 * 1024 };
    const uint32_t count = zend_hash_num_elements(entries);
    if (!FALCON_HAS(eventReplaceResourcePackOffer) || count > MAX_PACKS)
        RETURN_FALSE;
    FalconResourcePackOfferEntry packs[MAX_PACKS];
    uint32_t index = 0;
    zval *entry;
    ZEND_HASH_FOREACH_VAL(entries, entry) {
        if (Z_TYPE_P(entry) != IS_ARRAY)
            RETURN_FALSE;
        const zval *id = zend_hash_index_find(Z_ARRVAL_P(entry), 0);
        const zval *key = zend_hash_index_find(Z_ARRVAL_P(entry), 1);
        const zval *data = zend_hash_index_find(Z_ARRVAL_P(entry), 2);
        if (id == NULL || Z_TYPE_P(id) != IS_STRING || strlen(Z_STRVAL_P(id)) != Z_STRLEN_P(id) ||
            key == NULL || Z_TYPE_P(key) != IS_STRING || strlen(Z_STRVAL_P(key)) != Z_STRLEN_P(key) ||
            (data != NULL && (Z_TYPE_P(data) != IS_STRING || Z_STRLEN_P(data) > MAX_BYTES)))
            RETURN_FALSE;
        packs[index++] = (FalconResourcePackOfferEntry) {
            Z_STRVAL_P(id),
            {data != NULL ? Z_STRVAL_P(data) : NULL, data != NULL ? (uint32_t) Z_STRLEN_P(data) : 0, Z_STRVAL_P(key)}
        };
    } ZEND_HASH_FOREACH_END();
    RETURN_BOOL(falcon->eventReplaceResourcePackOffer(HANDLE(FalconEvent, handle), packs, count, required ? 1 : 0));
#else
    RETURN_FALSE;
#endif
}

PHP_FUNCTION(falcon_server_replace_resource_packs) {
    HashTable *entries;
    bool required;
    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_ARRAY_HT(entries)
        Z_PARAM_BOOL(required)
    ZEND_PARSE_PARAMETERS_END();
#ifdef FALCON_API_RESOURCE_PACKS
    enum { MAX_PACKS = 128, MAX_BYTES = 128 * 1024 * 1024 };
    const uint32_t count = zend_hash_num_elements(entries);
    if (!FALCON_HAS(resourcePacksReplace) || count > MAX_PACKS)
        RETURN_FALSE;

    FalconResourcePackDescriptor packs[MAX_PACKS];
    uint32_t index = 0;
    zval *entry;
    ZEND_HASH_FOREACH_VAL(entries, entry) {
        if (Z_TYPE_P(entry) != IS_ARRAY)
            RETURN_FALSE;
        zval *data = zend_hash_index_find(Z_ARRVAL_P(entry), 0);
        zval *key = zend_hash_index_find(Z_ARRVAL_P(entry), 1);
        if (data == NULL || Z_TYPE_P(data) != IS_STRING || Z_STRLEN_P(data) > MAX_BYTES ||
            key == NULL || Z_TYPE_P(key) != IS_STRING || strlen(Z_STRVAL_P(key)) != Z_STRLEN_P(key))
            RETURN_FALSE;
        packs[index++] = (FalconResourcePackDescriptor) {
            Z_STRVAL_P(data), (uint32_t) Z_STRLEN_P(data), Z_STRVAL_P(key)
        };
    } ZEND_HASH_FOREACH_END();
    RETURN_BOOL(falcon->resourcePacksReplace(falcon_plugin, packs, count, required ? 1 : 0) != 0);
#else
    RETURN_FALSE;
#endif
}

// --- what the game knows of item and block types ---

PHP_FUNCTION(falcon_register_custom_item) {
    zend_string *identifier, *name, *icon;
    zend_long maxStackSize;
    ZEND_PARSE_PARAMETERS_START(4, 4)
        Z_PARAM_PATH_STR(identifier)
        Z_PARAM_PATH_STR(name)
        Z_PARAM_PATH_STR(icon)
        Z_PARAM_LONG(maxStackSize)
    ZEND_PARSE_PARAMETERS_END();
    if (!FALCON_HAS(registerCustomItem) || maxStackSize < 1 || maxStackSize > 64)
        RETURN_FALSE;
    FalconCustomItemDescriptor item = {0};
    item.identifier = ZSTR_VAL(identifier);
    item.displayName = ZSTR_VAL(name);
    item.icon = ZSTR_VAL(icon);
    item.maxStackSize = (uint32_t) maxStackSize;
    RETURN_BOOL(falcon->registerCustomItem(falcon_plugin, &item) != 0);
}

PHP_FUNCTION(falcon_register_recipe) {
    zend_string *identifier, *result;
    zend_long width, height, count;
    HashTable *inputs;
    ZEND_PARSE_PARAMETERS_START(6, 6)
        Z_PARAM_PATH_STR(identifier)
        Z_PARAM_LONG(width)
        Z_PARAM_LONG(height)
        Z_PARAM_ARRAY_HT(inputs)
        Z_PARAM_PATH_STR(result)
        Z_PARAM_LONG(count)
    ZEND_PARSE_PARAMETERS_END();
    const uint32_t size = zend_hash_num_elements(inputs);
    if (!FALCON_HAS(registerRecipe) || size == 0 || size > 9 || count < 1 || count > 64 ||
        width < 0 || width > 3 || height < 0 || height > 3)
        RETURN_FALSE;
    const char *ingredients[9];
    uint32_t index = 0;
    zval *input;
    ZEND_HASH_FOREACH_VAL(inputs, input) {
        if (Z_TYPE_P(input) == IS_NULL) {
            ingredients[index++] = NULL;
        } else if (Z_TYPE_P(input) == IS_STRING && strlen(Z_STRVAL_P(input)) == Z_STRLEN_P(input)) {
            ingredients[index++] = Z_STRVAL_P(input);
        } else {
            RETURN_FALSE;
        }
    } ZEND_HASH_FOREACH_END();
    const FalconRecipeDescriptor recipe = {
        ZSTR_VAL(identifier), width != 0, (uint32_t) width, (uint32_t) height,
        ingredients, size, ZSTR_VAL(result), (uint32_t) count
    };
    RETURN_BOOL(falcon->registerRecipe(falcon_plugin, &recipe) != 0);
}

// null for an unknown identifier, or on a Falcon without it
PHP_FUNCTION(falcon_item_info) {
    NAME_ARGUMENT(identifier);
    FalconItemInfo info;
    if (!FALCON_HAS(serverItemInfo) || falcon->serverItemInfo(ZSTR_VAL(identifier), &info) == 0)
        RETURN_NULL();
    array_init_size(return_value, 11);
    add_assoc_string(return_value, "name", info.name != NULL ? info.name : "");
    add_assoc_long(return_value, "maxStackSize", (zend_long) info.maxStackSize);
    add_assoc_long(return_value, "maxDurability", (zend_long) info.maxDurability);
    add_assoc_long(return_value, "toolType", (zend_long) info.toolType);
    add_assoc_long(return_value, "toolTier", (zend_long) info.toolTier);
    add_assoc_long(return_value, "attackDamage", (zend_long) info.attackDamage);
    add_assoc_long(return_value, "armorSlot", (zend_long) info.armorSlot);
    add_assoc_long(return_value, "armorPoints", (zend_long) info.armorPoints);
    add_assoc_long(return_value, "armorToughness", (zend_long) info.armorToughness);
    add_assoc_long(return_value, "nutrition", (zend_long) info.nutrition);
    add_assoc_double(return_value, "saturation", info.saturation);
}

PHP_FUNCTION(falcon_block_info) {
    NAME_ARGUMENT(identifier);
    FalconBlockInfo info;
    if (!FALCON_HAS(serverBlockInfo) || falcon->serverBlockInfo(ZSTR_VAL(identifier), &info) == 0)
        RETURN_NULL();
    array_init_size(return_value, 11);
    add_assoc_string(return_value, "name", info.name != NULL ? info.name : "");
    add_assoc_double(return_value, "hardness", info.hardness);
    add_assoc_double(return_value, "blastResistance", info.blastResistance);
    add_assoc_long(return_value, "toolType", (zend_long) info.toolType);
    add_assoc_long(return_value, "toolTier", (zend_long) info.toolTier);
    add_assoc_long(return_value, "lightEmission", (zend_long) info.lightEmission);
    add_assoc_bool(return_value, "transparent", info.transparent != 0);
    add_assoc_bool(return_value, "solid", info.solid != 0);
    add_assoc_long(return_value, "flameEncouragement", (zend_long) info.flameEncouragement);
    add_assoc_long(return_value, "flammability", (zend_long) info.flammability);
    add_assoc_long(return_value, "maxStackSize", (zend_long) info.maxStackSize);
}

// --- drops, experience, keepInventory and knockback of the events that carry them ---

#define EVENT_ARGUMENT(event) \
    zend_long event; \
    ZEND_PARSE_PARAMETERS_START(1, 1) \
        Z_PARAM_LONG(event) \
    ZEND_PARSE_PARAMETERS_END()

// null when the event carries no drops, or on a Falcon without them
PHP_FUNCTION(falcon_event_drops) {
    EVENT_ARGUMENT(event);
    if (!FALCON_HAS(eventDropAt))
        RETURN_NULL();
    FalconEvent *handle = HANDLE(FalconEvent, event);
    const uint32_t count = falcon->eventDropCount(handle);
    array_init_size(return_value, count);
    for (uint32_t i = 0; i < count; ++i) {
        FalconItem *item = falcon->eventDropAt(handle, i);
        if (item == NULL)
            continue;
        zval entry;
        item_to_array(item, &entry);
        add_next_index_zval(return_value, &entry);
    }
}

PHP_FUNCTION(falcon_event_set_drops) {
    zend_long event;
    HashTable *items;
    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_LONG(event)
        Z_PARAM_ARRAY_HT(items)
    ZEND_PARSE_PARAMETERS_END();
    if (!FALCON_HAS(eventSetDrops))
        RETURN_FALSE;
    const uint32_t total = zend_hash_num_elements(items);
    FalconItem **handles = total > 0 ? ecalloc(total, sizeof(FalconItem *)) : NULL;
    uint32_t count = 0;
    zval *entry;
    ZEND_HASH_FOREACH_VAL(items, entry) {
        if (Z_TYPE_P(entry) == IS_ARRAY) {
            FalconItem *item = item_from_array(Z_ARRVAL_P(entry));
            if (item != NULL)
                handles[count++] = item;
        }
    } ZEND_HASH_FOREACH_END();
    falcon->eventSetDrops(HANDLE(FalconEvent, event), handles, count);
    for (uint32_t i = 0; i < count; ++i)
        falcon->itemDestroy(handles[i]);
    if (handles != NULL)
        efree(handles);
    RETURN_TRUE;
}

PHP_FUNCTION(falcon_event_experience) {
    EVENT_ARGUMENT(event);
    RETURN_LONG(FALCON_HAS(eventExperience) ? (zend_long) falcon->eventExperience(HANDLE(FalconEvent, event)) : 0);
}

PHP_FUNCTION(falcon_event_set_experience) {
    zend_long event, experience;
    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_LONG(event)
        Z_PARAM_LONG(experience)
    ZEND_PARSE_PARAMETERS_END();
    if (FALCON_HAS(eventSetExperience))
        falcon->eventSetExperience(HANDLE(FalconEvent, event), (int32_t) experience);
}

// [keepInventory, keepExperience]
PHP_FUNCTION(falcon_event_keep) {
    EVENT_ARGUMENT(event);
    FalconEvent *handle = HANDLE(FalconEvent, event);
    array_init_size(return_value, 2);
    add_next_index_bool(return_value, FALCON_HAS(eventKeepInventory) && falcon->eventKeepInventory(handle) != 0);
    add_next_index_bool(return_value, FALCON_HAS(eventKeepExperience) && falcon->eventKeepExperience(handle) != 0);
}

PHP_FUNCTION(falcon_event_set_keep) {
    zend_long event;
    bool inventory, experience;
    ZEND_PARSE_PARAMETERS_START(3, 3)
        Z_PARAM_LONG(event)
        Z_PARAM_BOOL(inventory)
        Z_PARAM_BOOL(experience)
    ZEND_PARSE_PARAMETERS_END();
    FalconEvent *handle = HANDLE(FalconEvent, event);
    if (FALCON_HAS(eventSetKeepInventory))
        falcon->eventSetKeepInventory(handle, inventory ? 1 : 0);
    if (FALCON_HAS(eventSetKeepExperience))
        falcon->eventSetKeepExperience(handle, experience ? 1 : 0);
}

// [horizontal, vertical], null for damage that pushes nothing
PHP_FUNCTION(falcon_event_knockback) {
    EVENT_ARGUMENT(event);
    float horizontal = 0.0f, vertical = 0.0f;
    if (!FALCON_HAS(eventKnockback) || falcon->eventKnockback(HANDLE(FalconEvent, event), &horizontal, &vertical) == 0)
        RETURN_NULL();
    array_init_size(return_value, 2);
    add_next_index_double(return_value, horizontal);
    add_next_index_double(return_value, vertical);
}

PHP_FUNCTION(falcon_event_set_knockback) {
    zend_long event;
    double horizontal, vertical;
    ZEND_PARSE_PARAMETERS_START(3, 3)
        Z_PARAM_LONG(event)
        Z_PARAM_DOUBLE(horizontal)
        Z_PARAM_DOUBLE(vertical)
    ZEND_PARSE_PARAMETERS_END();
    if (FALCON_HAS(eventSetKnockback))
        falcon->eventSetKnockback(HANDLE(FalconEvent, event), (float) horizontal, (float) vertical);
}

ZEND_BEGIN_ARG_INFO_EX(arginfo_server_any, 0, 0, 0)
    ZEND_ARG_VARIADIC_INFO(0, arguments)
ZEND_END_ARG_INFO()

#define ENTRY(name) ZEND_RAW_FENTRY(#name, ZEND_FN(name), arginfo_server_any, 0)

const zend_function_entry falcon_server_functions[] = {
    ENTRY(falcon_server_ban)
    ENTRY(falcon_server_pardon)
    ENTRY(falcon_server_is_banned)
    ENTRY(falcon_server_bans)
    ENTRY(falcon_server_allow)
    ENTRY(falcon_server_allow_list)
    ENTRY(falcon_server_allow_list_enabled)
    ENTRY(falcon_server_set_allow_list_enabled)
    ENTRY(falcon_server_motd)
    ENTRY(falcon_server_set_motd)
    ENTRY(falcon_server_max_players)
    ENTRY(falcon_server_set_max_players)
    ENTRY(falcon_server_tps)
    ENTRY(falcon_server_shutdown)
    ENTRY(falcon_server_difficulty)
    ENTRY(falcon_server_set_difficulty)
    ENTRY(falcon_level_game_rule)
    ENTRY(falcon_level_set_game_rule)
    ENTRY(falcon_level_biome)
    ENTRY(falcon_event_resource_packs)
    ENTRY(falcon_event_replace_resource_pack_offer)
    ENTRY(falcon_server_replace_resource_packs)
    ENTRY(falcon_item_info)
    ENTRY(falcon_register_custom_item)
    ENTRY(falcon_register_recipe)
    ENTRY(falcon_block_info)
    ENTRY(falcon_event_drops)
    ENTRY(falcon_event_set_drops)
    ENTRY(falcon_event_experience)
    ENTRY(falcon_event_set_experience)
    ENTRY(falcon_event_keep)
    ENTRY(falcon_event_set_keep)
    ENTRY(falcon_event_knockback)
    ENTRY(falcon_event_set_knockback)
    PHP_FE_END
};
