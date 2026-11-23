// what the bridge's C files share: the API table, this plugin's handle, and how handles travel through PHP
#pragma once

#include <falcon/falcon_api.h>

#include <php.h>

#include <stddef.h>
#include <stdint.h>

extern const FalconServerApi *falcon;
extern FalconPlugin *falcon_plugin;

// a function added to the table after the oldest Falcon this runs on: absent from a shorter table
#define FALCON_HAS(field) \
    (falcon != NULL && falcon->size >= offsetof(FalconServerApi, field) + sizeof(falcon->field) && falcon->field != NULL)

// Falcon's handles travel through PHP as integers
#define HANDLE(type, value) ((type *) (uintptr_t) (value))
#define TO_LONG(pointer) ((zend_long) (uintptr_t) (pointer))

// a static method of the runtime's Bridge class, guarded as falcon_guard guards a callback; false when it stopped
bool call_bridge(const char *method, uint32_t count, zval *arguments, zval *result);

// an item as PHP keeps it, {id, count, damage, nbt}, and back: an owned item the caller destroys, NULL for none
void item_to_array(FalconItem *item, zval *out);
FalconItem *item_from_array(HashTable *data);

// the watchdog: started with PHP; the bridge tells it when PHP holds the server's thread
void pmmp_watchdog_start(void);
void pmmp_watchdog_stop(void);
void pmmp_watchdog_enter(void);
void pmmp_watchdog_leave(void);

// the world, block, item and entity functions, registered with the falcon module
extern const zend_function_entry falcon_world_functions[];

// effects, attributes, skins, weather, explosions and signs
extern const zend_function_entry falcon_living_functions[];

// bans, the allow list, the MOTD, slots, ticks, difficulty and game rules
extern const zend_function_entry falcon_server_functions[];

// the entities PocketMine-MP plugins define, which PHP draws for clients
extern const zend_function_entry falcon_entity_functions[];
