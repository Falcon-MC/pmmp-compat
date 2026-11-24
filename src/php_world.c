// worlds, blocks, items and entities for the PHP side: plain arrays and handles, the objects are built in PHP
#include "php_bridge_internal.h"

#include <zend_API.h>

#include <stdlib.h>
#include <string.h>

static void return_text(zval *return_value, const char *text) {
    RETURN_STRING(text != NULL ? text : "");
}

static void add_vector(zval *array, FalconVec3 vector) {
    add_next_index_double(array, vector.x);
    add_next_index_double(array, vector.y);
    add_next_index_double(array, vector.z);
}

static void return_vector(zval *return_value, FalconVec3 vector) {
    array_init_size(return_value, 3);
    add_vector(return_value, vector);
}

// an item as PHP keeps it: identifier, count, damage and its little-endian NBT
void item_to_array(FalconItem *item, zval *out) {
    array_init_size(out, 4);
    add_assoc_string(out, "id", falcon->itemIdentifier(item) != NULL ? falcon->itemIdentifier(item) : "minecraft:air");
    add_assoc_long(out, "count", (zend_long) falcon->itemCount(item));
    add_assoc_long(out, "damage", (zend_long) falcon->itemDamage(item));
    uint32_t length = 0;
    const uint8_t *nbt = falcon->itemNbt(item, &length);
    add_assoc_stringl(out, "nbt", nbt != NULL ? (const char *) nbt : "", nbt != NULL ? length : 0);
}

// an owned FalconItem the caller destroys, NULL for an unknown identifier
FalconItem *item_from_array(HashTable *data) {
    zval *id = zend_hash_str_find(data, "id", sizeof("id") - 1);
    zval *count = zend_hash_str_find(data, "count", sizeof("count") - 1);
    if (id == NULL || Z_TYPE_P(id) != IS_STRING)
        return NULL;
    const zend_long amount = count != NULL && Z_TYPE_P(count) == IS_LONG ? Z_LVAL_P(count) : 1;
    FalconItem *item = falcon->itemCreate(Z_STRVAL_P(id), (uint32_t) (amount > 0 ? amount : 0));
    if (item == NULL)
        return NULL;
    zval *damage = zend_hash_str_find(data, "damage", sizeof("damage") - 1);
    if (damage != NULL && Z_TYPE_P(damage) == IS_LONG && Z_LVAL_P(damage) != 0)
        falcon->itemSetDamage(item, (int32_t) Z_LVAL_P(damage));
    zval *nbt = zend_hash_str_find(data, "nbt", sizeof("nbt") - 1);
    if (nbt != NULL && Z_TYPE_P(nbt) == IS_STRING && Z_STRLEN_P(nbt) > 0)
        falcon->itemSetNbt(item, (const uint8_t *) Z_STRVAL_P(nbt), (uint32_t) Z_STRLEN_P(nbt));
    return item;
}

// Slot setters take a null handle for empty slots, independently of the runtime ID of the air item.
static bool slot_item_from_array(HashTable *data, FalconItem **out) {
    zval *id = zend_hash_str_find(data, "id", sizeof("id") - 1);
    if (id == NULL || Z_TYPE_P(id) != IS_STRING)
        return false;
    zval *count = zend_hash_str_find(data, "count", sizeof("count") - 1);
    if (zend_string_equals_literal(Z_STR_P(id), "minecraft:air")
        || (count != NULL && Z_TYPE_P(count) == IS_LONG && Z_LVAL_P(count) <= 0)) {
        *out = NULL;
        return true;
    }
    *out = item_from_array(data);
    return *out != NULL;
}

// an owned copy from an inventory: read, then given back
static void return_owned_item(zval *return_value, FalconItem *item) {
    if (item == NULL)
        RETURN_NULL();
    item_to_array(item, return_value);
    falcon->itemDestroy(item);
}

#define ARGUMENTS_1(a) \
    zend_long a; \
    ZEND_PARSE_PARAMETERS_START(1, 1) \
        Z_PARAM_LONG(a) \
    ZEND_PARSE_PARAMETERS_END()

#define POSITION_ARGUMENTS(level, x, y, z) \
    zend_long level, x, y, z; \
    ZEND_PARSE_PARAMETERS_START(4, 4) \
        Z_PARAM_LONG(level) \
        Z_PARAM_LONG(x) \
        Z_PARAM_LONG(y) \
        Z_PARAM_LONG(z) \
    ZEND_PARSE_PARAMETERS_END()

#define BLOCK_POS(x, y, z) ((FalconBlockPos){(int32_t) (x), (int32_t) (y), (int32_t) (z)})

// --- worlds ---

// [world name => [dimension => level handle]]
PHP_FUNCTION(falcon_worlds) {
    ZEND_PARSE_PARAMETERS_NONE();
    array_init(return_value);
    const uint32_t count = falcon->serverWorldCount();
    static const FalconDimension dimensions[] = {FALCON_DIMENSION_OVERWORLD, FALCON_DIMENSION_NETHER,
                                                 FALCON_DIMENSION_THE_END};
    for (uint32_t index = 0; index < count; ++index) {
        const char *name = falcon->serverWorldName(index);
        if (name == NULL)
            continue;
        zval levels;
        array_init(&levels);
        for (size_t dimension = 0; dimension < sizeof(dimensions) / sizeof(dimensions[0]); ++dimension) {
            FalconLevel *level = falcon->worldLevel(name, dimensions[dimension]);
            if (level != NULL)
                add_index_long(&levels, (zend_long) dimensions[dimension], TO_LONG(level));
        }
        add_assoc_zval(return_value, name, &levels);
    }
}

PHP_FUNCTION(falcon_server_version) {
    ZEND_PARSE_PARAMETERS_NONE();
    return_text(return_value, falcon->serverVersion());
}

PHP_FUNCTION(falcon_default_world) {
    ZEND_PARSE_PARAMETERS_NONE();
    return_text(return_value, falcon->serverDefaultWorldName());
}

PHP_FUNCTION(falcon_world_load) {
    zend_string *name, *seed;
    bool create;
    ZEND_PARSE_PARAMETERS_START(3, 3)
        Z_PARAM_PATH_STR(name)
        Z_PARAM_BOOL(create)
        Z_PARAM_PATH_STR(seed)
    ZEND_PARSE_PARAMETERS_END();
    RETURN_BOOL(FALCON_HAS(serverLoadWorld) && falcon->serverLoadWorld(ZSTR_VAL(name), create, ZSTR_VAL(seed)) != 0);
}

PHP_FUNCTION(falcon_world_unload) {
    zend_string *name;
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_PATH_STR(name)
    ZEND_PARSE_PARAMETERS_END();
    RETURN_BOOL(FALCON_HAS(serverUnloadWorld) && falcon->serverUnloadWorld(ZSTR_VAL(name)) != 0);
}

PHP_FUNCTION(falcon_level_dimension) {
    ARGUMENTS_1(level);
    RETURN_LONG((zend_long) falcon->levelDimension(HANDLE(FalconLevel, level)));
}

// [name, states as JSON]
PHP_FUNCTION(falcon_level_get_block) {
    POSITION_ARGUMENTS(level, x, y, z);
    FalconLevel *target = HANDLE(FalconLevel, level);
    const char *name = falcon->levelGetBlock(target, BLOCK_POS(x, y, z));
    array_init_size(return_value, 2);
    add_next_index_string(return_value, name != NULL ? name : "minecraft:air");
    const char *states = falcon->levelGetBlockStates(target, BLOCK_POS(x, y, z));
    add_next_index_string(return_value, states != NULL ? states : "{}");
}

PHP_FUNCTION(falcon_level_set_block) {
    zend_long level, x, y, z;
    zend_string *name;
    zend_string *states;
    ZEND_PARSE_PARAMETERS_START(6, 6)
        Z_PARAM_LONG(level)
        Z_PARAM_LONG(x)
        Z_PARAM_LONG(y)
        Z_PARAM_LONG(z)
        Z_PARAM_STR(name)
        Z_PARAM_STR(states)
    ZEND_PARSE_PARAMETERS_END();
    RETURN_BOOL(falcon->levelSetBlock(HANDLE(FalconLevel, level), BLOCK_POS(x, y, z), ZSTR_VAL(name),
                                      ZSTR_VAL(states)) != 0);
}

PHP_FUNCTION(falcon_level_get_block_layer) {
    zend_long level, x, y, z, layer;
    ZEND_PARSE_PARAMETERS_START(5, 5)
        Z_PARAM_LONG(level)
        Z_PARAM_LONG(x)
        Z_PARAM_LONG(y)
        Z_PARAM_LONG(z)
        Z_PARAM_LONG(layer)
    ZEND_PARSE_PARAMETERS_END();
    if (layer < 0 || layer > 1 || !FALCON_HAS(levelGetBlockLayer))
        RETURN_NULL();

    const char *states = NULL;
    const char *name = falcon->levelGetBlockLayer(HANDLE(FalconLevel, level), BLOCK_POS(x, y, z),
                                                  (uint32_t) layer, &states);
    if (name == NULL)
        RETURN_NULL();
    array_init_size(return_value, 2);
    add_next_index_string(return_value, name);
    add_next_index_string(return_value, states != NULL ? states : "{}");
}

PHP_FUNCTION(falcon_level_set_block_layer) {
    zend_long level, x, y, z, layer;
    zend_string *name, *states;
    ZEND_PARSE_PARAMETERS_START(7, 7)
        Z_PARAM_LONG(level)
        Z_PARAM_LONG(x)
        Z_PARAM_LONG(y)
        Z_PARAM_LONG(z)
        Z_PARAM_LONG(layer)
        Z_PARAM_STR(name)
        Z_PARAM_STR(states)
    ZEND_PARSE_PARAMETERS_END();
    RETURN_BOOL(layer >= 0 && layer <= 1 && FALCON_HAS(levelSetBlockLayer)
        && falcon->levelSetBlockLayer(HANDLE(FalconLevel, level), BLOCK_POS(x, y, z), (uint32_t) layer,
                                      ZSTR_VAL(name), ZSTR_VAL(states)) != 0);
}

PHP_FUNCTION(falcon_level_break_block) {
    zend_long level, x, y, z;
    bool drop;
    ZEND_PARSE_PARAMETERS_START(5, 5)
        Z_PARAM_LONG(level)
        Z_PARAM_LONG(x)
        Z_PARAM_LONG(y)
        Z_PARAM_LONG(z)
        Z_PARAM_BOOL(drop)
    ZEND_PARSE_PARAMETERS_END();
    RETURN_BOOL(falcon->levelBreakBlock(HANDLE(FalconLevel, level), BLOCK_POS(x, y, z), drop ? 1 : 0) != 0);
}

PHP_FUNCTION(falcon_level_time) {
    ARGUMENTS_1(level);
    RETURN_LONG((zend_long) falcon->levelTime(HANDLE(FalconLevel, level)));
}

PHP_FUNCTION(falcon_level_set_time) {
    zend_long level, time;
    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_LONG(level)
        Z_PARAM_LONG(time)
    ZEND_PARSE_PARAMETERS_END();
    falcon->levelSetTime(HANDLE(FalconLevel, level), (int64_t) time);
}

PHP_FUNCTION(falcon_level_spawn) {
    ARGUMENTS_1(level);
    return_vector(return_value, falcon->levelSpawnPosition(HANDLE(FalconLevel, level)));
}

PHP_FUNCTION(falcon_level_set_spawn) {
    zend_long level, x, y, z;
    ZEND_PARSE_PARAMETERS_START(4, 4)
        Z_PARAM_LONG(level)
        Z_PARAM_LONG(x)
        Z_PARAM_LONG(y)
        Z_PARAM_LONG(z)
    ZEND_PARSE_PARAMETERS_END();
#ifdef FALCON_API_WORLD_SPAWN_POSITION
    RETURN_BOOL(x >= INT32_MIN && x <= INT32_MAX && y >= INT32_MIN && y <= INT32_MAX
        && z >= INT32_MIN && z <= INT32_MAX && FALCON_HAS(levelSetSpawnPosition)
        && falcon->levelSetSpawnPosition(HANDLE(FalconLevel, level), BLOCK_POS(x, y, z)) != 0);
#else
    RETURN_FALSE;
#endif
}

PHP_FUNCTION(falcon_level_entities) {
    ARGUMENTS_1(level);
    FalconLevel *target = HANDLE(FalconLevel, level);
    const uint32_t count = falcon->levelEntityCount(target);
    array_init_size(return_value, count);
    for (uint32_t index = 0; index < count; ++index) {
        FalconEntity *entity = falcon->levelEntity(target, index);
        if (entity != NULL)
            add_next_index_long(return_value, TO_LONG(entity));
    }
}

PHP_FUNCTION(falcon_level_load_chunk) {
    zend_long level, chunkX, chunkZ;
    ZEND_PARSE_PARAMETERS_START(3, 3)
        Z_PARAM_LONG(level)
        Z_PARAM_LONG(chunkX)
        Z_PARAM_LONG(chunkZ)
    ZEND_PARSE_PARAMETERS_END();
    if (chunkX < INT32_MIN || chunkX > INT32_MAX || chunkZ < INT32_MIN || chunkZ > INT32_MAX)
        RETURN_FALSE;
    RETURN_BOOL(FALCON_HAS(levelLoadChunk) &&
                falcon->levelLoadChunk(HANDLE(FalconLevel, level), (int32_t) chunkX, (int32_t) chunkZ) != 0);
}

PHP_FUNCTION(falcon_level_is_chunk_loaded) {
    zend_long level, chunkX, chunkZ;
    ZEND_PARSE_PARAMETERS_START(3, 3)
        Z_PARAM_LONG(level)
        Z_PARAM_LONG(chunkX)
        Z_PARAM_LONG(chunkZ)
    ZEND_PARSE_PARAMETERS_END();
    RETURN_BOOL(falcon->levelIsChunkLoaded(HANDLE(FalconLevel, level), (int32_t) chunkX, (int32_t) chunkZ) != 0);
}

PHP_FUNCTION(falcon_level_highest_block) {
    zend_long level, x, z;
    ZEND_PARSE_PARAMETERS_START(3, 3)
        Z_PARAM_LONG(level)
        Z_PARAM_LONG(x)
        Z_PARAM_LONG(z)
    ZEND_PARSE_PARAMETERS_END();
    RETURN_LONG((zend_long) falcon->levelHighestBlockY(HANDLE(FalconLevel, level), (int32_t) x, (int32_t) z));
}

PHP_FUNCTION(falcon_level_drop_item) {
    zend_long level;
    double x, y, z;
    HashTable *item;
    ZEND_PARSE_PARAMETERS_START(5, 5)
        Z_PARAM_LONG(level)
        Z_PARAM_DOUBLE(x)
        Z_PARAM_DOUBLE(y)
        Z_PARAM_DOUBLE(z)
        Z_PARAM_ARRAY_HT(item)
    ZEND_PARSE_PARAMETERS_END();
    FalconItem *created = item_from_array(item);
    if (created == NULL)
        RETURN_FALSE;
    falcon->levelDropItem(HANDLE(FalconLevel, level), (FalconVec3){x, y, z}, created);
    falcon->itemDestroy(created);
    RETURN_TRUE;
}

PHP_FUNCTION(falcon_level_spawn_item) {
    zend_long level, delay;
    double x, y, z;
    FalconVec3 motion = {0};
    HashTable *item;
    ZEND_PARSE_PARAMETERS_START(6, 9)
        Z_PARAM_LONG(level)
        Z_PARAM_DOUBLE(x)
        Z_PARAM_DOUBLE(y)
        Z_PARAM_DOUBLE(z)
        Z_PARAM_ARRAY_HT(item)
        Z_PARAM_LONG(delay)
        Z_PARAM_OPTIONAL
        Z_PARAM_DOUBLE(motion.x)
        Z_PARAM_DOUBLE(motion.y)
        Z_PARAM_DOUBLE(motion.z)
    ZEND_PARSE_PARAMETERS_END();
    if (delay < INT32_MIN || delay > INT32_MAX) {
        zend_argument_value_error(6, "must fit in a signed 32-bit integer");
        RETURN_THROWS();
    }
    FalconItem *created = item_from_array(item);
    if (created == NULL)
        RETURN_LONG(0);

    FalconLevel *target = HANDLE(FalconLevel, level);
    FalconVec3 position = {x, y, z};
    FalconEntity *entity = NULL;
    if (FALCON_HAS(levelSpawnItem)) {
        entity = falcon->levelSpawnItem(target, position, created, ZEND_NUM_ARGS() > 6 ? &motion : NULL,
                                        (int32_t) delay);
    } else {
        falcon->levelDropItem(target, position, created);
    }
    falcon->itemDestroy(created);
    RETURN_LONG(TO_LONG(entity));
}

PHP_FUNCTION(falcon_item_entity_item) {
    ARGUMENTS_1(entity);
    if (!FALCON_HAS(itemEntityItem))
        RETURN_NULL();
    return_owned_item(return_value, falcon->itemEntityItem(HANDLE(FalconEntity, entity)));
}

PHP_FUNCTION(falcon_item_entity_pickup_delay) {
    ARGUMENTS_1(entity);
    if (!FALCON_HAS(itemEntityPickupDelay))
        RETURN_NULL();
    RETURN_LONG(falcon->itemEntityPickupDelay(HANDLE(FalconEntity, entity)));
}

PHP_FUNCTION(falcon_item_entity_set_pickup_delay) {
    zend_long entity, delay;
    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_LONG(entity)
        Z_PARAM_LONG(delay)
    ZEND_PARSE_PARAMETERS_END();
    if (delay < INT32_MIN || delay > INT32_MAX) {
        zend_argument_value_error(2, "must fit in a signed 32-bit integer");
        RETURN_THROWS();
    }
    if (FALCON_HAS(itemEntitySetPickupDelay))
        falcon->itemEntitySetPickupDelay(HANDLE(FalconEntity, entity), (int32_t) delay);
}

// --- items ---

PHP_FUNCTION(falcon_item_exists) {
    zend_string *identifier;
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_STR(identifier)
    ZEND_PARSE_PARAMETERS_END();
    FalconItem *item = falcon->itemCreate(ZSTR_VAL(identifier), 1);
    if (item == NULL)
        RETURN_FALSE;
    falcon->itemDestroy(item);
    RETURN_TRUE;
}

// the item as Falcon would store it: its NBT once Falcon has normalised it
PHP_FUNCTION(falcon_item_normalise) {
    HashTable *item;
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_ARRAY_HT(item)
    ZEND_PARSE_PARAMETERS_END();
    return_owned_item(return_value, item_from_array(item));
}

PHP_FUNCTION(falcon_player_inventory_size) {
    ARGUMENTS_1(player);
    RETURN_LONG((zend_long) falcon->playerInventorySize(HANDLE(FalconPlayer, player)));
}

PHP_FUNCTION(falcon_player_inventory_item) {
    zend_long player, slot;
    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_LONG(player)
        Z_PARAM_LONG(slot)
    ZEND_PARSE_PARAMETERS_END();
    return_owned_item(return_value, falcon->playerInventoryItem(HANDLE(FalconPlayer, player), (uint32_t) slot));
}

PHP_FUNCTION(falcon_player_set_inventory_item) {
    zend_long player, slot;
    HashTable *item;
    ZEND_PARSE_PARAMETERS_START(3, 3)
        Z_PARAM_LONG(player)
        Z_PARAM_LONG(slot)
        Z_PARAM_ARRAY_HT(item)
    ZEND_PARSE_PARAMETERS_END();
    FalconItem *created;
    if (!slot_item_from_array(item, &created))
        RETURN_FALSE;
    falcon->playerSetInventoryItem(HANDLE(FalconPlayer, player), (uint32_t) slot, created);
    falcon->itemDestroy(created);
    RETURN_TRUE;
}

PHP_FUNCTION(falcon_player_give_item) {
    zend_long player;
    HashTable *item;
    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_LONG(player)
        Z_PARAM_ARRAY_HT(item)
    ZEND_PARSE_PARAMETERS_END();
    FalconItem *created = item_from_array(item);
    if (created == NULL)
        RETURN_FALSE;
    const int given = falcon->playerGiveItem(HANDLE(FalconPlayer, player), created);
    falcon->itemDestroy(created);
    RETURN_BOOL(given != 0);
}

PHP_FUNCTION(falcon_player_selected_slot) {
    ARGUMENTS_1(player);
    RETURN_LONG((zend_long) falcon->playerSelectedSlot(HANDLE(FalconPlayer, player)));
}

PHP_FUNCTION(falcon_player_set_selected_slot) {
    zend_long player, slot;
    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_LONG(player)
        Z_PARAM_LONG(slot)
    ZEND_PARSE_PARAMETERS_END();
    falcon->playerSetSelectedSlot(HANDLE(FalconPlayer, player), (uint32_t) slot);
}

// --- entities ---

PHP_FUNCTION(falcon_player_entity) {
    ARGUMENTS_1(player);
    RETURN_LONG(TO_LONG(falcon->playerEntity(HANDLE(FalconPlayer, player))));
}

// the entity again from its runtime id, 0 once it is gone or on a Falcon without the lookup
PHP_FUNCTION(falcon_server_entity) {
    ARGUMENTS_1(runtimeId);
    if (!FALCON_HAS(serverEntity))
        RETURN_LONG(0);
    RETURN_LONG(TO_LONG(falcon->serverEntity((uint64_t) runtimeId)));
}

PHP_FUNCTION(falcon_entity_runtime_id) {
    ARGUMENTS_1(entity);
    RETURN_LONG((zend_long) falcon->entityRuntimeId(HANDLE(FalconEntity, entity)));
}

// what PHP reads of an entity in one call
PHP_FUNCTION(falcon_entity_info) {
    ARGUMENTS_1(entity);
    FalconEntity *target = HANDLE(FalconEntity, entity);
    array_init_size(return_value, 10);
    const char *type = falcon->entityType(target);
    add_assoc_string(return_value, "type", type != NULL ? type : "");
    add_assoc_long(return_value, "level", TO_LONG(falcon->entityLevel(target)));
    zval position;
    array_init_size(&position, 3);
    add_vector(&position, falcon->entityPosition(target));
    add_assoc_zval(return_value, "position", &position);
    zval rotation;
    array_init_size(&rotation, 3);
    add_vector(&rotation, falcon->entityRotation(target));
    add_assoc_zval(return_value, "rotation", &rotation);
    zval motion;
    array_init_size(&motion, 3);
    add_vector(&motion, falcon->entityMotion(target));
    add_assoc_zval(return_value, "motion", &motion);
    add_assoc_double(return_value, "health", falcon->entityHealth(target));
    add_assoc_double(return_value, "maxHealth", falcon->entityMaxHealth(target));
    add_assoc_bool(return_value, "alive", falcon->entityIsAlive(target) != 0);
    const char *tag = falcon->entityNameTag(target);
    add_assoc_string(return_value, "nameTag", tag != NULL ? tag : "");
    add_assoc_long(return_value, "player", TO_LONG(falcon->entityPlayer(target)));
    add_assoc_bool(return_value, "onFire", falcon->entityIsOnFire(target) != 0);
    add_assoc_bool(return_value, "invisible", FALCON_HAS(entityIsInvisible) && falcon->entityIsInvisible(target) != 0);
}

PHP_FUNCTION(falcon_entity_teleport) {
    zend_long entity, level;
    double x, y, z;
    ZEND_PARSE_PARAMETERS_START(5, 5)
        Z_PARAM_LONG(entity)
        Z_PARAM_LONG(level)
        Z_PARAM_DOUBLE(x)
        Z_PARAM_DOUBLE(y)
        Z_PARAM_DOUBLE(z)
    ZEND_PARSE_PARAMETERS_END();
    falcon->entityTeleport(HANDLE(FalconEntity, entity), HANDLE(FalconLevel, level), (FalconVec3){x, y, z});
}

PHP_FUNCTION(falcon_entity_teleport_with_rotation) {
    zend_long entity, level;
    double x, y, z, yaw, pitch;
    ZEND_PARSE_PARAMETERS_START(7, 7)
        Z_PARAM_LONG(entity)
        Z_PARAM_LONG(level)
        Z_PARAM_DOUBLE(x)
        Z_PARAM_DOUBLE(y)
        Z_PARAM_DOUBLE(z)
        Z_PARAM_DOUBLE(yaw)
        Z_PARAM_DOUBLE(pitch)
    ZEND_PARSE_PARAMETERS_END();
#ifdef FALCON_API_ENTITY_TELEPORT_DETAILS
    if (FALCON_HAS(entityTeleportWithRotation)) {
        const FalconVec3 rotation = {pitch, yaw, yaw};
        RETURN_BOOL(falcon->entityTeleportWithRotation(HANDLE(FalconEntity, entity), HANDLE(FalconLevel, level),
            (FalconVec3){x, y, z}, &rotation) != 0);
    }
#endif
    RETURN_NULL();
}

PHP_FUNCTION(falcon_entity_set_motion) {
    zend_long entity;
    double x, y, z;
    ZEND_PARSE_PARAMETERS_START(4, 4)
        Z_PARAM_LONG(entity)
        Z_PARAM_DOUBLE(x)
        Z_PARAM_DOUBLE(y)
        Z_PARAM_DOUBLE(z)
    ZEND_PARSE_PARAMETERS_END();
    falcon->entitySetMotion(HANDLE(FalconEntity, entity), (FalconVec3){x, y, z});
}

PHP_FUNCTION(falcon_entity_set_health) {
    zend_long entity;
    double health;
    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_LONG(entity)
        Z_PARAM_DOUBLE(health)
    ZEND_PARSE_PARAMETERS_END();
    falcon->entitySetHealth(HANDLE(FalconEntity, entity), (float) health);
}

PHP_FUNCTION(falcon_entity_set_name_tag) {
    zend_long entity;
    zend_string *tag;
    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_LONG(entity)
        Z_PARAM_STR(tag)
    ZEND_PARSE_PARAMETERS_END();
    falcon->entitySetNameTag(HANDLE(FalconEntity, entity), ZSTR_VAL(tag));
}

PHP_FUNCTION(falcon_entity_damage) {
    zend_long entity, attacker;
    double amount;
    zend_string *cause;
    ZEND_PARSE_PARAMETERS_START(4, 4)
        Z_PARAM_LONG(entity)
        Z_PARAM_DOUBLE(amount)
        Z_PARAM_STR(cause)
        Z_PARAM_LONG(attacker)
    ZEND_PARSE_PARAMETERS_END();
    RETURN_BOOL(falcon->entityDamage(HANDLE(FalconEntity, entity), (float) amount, ZSTR_VAL(cause),
                                     HANDLE(FalconEntity, attacker)) != 0);
}

PHP_FUNCTION(falcon_entity_kill) {
    ARGUMENTS_1(entity);
    falcon->entityKill(HANDLE(FalconEntity, entity));
}

PHP_FUNCTION(falcon_entity_remove) {
    ARGUMENTS_1(entity);
    falcon->entityRemove(HANDLE(FalconEntity, entity));
}

// --- what an event carries of them ---

PHP_FUNCTION(falcon_event_entity) {
    ARGUMENTS_1(event);
    RETURN_LONG(TO_LONG(falcon->eventEntity(HANDLE(FalconEvent, event))));
}

PHP_FUNCTION(falcon_event_attacker) {
    ARGUMENTS_1(event);
    RETURN_LONG(TO_LONG(falcon->eventAttacker(HANDLE(FalconEvent, event))));
}

PHP_FUNCTION(falcon_event_level) {
    ARGUMENTS_1(event);
    RETURN_LONG(TO_LONG(falcon->eventLevel(HANDLE(FalconEvent, event))));
}

PHP_FUNCTION(falcon_event_set_teleport_level) {
    zend_long event, level;
    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_LONG(event)
        Z_PARAM_LONG(level)
    ZEND_PARSE_PARAMETERS_END();
#ifdef FALCON_API_ENTITY_TELEPORT_DETAILS
    if (FALCON_HAS(eventSetTeleportLevel))
        RETURN_BOOL(falcon->eventSetTeleportLevel(HANDLE(FalconEvent, event), HANDLE(FalconLevel, level)) != 0);
#endif
    RETURN_FALSE;
}

// [x, y, z, face, block name]
PHP_FUNCTION(falcon_event_block) {
    ARGUMENTS_1(event);
    FalconEvent *target = HANDLE(FalconEvent, event);
    const FalconBlockPos position = falcon->eventBlockPosition(target);
    array_init_size(return_value, 5);
    add_next_index_long(return_value, position.x);
    add_next_index_long(return_value, position.y);
    add_next_index_long(return_value, position.z);
    add_next_index_long(return_value, (zend_long) falcon->eventBlockFace(target));
    const char *name = falcon->eventBlockName(target);
    add_next_index_string(return_value, name != NULL ? name : "");
}

PHP_FUNCTION(falcon_event_amount) {
    ARGUMENTS_1(event);
    RETURN_DOUBLE(falcon->eventAmount(HANDLE(FalconEvent, event)));
}

PHP_FUNCTION(falcon_event_set_amount) {
    zend_long event;
    double amount;
    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_LONG(event)
        Z_PARAM_DOUBLE(amount)
    ZEND_PARSE_PARAMETERS_END();
    falcon->eventSetAmount(HANDLE(FalconEvent, event), amount);
}

PHP_FUNCTION(falcon_event_from) {
    ARGUMENTS_1(event);
    return_vector(return_value, falcon->eventFrom(HANDLE(FalconEvent, event)));
}

PHP_FUNCTION(falcon_event_to) {
    ARGUMENTS_1(event);
    return_vector(return_value, falcon->eventTo(HANDLE(FalconEvent, event)));
}

PHP_FUNCTION(falcon_event_set_to) {
    zend_long event;
    double x, y, z;
    ZEND_PARSE_PARAMETERS_START(4, 4)
        Z_PARAM_LONG(event)
        Z_PARAM_DOUBLE(x)
        Z_PARAM_DOUBLE(y)
        Z_PARAM_DOUBLE(z)
    ZEND_PARSE_PARAMETERS_END();
    falcon->eventSetTo(HANDLE(FalconEvent, event), (FalconVec3){x, y, z});
}

PHP_FUNCTION(falcon_event_position) {
    ARGUMENTS_1(event);
    return_vector(return_value, falcon->eventPosition(HANDLE(FalconEvent, event)));
}

// a borrowed item: copied into PHP's array, the event keeps its own
PHP_FUNCTION(falcon_event_item) {
    ARGUMENTS_1(event);
    FalconItem *item = falcon->eventItem(HANDLE(FalconEvent, event));
    if (item == NULL)
        RETURN_NULL();
    item_to_array(item, return_value);
}

// --- packets, forms and the player's identity ---

PHP_FUNCTION(falcon_player_send_packet) {
    zend_long player, packetId;
    zend_string *payload;
    ZEND_PARSE_PARAMETERS_START(3, 3)
        Z_PARAM_LONG(player)
        Z_PARAM_LONG(packetId)
        Z_PARAM_STR(payload)
    ZEND_PARSE_PARAMETERS_END();
    RETURN_BOOL(falcon->playerSendPacket(HANDLE(FalconPlayer, player), (uint32_t) packetId,
                                         (const uint8_t *) ZSTR_VAL(payload), (uint32_t) ZSTR_LEN(payload)) != 0);
}

// the player's commands built anew and sent again, DATA_PACKET_SEND firing for them; false on a Falcon without it
PHP_FUNCTION(falcon_player_send_commands) {
    ARGUMENTS_1(player);
    RETURN_BOOL(FALCON_HAS(playerSendCommands) && falcon->playerSendCommands(HANDLE(FalconPlayer, player)) != 0);
}

// [packet id, payload without its header]
PHP_FUNCTION(falcon_event_packet) {
    ARGUMENTS_1(event);
    FalconEvent *target = HANDLE(FalconEvent, event);
    uint32_t length = 0;
    const uint8_t *data = falcon->eventPacketData(target, &length);
    array_init_size(return_value, 2);
    add_next_index_long(return_value, (zend_long) falcon->eventPacketId(target));
    add_next_index_stringl(return_value, data != NULL ? (const char *) data : "", data != NULL ? length : 0);
}

PHP_FUNCTION(falcon_event_set_packet) {
    zend_long event;
    zend_string *payload;
    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_LONG(event)
        Z_PARAM_STR(payload)
    ZEND_PARSE_PARAMETERS_END();
    falcon->eventSetPacketData(HANDLE(FalconEvent, event), (const uint8_t *) ZSTR_VAL(payload),
                               (uint32_t) ZSTR_LEN(payload));
}

PHP_FUNCTION(falcon_player_send_form) {
    zend_long player;
    zend_string *json;
    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_LONG(player)
        Z_PARAM_STR(json)
    ZEND_PARSE_PARAMETERS_END();
    RETURN_LONG((zend_long) falcon->playerSendForm(HANDLE(FalconPlayer, player), ZSTR_VAL(json)));
}

// [form id, response JSON]
PHP_FUNCTION(falcon_event_form) {
    ARGUMENTS_1(event);
    FalconEvent *target = HANDLE(FalconEvent, event);
    array_init_size(return_value, 2);
    add_next_index_long(return_value, (zend_long) falcon->eventFormId(target));
    const char *response = falcon->eventFormResponse(target);
    add_next_index_string(return_value, response != NULL ? response : "null");
}

PHP_FUNCTION(falcon_player_uuid) {
    ARGUMENTS_1(player);
    return_text(return_value, falcon->playerUuid(HANDLE(FalconPlayer, player)));
}

PHP_FUNCTION(falcon_player_address) {
    ARGUMENTS_1(player);
    return_text(return_value, falcon->playerAddress(HANDLE(FalconPlayer, player)));
}

PHP_FUNCTION(falcon_player_protocol) {
    ARGUMENTS_1(player);
    RETURN_LONG((zend_long) falcon->playerProtocolVersion(HANDLE(FalconPlayer, player)));
}

// --- inventories: armour, off hand, Ender chest, block containers and plugin windows ---

#define SLOT_ARGUMENTS(owner, slot) \
    zend_long owner, slot; \
    ZEND_PARSE_PARAMETERS_START(2, 2) \
        Z_PARAM_LONG(owner) \
        Z_PARAM_LONG(slot) \
    ZEND_PARSE_PARAMETERS_END()

#define SLOT_ITEM_ARGUMENTS(owner, slot, item) \
    zend_long owner, slot; \
    HashTable *item; \
    ZEND_PARSE_PARAMETERS_START(3, 3) \
        Z_PARAM_LONG(owner) \
        Z_PARAM_LONG(slot) \
        Z_PARAM_ARRAY_HT(item) \
    ZEND_PARSE_PARAMETERS_END()

PHP_FUNCTION(falcon_player_armor_item) {
    SLOT_ARGUMENTS(player, slot);
    return_owned_item(return_value, falcon->playerArmorItem(HANDLE(FalconPlayer, player), (FalconArmorSlot) slot));
}

PHP_FUNCTION(falcon_player_set_armor_item) {
    SLOT_ITEM_ARGUMENTS(player, slot, item);
    FalconItem *created;
    if (!slot_item_from_array(item, &created))
        RETURN_FALSE;
    falcon->playerSetArmorItem(HANDLE(FalconPlayer, player), (FalconArmorSlot) slot, created);
    falcon->itemDestroy(created);
    RETURN_TRUE;
}

PHP_FUNCTION(falcon_player_offhand_item) {
    ARGUMENTS_1(player);
    return_owned_item(return_value, falcon->playerOffhandItem(HANDLE(FalconPlayer, player)));
}

PHP_FUNCTION(falcon_player_set_offhand_item) {
    zend_long player;
    HashTable *item;
    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_LONG(player)
        Z_PARAM_ARRAY_HT(item)
    ZEND_PARSE_PARAMETERS_END();
    FalconItem *created;
    if (!slot_item_from_array(item, &created))
        RETURN_FALSE;
    falcon->playerSetOffhandItem(HANDLE(FalconPlayer, player), created);
    falcon->itemDestroy(created);
    RETURN_TRUE;
}

PHP_FUNCTION(falcon_player_ender_size) {
    ARGUMENTS_1(player);
    RETURN_LONG((zend_long) falcon->playerEnderChestSize(HANDLE(FalconPlayer, player)));
}

PHP_FUNCTION(falcon_player_ender_item) {
    SLOT_ARGUMENTS(player, slot);
    return_owned_item(return_value, falcon->playerEnderChestItem(HANDLE(FalconPlayer, player), (uint32_t) slot));
}

PHP_FUNCTION(falcon_player_set_ender_item) {
    SLOT_ITEM_ARGUMENTS(player, slot, item);
    FalconItem *created;
    if (!slot_item_from_array(item, &created))
        RETURN_FALSE;
    const int set = falcon->playerSetEnderChestItem(HANDLE(FalconPlayer, player), (uint32_t) slot, created);
    falcon->itemDestroy(created);
    RETURN_BOOL(set != 0);
}

PHP_FUNCTION(falcon_level_container_size) {
    POSITION_ARGUMENTS(level, x, y, z);
    RETURN_LONG((zend_long) falcon->levelContainerSize(HANDLE(FalconLevel, level), BLOCK_POS(x, y, z)));
}

PHP_FUNCTION(falcon_level_container_item) {
    zend_long level, x, y, z, slot;
    ZEND_PARSE_PARAMETERS_START(5, 5)
        Z_PARAM_LONG(level)
        Z_PARAM_LONG(x)
        Z_PARAM_LONG(y)
        Z_PARAM_LONG(z)
        Z_PARAM_LONG(slot)
    ZEND_PARSE_PARAMETERS_END();
    return_owned_item(return_value, falcon->levelContainerItem(HANDLE(FalconLevel, level), BLOCK_POS(x, y, z),
                                                               (uint32_t) slot));
}

PHP_FUNCTION(falcon_level_set_container_item) {
    zend_long level, x, y, z, slot;
    HashTable *item;
    ZEND_PARSE_PARAMETERS_START(6, 6)
        Z_PARAM_LONG(level)
        Z_PARAM_LONG(x)
        Z_PARAM_LONG(y)
        Z_PARAM_LONG(z)
        Z_PARAM_LONG(slot)
        Z_PARAM_ARRAY_HT(item)
    ZEND_PARSE_PARAMETERS_END();
    FalconItem *created;
    if (!slot_item_from_array(item, &created))
        RETURN_FALSE;
    const int set = falcon->levelSetContainerItem(HANDLE(FalconLevel, level), BLOCK_POS(x, y, z), (uint32_t) slot, created);
    falcon->itemDestroy(created);
    RETURN_BOOL(set != 0);
}

// [action, source container, source slot, destination container, destination slot, count]
PHP_FUNCTION(falcon_event_transaction) {
    ARGUMENTS_1(event);
    FalconEvent *target = HANDLE(FalconEvent, event);
    array_init_size(return_value, 6);
    const char *action = falcon->eventCause(target);
    add_next_index_string(return_value, action != NULL ? action : "");
    const char *source = falcon->eventSourceContainer(target);
    add_next_index_string(return_value, source != NULL ? source : "");
    add_next_index_long(return_value, (zend_long) falcon->eventSourceSlot(target));
    const char *destination = falcon->eventDestinationContainer(target);
    add_next_index_string(return_value, destination != NULL ? destination : "");
    add_next_index_long(return_value, (zend_long) falcon->eventDestinationSlot(target));
    add_next_index_long(return_value, (zend_long) falcon->eventAmount(target));
}

// a plugin window closed: the Bridge tells the PHP inventory, whoever closed it
static void window_closed(FalconPlayer *player, uint32_t window, void *userData) {
    (void) userData;
    zval call[2];
    ZVAL_LONG(&call[0], TO_LONG(player));
    ZVAL_LONG(&call[1], (zend_long) window);
    zval result;
    call_bridge("pmmpcompat\\Bridge::windowClosed", 2, call, &result);
    zval_ptr_dtor(&result);
}

// the window id, 0 when another is open or on a Falcon without plugin windows
PHP_FUNCTION(falcon_player_open_window) {
    zend_long player, type, x, y, z, actor, size;
    ZEND_PARSE_PARAMETERS_START(7, 7)
        Z_PARAM_LONG(player)
        Z_PARAM_LONG(type)
        Z_PARAM_LONG(x)
        Z_PARAM_LONG(y)
        Z_PARAM_LONG(z)
        Z_PARAM_LONG(actor)
        Z_PARAM_LONG(size)
    ZEND_PARSE_PARAMETERS_END();
    if (!FALCON_HAS(playerOpenWindow))
        RETURN_LONG(0);
    const FalconWindowDescriptor descriptor = {(uint32_t) type, BLOCK_POS(x, y, z), (int64_t) actor, (uint32_t) size,
                                               window_closed, NULL};
    RETURN_LONG((zend_long) falcon->playerOpenWindow(falcon_plugin, HANDLE(FalconPlayer, player), &descriptor));
}

PHP_FUNCTION(falcon_player_window_item) {
    zend_long player, window, slot;
    ZEND_PARSE_PARAMETERS_START(3, 3)
        Z_PARAM_LONG(player)
        Z_PARAM_LONG(window)
        Z_PARAM_LONG(slot)
    ZEND_PARSE_PARAMETERS_END();
    if (!FALCON_HAS(playerWindowItem))
        RETURN_NULL();
    return_owned_item(return_value, falcon->playerWindowItem(HANDLE(FalconPlayer, player), (uint32_t) window,
                                                             (uint32_t) slot));
}

PHP_FUNCTION(falcon_player_set_window_item) {
    zend_long player, window, slot;
    HashTable *item;
    ZEND_PARSE_PARAMETERS_START(4, 4)
        Z_PARAM_LONG(player)
        Z_PARAM_LONG(window)
        Z_PARAM_LONG(slot)
        Z_PARAM_ARRAY_HT(item)
    ZEND_PARSE_PARAMETERS_END();
    if (!FALCON_HAS(playerSetWindowItem))
        RETURN_FALSE;
    FalconItem *created;
    if (!slot_item_from_array(item, &created))
        RETURN_FALSE;
    const int set = falcon->playerSetWindowItem(HANDLE(FalconPlayer, player), (uint32_t) window, (uint32_t) slot, created);
    falcon->itemDestroy(created);
    RETURN_BOOL(set != 0);
}

PHP_FUNCTION(falcon_player_close_window) {
    zend_long player, window;
    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_LONG(player)
        Z_PARAM_LONG(window)
    ZEND_PARSE_PARAMETERS_END();
    if (FALCON_HAS(playerCloseWindow))
        falcon->playerCloseWindow(HANDLE(FalconPlayer, player), (uint32_t) window);
}

PHP_FUNCTION(falcon_block_network_id) {
    zend_string *name;
    zend_string *states;
    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_STR(name)
        Z_PARAM_STR(states)
    ZEND_PARSE_PARAMETERS_END();
    if (!FALCON_HAS(serverBlockNetworkId))
        RETURN_LONG(0);
    RETURN_LONG((zend_long) falcon->serverBlockNetworkId(ZSTR_VAL(name), ZSTR_VAL(states)));
}

ZEND_BEGIN_ARG_INFO_EX(arginfo_any, 0, 0, 0)
    ZEND_ARG_VARIADIC_INFO(0, arguments)
ZEND_END_ARG_INFO()

#define ENTRY(name) ZEND_RAW_FENTRY(#name, ZEND_FN(name), arginfo_any, 0)

const zend_function_entry falcon_world_functions[] = {
    ENTRY(falcon_worlds)
    ENTRY(falcon_world_load)
    ENTRY(falcon_world_unload)
    ENTRY(falcon_default_world)
    ENTRY(falcon_server_version)
    ENTRY(falcon_level_dimension)
    ENTRY(falcon_level_get_block)
    ENTRY(falcon_level_set_block)
    ENTRY(falcon_level_get_block_layer)
    ENTRY(falcon_level_set_block_layer)
    ENTRY(falcon_level_break_block)
    ENTRY(falcon_level_time)
    ENTRY(falcon_level_set_time)
    ENTRY(falcon_level_spawn)
    ENTRY(falcon_level_set_spawn)
    ENTRY(falcon_level_entities)
    ENTRY(falcon_level_is_chunk_loaded)
    ENTRY(falcon_level_load_chunk)
    ENTRY(falcon_level_highest_block)
    ENTRY(falcon_level_drop_item)
    ENTRY(falcon_level_spawn_item)
    ENTRY(falcon_item_entity_item)
    ENTRY(falcon_item_entity_pickup_delay)
    ENTRY(falcon_item_entity_set_pickup_delay)
    ENTRY(falcon_item_exists)
    ENTRY(falcon_item_normalise)
    ENTRY(falcon_player_inventory_size)
    ENTRY(falcon_player_inventory_item)
    ENTRY(falcon_player_set_inventory_item)
    ENTRY(falcon_player_give_item)
    ENTRY(falcon_player_selected_slot)
    ENTRY(falcon_player_set_selected_slot)
    ENTRY(falcon_player_entity)
    ENTRY(falcon_server_entity)
    ENTRY(falcon_entity_runtime_id)
    ENTRY(falcon_entity_info)
    ENTRY(falcon_entity_teleport)
    ENTRY(falcon_entity_teleport_with_rotation)
    ENTRY(falcon_entity_set_motion)
    ENTRY(falcon_entity_set_health)
    ENTRY(falcon_entity_set_name_tag)
    ENTRY(falcon_entity_damage)
    ENTRY(falcon_entity_kill)
    ENTRY(falcon_entity_remove)
    ENTRY(falcon_event_entity)
    ENTRY(falcon_event_attacker)
    ENTRY(falcon_event_level)
    ENTRY(falcon_event_set_teleport_level)
    ENTRY(falcon_event_block)
    ENTRY(falcon_event_amount)
    ENTRY(falcon_event_set_amount)
    ENTRY(falcon_event_from)
    ENTRY(falcon_event_to)
    ENTRY(falcon_event_set_to)
    ENTRY(falcon_event_position)
    ENTRY(falcon_event_item)
    ENTRY(falcon_player_send_packet)
    ENTRY(falcon_player_send_commands)
    ENTRY(falcon_event_packet)
    ENTRY(falcon_event_set_packet)
    ENTRY(falcon_player_send_form)
    ENTRY(falcon_event_form)
    ENTRY(falcon_player_uuid)
    ENTRY(falcon_player_address)
    ENTRY(falcon_player_protocol)
    ENTRY(falcon_player_armor_item)
    ENTRY(falcon_player_set_armor_item)
    ENTRY(falcon_player_offhand_item)
    ENTRY(falcon_player_set_offhand_item)
    ENTRY(falcon_player_ender_size)
    ENTRY(falcon_player_ender_item)
    ENTRY(falcon_player_set_ender_item)
    ENTRY(falcon_level_container_size)
    ENTRY(falcon_level_container_item)
    ENTRY(falcon_level_set_container_item)
    ENTRY(falcon_event_transaction)
    ENTRY(falcon_player_open_window)
    ENTRY(falcon_player_window_item)
    ENTRY(falcon_player_set_window_item)
    ENTRY(falcon_player_close_window)
    ENTRY(falcon_block_network_id)
    PHP_FE_END
};
