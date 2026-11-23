// entities PocketMine-MP plugins define: a Falcon entity of this plugin's own type holds each one's place, and PHP
// draws it for clients with PocketMine-MP's packets
#include "php_bridge.h"
#include "php_bridge_internal.h"

#include <zend_API.h>

#include <stdbool.h>
#include <stdint.h>

// the type standing for every plugin's entities; what clients see of one is up to its PHP class
#define DRAWN_TYPE "pmmpcompat:entity"

static bool type_registered = false;

// a player clicked one: the PHP object's onInteract() answers
static int interact_drawn(FalconEntity *entity, FalconPlayer *player, void *userData) {
    (void) userData;
    zval call[2];
    ZVAL_LONG(&call[0], TO_LONG(entity));
    ZVAL_LONG(&call[1], TO_LONG(player));
    zval result;
    const bool completed = call_bridge("pmmpcompat\\Bridge::entityInteract", 2, call, &result);
    const int handled = completed && Z_TYPE(result) == IS_TRUE ? 1 : 0;
    zval_ptr_dtor(&result);
    return handled;
}

int pmmp_register_entity_type(const FalconServerApi *api, FalconPlugin *plugin) {
    // a Falcon without drawn entities would show the bare type to clients: better none at all
    if (api == NULL || api->size < offsetof(FalconServerApi, itemNetworkId) + sizeof(api->itemNetworkId))
        return 0;
    FalconCustomEntityDescriptor descriptor = {0};
    descriptor.identifier = DRAWN_TYPE;
    descriptor.width = 0.6f;
    descriptor.height = 1.8f;
    descriptor.maxHealth = 20.0f;
    descriptor.summonable = 0;
    descriptor.onInteract = interact_drawn;
    type_registered = api->registerCustomEntity(plugin, &descriptor) != 0;
    return type_registered ? 1 : 0;
}

static bool drawn_supported(void) {
    return type_registered && FALCON_HAS(entitySetPluginDrawn) && FALCON_HAS(entitySetSize);
}

PHP_FUNCTION(falcon_drawn_entities_supported) {
    ZEND_PARSE_PARAMETERS_NONE();
    RETURN_BOOL(drawn_supported());
}

// falcon_drawn_entity_spawn(level, x, y, z, width, height): the Falcon entity a PHP one stands on, 0 when refused
PHP_FUNCTION(falcon_drawn_entity_spawn) {
    zend_long level;
    double x, y, z, width, height;
    ZEND_PARSE_PARAMETERS_START(6, 6)
        Z_PARAM_LONG(level)
        Z_PARAM_DOUBLE(x)
        Z_PARAM_DOUBLE(y)
        Z_PARAM_DOUBLE(z)
        Z_PARAM_DOUBLE(width)
        Z_PARAM_DOUBLE(height)
    ZEND_PARSE_PARAMETERS_END();
    if (!drawn_supported())
        RETURN_LONG(0);
    FalconEntity *entity = falcon->levelSpawnEntity(HANDLE(FalconLevel, level), DRAWN_TYPE, (FalconVec3){x, y, z});
    if (entity == NULL)
        RETURN_LONG(0);
    falcon->entitySetPluginDrawn(entity, 1);
    if (width > 0 && height > 0)
        falcon->entitySetSize(entity, (float) width, (float) height);
    RETURN_LONG(TO_LONG(entity));
}

PHP_FUNCTION(falcon_entity_is_drawn) {
    zend_long entity;
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_LONG(entity)
    ZEND_PARSE_PARAMETERS_END();
    RETURN_BOOL(FALCON_HAS(entityIsPluginDrawn) && falcon->entityIsPluginDrawn(HANDLE(FalconEntity, entity)) != 0);
}

PHP_FUNCTION(falcon_entity_set_size) {
    zend_long entity;
    double width, height;
    ZEND_PARSE_PARAMETERS_START(3, 3)
        Z_PARAM_LONG(entity)
        Z_PARAM_DOUBLE(width)
        Z_PARAM_DOUBLE(height)
    ZEND_PARSE_PARAMETERS_END();
    RETURN_BOOL(FALCON_HAS(entitySetSize)
                && falcon->entitySetSize(HANDLE(FalconEntity, entity), (float) width, (float) height) != 0);
}

PHP_FUNCTION(falcon_entity_type) {
    zend_long entity;
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_LONG(entity)
    ZEND_PARSE_PARAMETERS_END();
    const char *type = falcon->entityType(HANDLE(FalconEntity, entity));
    RETURN_STRING(type != NULL ? type : "");
}

// what this plugin keeps on an entity, saved with it: null when unset
PHP_FUNCTION(falcon_entity_data) {
    zend_long entity;
    zend_string *key;
    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_LONG(entity)
        Z_PARAM_STR(key)
    ZEND_PARSE_PARAMETERS_END();
    const char *value = falcon->entityData(falcon_plugin, HANDLE(FalconEntity, entity), ZSTR_VAL(key));
    if (value == NULL)
        RETURN_NULL();
    RETURN_STRING(value);
}

// null removes it; the value is text, binary data goes in base64
PHP_FUNCTION(falcon_entity_set_data) {
    zend_long entity;
    zend_string *key;
    zend_string *value = NULL;
    ZEND_PARSE_PARAMETERS_START(3, 3)
        Z_PARAM_LONG(entity)
        Z_PARAM_STR(key)
        Z_PARAM_STR_OR_NULL(value)
    ZEND_PARSE_PARAMETERS_END();
    RETURN_BOOL(falcon->entitySetData(falcon_plugin, HANDLE(FalconEntity, entity), ZSTR_VAL(key),
                                      value != NULL ? ZSTR_VAL(value) : NULL) != 0);
}

// falcon_level_collision_boxes(level, minX, minY, minZ, maxX, maxY, maxZ): each box as six floats
PHP_FUNCTION(falcon_level_collision_boxes) {
    zend_long level;
    double minX, minY, minZ, maxX, maxY, maxZ;
    ZEND_PARSE_PARAMETERS_START(7, 7)
        Z_PARAM_LONG(level)
        Z_PARAM_DOUBLE(minX)
        Z_PARAM_DOUBLE(minY)
        Z_PARAM_DOUBLE(minZ)
        Z_PARAM_DOUBLE(maxX)
        Z_PARAM_DOUBLE(maxY)
        Z_PARAM_DOUBLE(maxZ)
    ZEND_PARSE_PARAMETERS_END();
    array_init(return_value);
    if (!FALCON_HAS(levelCollisionBoxes))
        return;
    FalconLevel *target = HANDLE(FalconLevel, level);
    const FalconVec3 from = {minX, minY, minZ};
    const FalconVec3 to = {maxX, maxY, maxZ};
    FalconBox stack[64];
    FalconBox *boxes = stack;
    uint32_t count = falcon->levelCollisionBoxes(target, from, to, boxes, 64);
    if (count > 64) {
        boxes = emalloc(sizeof(FalconBox) * count);
        count = falcon->levelCollisionBoxes(target, from, to, boxes, count);
    }
    for (uint32_t index = 0; index < count; ++index) {
        zval box;
        array_init_size(&box, 6);
        add_next_index_double(&box, boxes[index].min.x);
        add_next_index_double(&box, boxes[index].min.y);
        add_next_index_double(&box, boxes[index].min.z);
        add_next_index_double(&box, boxes[index].max.x);
        add_next_index_double(&box, boxes[index].max.y);
        add_next_index_double(&box, boxes[index].max.z);
        add_next_index_zval(return_value, &box);
    }
    if (boxes != stack)
        efree(boxes);
}

// an older Falcon cannot tell: the chunk is taken as sent
PHP_FUNCTION(falcon_player_has_chunk) {
    zend_long player, chunkX, chunkZ;
    ZEND_PARSE_PARAMETERS_START(3, 3)
        Z_PARAM_LONG(player)
        Z_PARAM_LONG(chunkX)
        Z_PARAM_LONG(chunkZ)
    ZEND_PARSE_PARAMETERS_END();
    RETURN_BOOL(!FALCON_HAS(playerHasChunk)
                || falcon->playerHasChunk(HANDLE(FalconPlayer, player), (int32_t) chunkX, (int32_t) chunkZ) != 0);
}

// falcon_item_network_id(item): [item id, block id] as Falcon's clients know them, [0, 0] for none
PHP_FUNCTION(falcon_item_network_id) {
    HashTable *data;
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_ARRAY_HT(data)
    ZEND_PARSE_PARAMETERS_END();
    int32_t item = 0;
    int32_t block = 0;
    FalconItem *made = FALCON_HAS(itemNetworkId) ? item_from_array(data) : NULL;
    if (made != NULL) {
        item = falcon->itemNetworkId(made, &block);
        falcon->itemDestroy(made);
    }
    array_init_size(return_value, 2);
    add_next_index_long(return_value, item);
    add_next_index_long(return_value, block);
}

ZEND_BEGIN_ARG_INFO_EX(arginfo_entity_any, 0, 0, 0)
    ZEND_ARG_VARIADIC_INFO(0, arguments)
ZEND_END_ARG_INFO()

#define ENTRY(name) ZEND_RAW_FENTRY(#name, ZEND_FN(name), arginfo_entity_any, 0)

const zend_function_entry falcon_entity_functions[] = {
    ENTRY(falcon_drawn_entities_supported)
    ENTRY(falcon_drawn_entity_spawn)
    ENTRY(falcon_entity_is_drawn)
    ENTRY(falcon_entity_set_size)
    ENTRY(falcon_entity_type)
    ENTRY(falcon_entity_data)
    ENTRY(falcon_entity_set_data)
    ENTRY(falcon_level_collision_boxes)
    ENTRY(falcon_player_has_chunk)
    ENTRY(falcon_item_network_id)
    PHP_FE_END
};
