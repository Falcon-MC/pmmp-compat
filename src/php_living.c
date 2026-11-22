// effects, attributes, skins, weather, explosions and signs for the PHP side
#include "php_bridge_internal.h"

#include <zend_API.h>

#include <string.h>

static void effect_to_array(const FalconEffect *effect, zval *out) {
    array_init_size(out, 5);
    add_next_index_long(out, (zend_long) effect->type);
    add_next_index_long(out, (zend_long) effect->duration);
    add_next_index_long(out, (zend_long) effect->amplifier);
    add_next_index_bool(out, effect->showParticles != 0);
    add_next_index_bool(out, effect->ambient != 0);
}

// --- effects ---

PHP_FUNCTION(falcon_entity_add_effect) {
    zend_long entity, type, duration, amplifier;
    bool particles = true;
    ZEND_PARSE_PARAMETERS_START(4, 5)
        Z_PARAM_LONG(entity)
        Z_PARAM_LONG(type)
        Z_PARAM_LONG(duration)
        Z_PARAM_LONG(amplifier)
        Z_PARAM_OPTIONAL
        Z_PARAM_BOOL(particles)
    ZEND_PARSE_PARAMETERS_END();
    if (!FALCON_HAS(entityAddEffect))
        RETURN_FALSE;
    RETURN_BOOL(falcon->entityAddEffect(HANDLE(FalconEntity, entity), (FalconEffectType) type, (int32_t) duration,
                                        (int32_t) amplifier, particles ? 1 : 0) != 0);
}

PHP_FUNCTION(falcon_entity_remove_effect) {
    zend_long entity, type;
    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_LONG(entity)
        Z_PARAM_LONG(type)
    ZEND_PARSE_PARAMETERS_END();
    if (!FALCON_HAS(entityRemoveEffect))
        RETURN_FALSE;
    RETURN_BOOL(falcon->entityRemoveEffect(HANDLE(FalconEntity, entity), (FalconEffectType) type) != 0);
}

// [[type, duration, amplifier, particles, ambient], ...]
PHP_FUNCTION(falcon_entity_effects) {
    zend_long entity;
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_LONG(entity)
    ZEND_PARSE_PARAMETERS_END();
    array_init(return_value);
    if (!FALCON_HAS(entityEffectAt))
        return;
    FalconEntity *handle = HANDLE(FalconEntity, entity);
    const uint32_t count = falcon->entityEffectCount(handle);
    for (uint32_t i = 0; i < count; ++i) {
        FalconEffect effect;
        if (falcon->entityEffectAt(handle, i, &effect) == 0)
            continue;
        zval entry;
        effect_to_array(&effect, &entry);
        add_next_index_zval(return_value, &entry);
    }
}

PHP_FUNCTION(falcon_entity_clear_effects) {
    zend_long entity;
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_LONG(entity)
    ZEND_PARSE_PARAMETERS_END();
    if (FALCON_HAS(entityClearEffects))
        falcon->entityClearEffects(HANDLE(FalconEntity, entity));
}

PHP_FUNCTION(falcon_event_effect) {
    zend_long event;
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_LONG(event)
    ZEND_PARSE_PARAMETERS_END();
    FalconEffect effect;
    if (!FALCON_HAS(eventEffect) || falcon->eventEffect(HANDLE(FalconEvent, event), &effect) == 0)
        RETURN_NULL();
    effect_to_array(&effect, return_value);
}

// --- attributes: health boost, hunger, saturation, exhaustion, speed... ---

// [value, minimum, maximum, default], null when the entity has none of that name
PHP_FUNCTION(falcon_entity_attribute) {
    zend_long entity;
    zend_string *name;
    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_LONG(entity)
        Z_PARAM_STR(name)
    ZEND_PARSE_PARAMETERS_END();
    FalconAttribute attribute;
    if (!FALCON_HAS(entityAttribute) ||
        falcon->entityAttribute(HANDLE(FalconEntity, entity), ZSTR_VAL(name), &attribute) == 0)
        RETURN_NULL();
    array_init_size(return_value, 4);
    add_next_index_double(return_value, attribute.value);
    add_next_index_double(return_value, attribute.minimum);
    add_next_index_double(return_value, attribute.maximum);
    add_next_index_double(return_value, attribute.defaultValue);
}

PHP_FUNCTION(falcon_entity_set_attribute) {
    zend_long entity;
    zend_string *name;
    double value;
    ZEND_PARSE_PARAMETERS_START(3, 3)
        Z_PARAM_LONG(entity)
        Z_PARAM_STR(name)
        Z_PARAM_DOUBLE(value)
    ZEND_PARSE_PARAMETERS_END();
    if (!FALCON_HAS(entitySetAttribute))
        RETURN_FALSE;
    RETURN_BOOL(falcon->entitySetAttribute(HANDLE(FalconEntity, entity), ZSTR_VAL(name), (float) value) != 0);
}

PHP_FUNCTION(falcon_entity_set_on_fire) {
    zend_long entity, ticks;
    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_LONG(entity)
        Z_PARAM_LONG(ticks)
    ZEND_PARSE_PARAMETERS_END();
    falcon->entitySetOnFire(HANDLE(FalconEntity, entity), (uint32_t) (ticks > 0 ? ticks : 0));
}

PHP_FUNCTION(falcon_entity_set_invisible) {
    zend_long entity;
    bool invisible;
    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_LONG(entity)
        Z_PARAM_BOOL(invisible)
    ZEND_PARSE_PARAMETERS_END();
    if (FALCON_HAS(entitySetInvisible))
        falcon->entitySetInvisible(HANDLE(FalconEntity, entity), invisible ? 1 : 0);
}

// --- food and experience ---

PHP_FUNCTION(falcon_player_food) {
    zend_long player;
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_LONG(player)
    ZEND_PARSE_PARAMETERS_END();
    RETURN_DOUBLE(falcon->playerFood(HANDLE(FalconPlayer, player)));
}

PHP_FUNCTION(falcon_player_set_food) {
    zend_long player;
    double food;
    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_LONG(player)
        Z_PARAM_DOUBLE(food)
    ZEND_PARSE_PARAMETERS_END();
    falcon->playerSetFood(HANDLE(FalconPlayer, player), (float) food);
}

PHP_FUNCTION(falcon_player_xp_level) {
    zend_long player;
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_LONG(player)
    ZEND_PARSE_PARAMETERS_END();
    RETURN_LONG((zend_long) falcon->playerXpLevel(HANDLE(FalconPlayer, player)));
}

PHP_FUNCTION(falcon_player_set_xp_level) {
    zend_long player, level;
    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_LONG(player)
        Z_PARAM_LONG(level)
    ZEND_PARSE_PARAMETERS_END();
    falcon->playerSetXpLevel(HANDLE(FalconPlayer, player), (int32_t) level);
}

// --- skins ---

// [skinId, width, height, rgba], null when Falcon has none for the player
PHP_FUNCTION(falcon_player_skin) {
    zend_long player;
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_LONG(player)
    ZEND_PARSE_PARAMETERS_END();
    if (!FALCON_HAS(playerSkinImage))
        RETURN_NULL();
    FalconPlayer *handle = HANDLE(FalconPlayer, player);
    uint32_t width = 0, height = 0, length = 0;
    const uint8_t *rgba = falcon->playerSkinImage(handle, &width, &height, &length);
    const char *id = falcon->playerSkinId(handle);
    array_init_size(return_value, 4);
    add_next_index_string(return_value, id != NULL ? id : "");
    add_next_index_long(return_value, (zend_long) width);
    add_next_index_long(return_value, (zend_long) height);
    add_next_index_stringl(return_value, rgba != NULL ? (const char *) rgba : "", rgba != NULL ? length : 0);
}

PHP_FUNCTION(falcon_player_set_skin) {
    zend_long player, width, height;
    zend_string *id, *rgba;
    ZEND_PARSE_PARAMETERS_START(5, 5)
        Z_PARAM_LONG(player)
        Z_PARAM_STR(id)
        Z_PARAM_STR(rgba)
        Z_PARAM_LONG(width)
        Z_PARAM_LONG(height)
    ZEND_PARSE_PARAMETERS_END();
    if (!FALCON_HAS(playerSetSkin))
        RETURN_FALSE;
    RETURN_BOOL(falcon->playerSetSkin(HANDLE(FalconPlayer, player), ZSTR_VAL(id), (const uint8_t *) ZSTR_VAL(rgba),
                                      (uint32_t) ZSTR_LEN(rgba), (uint32_t) width, (uint32_t) height) != 0);
}

// --- weather, explosions, lightning ---

PHP_FUNCTION(falcon_level_weather) {
    zend_long level;
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_LONG(level)
    ZEND_PARSE_PARAMETERS_END();
    FalconLevel *handle = HANDLE(FalconLevel, level);
    array_init_size(return_value, 2);
    add_next_index_bool(return_value, falcon->levelIsRaining(handle) != 0);
    add_next_index_bool(return_value, falcon->levelIsThundering(handle) != 0);
}

PHP_FUNCTION(falcon_level_set_weather) {
    zend_long level;
    bool raining, thundering;
    ZEND_PARSE_PARAMETERS_START(3, 3)
        Z_PARAM_LONG(level)
        Z_PARAM_BOOL(raining)
        Z_PARAM_BOOL(thundering)
    ZEND_PARSE_PARAMETERS_END();
    FalconLevel *handle = HANDLE(FalconLevel, level);
    falcon->levelSetRaining(handle, raining ? 1 : 0);
    falcon->levelSetThundering(handle, thundering ? 1 : 0);
}

PHP_FUNCTION(falcon_level_explode) {
    zend_long level;
    double x, y, z, power;
    bool breakBlocks;
    ZEND_PARSE_PARAMETERS_START(6, 6)
        Z_PARAM_LONG(level)
        Z_PARAM_DOUBLE(x)
        Z_PARAM_DOUBLE(y)
        Z_PARAM_DOUBLE(z)
        Z_PARAM_DOUBLE(power)
        Z_PARAM_BOOL(breakBlocks)
    ZEND_PARSE_PARAMETERS_END();
    falcon->levelCreateExplosion(HANDLE(FalconLevel, level), (FalconVec3){x, y, z}, (float) power, breakBlocks ? 1 : 0);
}

PHP_FUNCTION(falcon_level_lightning) {
    zend_long level;
    double x, y, z;
    ZEND_PARSE_PARAMETERS_START(4, 4)
        Z_PARAM_LONG(level)
        Z_PARAM_DOUBLE(x)
        Z_PARAM_DOUBLE(y)
        Z_PARAM_DOUBLE(z)
    ZEND_PARSE_PARAMETERS_END();
    falcon->levelStrikeLightning(HANDLE(FalconLevel, level), (FalconVec3){x, y, z});
}

PHP_FUNCTION(falcon_level_spawn_entity) {
    zend_long level;
    zend_string *identifier;
    double x, y, z;
    ZEND_PARSE_PARAMETERS_START(5, 5)
        Z_PARAM_LONG(level)
        Z_PARAM_STR(identifier)
        Z_PARAM_DOUBLE(x)
        Z_PARAM_DOUBLE(y)
        Z_PARAM_DOUBLE(z)
    ZEND_PARSE_PARAMETERS_END();
    FalconEntity *entity = falcon->levelSpawnEntity(HANDLE(FalconLevel, level), ZSTR_VAL(identifier), (FalconVec3){x, y, z});
    if (entity == NULL)
        RETURN_NULL();
    RETURN_LONG(TO_LONG(entity));
}

// --- signs ---

PHP_FUNCTION(falcon_level_sign_text) {
    zend_long level, x, y, z, side;
    ZEND_PARSE_PARAMETERS_START(5, 5)
        Z_PARAM_LONG(level)
        Z_PARAM_LONG(x)
        Z_PARAM_LONG(y)
        Z_PARAM_LONG(z)
        Z_PARAM_LONG(side)
    ZEND_PARSE_PARAMETERS_END();
    const char *text = falcon->levelSignText(HANDLE(FalconLevel, level), (FalconBlockPos){(int32_t) x, (int32_t) y, (int32_t) z},
                                             (FalconSignSide) side);
    if (text == NULL)
        RETURN_NULL();
    RETURN_STRING(text);
}

PHP_FUNCTION(falcon_level_set_sign_text) {
    zend_long level, x, y, z, side;
    zend_string *text;
    ZEND_PARSE_PARAMETERS_START(6, 6)
        Z_PARAM_LONG(level)
        Z_PARAM_LONG(x)
        Z_PARAM_LONG(y)
        Z_PARAM_LONG(z)
        Z_PARAM_LONG(side)
        Z_PARAM_STR(text)
    ZEND_PARSE_PARAMETERS_END();
    RETURN_BOOL(falcon->levelSetSignText(HANDLE(FalconLevel, level), (FalconBlockPos){(int32_t) x, (int32_t) y, (int32_t) z},
                                         (FalconSignSide) side, ZSTR_VAL(text)) != 0);
}

// --- what world and chunk events carry ---

PHP_FUNCTION(falcon_event_world_name) {
    zend_long event;
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_LONG(event)
    ZEND_PARSE_PARAMETERS_END();
    const char *name = falcon->eventWorldName(HANDLE(FalconEvent, event));
    RETURN_STRING(name != NULL ? name : "");
}

PHP_FUNCTION(falcon_event_chunk) {
    zend_long event;
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_LONG(event)
    ZEND_PARSE_PARAMETERS_END();
    FalconEvent *handle = HANDLE(FalconEvent, event);
    array_init_size(return_value, 2);
    add_next_index_long(return_value, (zend_long) falcon->eventChunkX(handle));
    add_next_index_long(return_value, (zend_long) falcon->eventChunkZ(handle));
}

PHP_FUNCTION(falcon_event_previous_amount) {
    zend_long event;
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_LONG(event)
    ZEND_PARSE_PARAMETERS_END();
    RETURN_DOUBLE(falcon->eventPreviousAmount(HANDLE(FalconEvent, event)));
}

PHP_FUNCTION(falcon_event_blocks) {
    zend_long event;
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_LONG(event)
    ZEND_PARSE_PARAMETERS_END();
    FalconEvent *handle = HANDLE(FalconEvent, event);
    const uint32_t count = falcon->eventBlockCount(handle);
    array_init_size(return_value, count);
    for (uint32_t i = 0; i < count; ++i) {
        const FalconBlockPos pos = falcon->eventBlockAt(handle, i);
        zval entry;
        array_init_size(&entry, 3);
        add_next_index_long(&entry, pos.x);
        add_next_index_long(&entry, pos.y);
        add_next_index_long(&entry, pos.z);
        add_next_index_zval(return_value, &entry);
    }
}

PHP_FUNCTION(falcon_event_set_blocks) {
    zend_long event;
    HashTable *blocks;
    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_LONG(event)
        Z_PARAM_ARRAY_HT(blocks)
    ZEND_PARSE_PARAMETERS_END();
    const uint32_t count = zend_hash_num_elements(blocks);
    FalconBlockPos *positions = count > 0 ? emalloc(sizeof(FalconBlockPos) * count) : NULL;
    uint32_t used = 0;
    zval *entry;
    ZEND_HASH_FOREACH_VAL(blocks, entry) {
        if (Z_TYPE_P(entry) != IS_ARRAY || zend_hash_num_elements(Z_ARRVAL_P(entry)) < 3)
            continue;
        zval *x = zend_hash_index_find(Z_ARRVAL_P(entry), 0), *y = zend_hash_index_find(Z_ARRVAL_P(entry), 1),
             *z = zend_hash_index_find(Z_ARRVAL_P(entry), 2);
        if (x == NULL || y == NULL || z == NULL)
            continue;
        positions[used++] = (FalconBlockPos){(int32_t) zval_get_long(x), (int32_t) zval_get_long(y), (int32_t) zval_get_long(z)};
    } ZEND_HASH_FOREACH_END();
    falcon->eventSetBlocks(HANDLE(FalconEvent, event), positions, used);
    if (positions != NULL)
        efree(positions);
}


// --- the player's spawn point, language, device, transfers ---

PHP_FUNCTION(falcon_player_transfer) {
    zend_long player, port;
    zend_string *address;
    ZEND_PARSE_PARAMETERS_START(3, 3)
        Z_PARAM_LONG(player)
        Z_PARAM_STR(address)
        Z_PARAM_LONG(port)
    ZEND_PARSE_PARAMETERS_END();
    RETURN_BOOL(falcon->playerTransfer(HANDLE(FalconPlayer, player), ZSTR_VAL(address), (uint32_t) port) != 0);
}

// [x, y, z, dimension], null when the player has none of their own
PHP_FUNCTION(falcon_player_spawn_point) {
    zend_long player;
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_LONG(player)
    ZEND_PARSE_PARAMETERS_END();
    FalconBlockPos position;
    FalconDimension dimension;
    if (!FALCON_HAS(playerSpawnPoint) || falcon->playerSpawnPoint(HANDLE(FalconPlayer, player), &position, &dimension) == 0)
        RETURN_NULL();
    array_init_size(return_value, 4);
    add_next_index_long(return_value, position.x);
    add_next_index_long(return_value, position.y);
    add_next_index_long(return_value, position.z);
    add_next_index_long(return_value, (zend_long) dimension);
}

PHP_FUNCTION(falcon_player_set_spawn_point) {
    zend_long player, level, x, y, z;
    ZEND_PARSE_PARAMETERS_START(5, 5)
        Z_PARAM_LONG(player)
        Z_PARAM_LONG(level)
        Z_PARAM_LONG(x)
        Z_PARAM_LONG(y)
        Z_PARAM_LONG(z)
    ZEND_PARSE_PARAMETERS_END();
    if (!FALCON_HAS(playerSetSpawnPoint))
        RETURN_FALSE;
    if (level == 0) {
        falcon->playerClearSpawnPoint(HANDLE(FalconPlayer, player));
        RETURN_TRUE;
    }
    RETURN_BOOL(falcon->playerSetSpawnPoint(HANDLE(FalconPlayer, player), HANDLE(FalconLevel, level),
                                            (FalconBlockPos){(int32_t) x, (int32_t) y, (int32_t) z}) != 0);
}

PHP_FUNCTION(falcon_player_language) {
    zend_long player;
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_LONG(player)
    ZEND_PARSE_PARAMETERS_END();
    const char *language = FALCON_HAS(playerLanguage) ? falcon->playerLanguage(HANDLE(FalconPlayer, player)) : NULL;
    RETURN_STRING(language != NULL && language[0] != '\0' ? language : "en_US");
}

PHP_FUNCTION(falcon_player_device_os) {
    zend_long player;
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_LONG(player)
    ZEND_PARSE_PARAMETERS_END();
    RETURN_LONG(FALCON_HAS(playerDeviceOs) ? (zend_long) falcon->playerDeviceOs(HANDLE(FalconPlayer, player)) : -1);
}

PHP_FUNCTION(falcon_player_set_operator) {
    zend_long player;
    bool op;
    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_LONG(player)
        Z_PARAM_BOOL(op)
    ZEND_PARSE_PARAMETERS_END();
    falcon->playerSetOperator(HANDLE(FalconPlayer, player), op ? 1 : 0);
}

PHP_FUNCTION(falcon_player_unique_id) {
    zend_long player;
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_LONG(player)
    ZEND_PARSE_PARAMETERS_END();
    RETURN_LONG(FALCON_HAS(playerUniqueId) ? (zend_long) falcon->playerUniqueId(HANDLE(FalconPlayer, player)) : 0);
}

PHP_FUNCTION(falcon_player_play_sound) {
    zend_long player;
    zend_string *sound;
    double x, y, z, volume, pitch;
    ZEND_PARSE_PARAMETERS_START(7, 7)
        Z_PARAM_LONG(player)
        Z_PARAM_STR(sound)
        Z_PARAM_DOUBLE(x)
        Z_PARAM_DOUBLE(y)
        Z_PARAM_DOUBLE(z)
        Z_PARAM_DOUBLE(volume)
        Z_PARAM_DOUBLE(pitch)
    ZEND_PARSE_PARAMETERS_END();
    if (FALCON_HAS(playerPlaySound))
        falcon->playerPlaySound(HANDLE(FalconPlayer, player), ZSTR_VAL(sound), (FalconVec3){x, y, z}, (float) volume,
                                (float) pitch);
}

PHP_FUNCTION(falcon_player_clear_inventory) {
    zend_long player;
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_LONG(player)
    ZEND_PARSE_PARAMETERS_END();
    falcon->playerClearInventory(HANDLE(FalconPlayer, player));
}

// --- what projectile hits and furnaces carry ---

PHP_FUNCTION(falcon_event_target) {
    zend_long event;
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_LONG(event)
    ZEND_PARSE_PARAMETERS_END();
    RETURN_LONG(TO_LONG(falcon->eventTarget(HANDLE(FalconEvent, event))));
}

PHP_FUNCTION(falcon_event_result) {
    zend_long event;
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_LONG(event)
    ZEND_PARSE_PARAMETERS_END();
    FalconItem *item = falcon->eventResult(HANDLE(FalconEvent, event));
    if (item == NULL)
        RETURN_NULL();
    item_to_array(item, return_value);
}

PHP_FUNCTION(falcon_event_set_result) {
    zend_long event;
    HashTable *data;
    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_LONG(event)
        Z_PARAM_ARRAY_HT(data)
    ZEND_PARSE_PARAMETERS_END();
    FalconItem *item = item_from_array(data);
    if (item == NULL)
        RETURN_FALSE;
    falcon->eventSetResult(HANDLE(FalconEvent, event), item);
    falcon->itemDestroy(item);
    RETURN_TRUE;
}

ZEND_BEGIN_ARG_INFO_EX(arginfo_living_any, 0, 0, 0)
    ZEND_ARG_VARIADIC_INFO(0, arguments)
ZEND_END_ARG_INFO()

#define ENTRY(name) ZEND_RAW_FENTRY(#name, ZEND_FN(name), arginfo_living_any, 0)

const zend_function_entry falcon_living_functions[] = {
    ENTRY(falcon_entity_add_effect)
    ENTRY(falcon_entity_remove_effect)
    ENTRY(falcon_entity_effects)
    ENTRY(falcon_entity_clear_effects)
    ENTRY(falcon_event_effect)
    ENTRY(falcon_entity_attribute)
    ENTRY(falcon_entity_set_attribute)
    ENTRY(falcon_entity_set_on_fire)
    ENTRY(falcon_entity_set_invisible)
    ENTRY(falcon_player_food)
    ENTRY(falcon_player_set_food)
    ENTRY(falcon_player_xp_level)
    ENTRY(falcon_player_set_xp_level)
    ENTRY(falcon_player_skin)
    ENTRY(falcon_player_set_skin)
    ENTRY(falcon_level_weather)
    ENTRY(falcon_level_set_weather)
    ENTRY(falcon_level_explode)
    ENTRY(falcon_level_lightning)
    ENTRY(falcon_level_spawn_entity)
    ENTRY(falcon_level_sign_text)
    ENTRY(falcon_level_set_sign_text)
    ENTRY(falcon_event_world_name)
    ENTRY(falcon_event_chunk)
    ENTRY(falcon_event_previous_amount)
    ENTRY(falcon_event_blocks)
    ENTRY(falcon_event_set_blocks)
    ENTRY(falcon_player_transfer)
    ENTRY(falcon_player_spawn_point)
    ENTRY(falcon_player_set_spawn_point)
    ENTRY(falcon_player_language)
    ENTRY(falcon_player_device_os)
    ENTRY(falcon_player_set_operator)
    ENTRY(falcon_player_unique_id)
    ENTRY(falcon_player_play_sound)
    ENTRY(falcon_player_clear_inventory)
    ENTRY(falcon_event_target)
    ENTRY(falcon_event_result)
    ENTRY(falcon_event_set_result)
    PHP_FE_END
};
