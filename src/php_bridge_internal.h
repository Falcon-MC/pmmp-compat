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

// the world, block, item and entity functions, registered with the falcon module
extern const zend_function_entry falcon_world_functions[];
