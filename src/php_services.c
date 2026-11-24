#include "php_bridge_internal.h"

#include <string.h>

typedef struct PhpService {
    zend_string *name;
    struct PhpService *next;
} PhpService;

static PhpService *registered_services;
static zend_string *service_response;

static bool on_main_thread(void) {
#ifdef ZTS
    return tsrm_is_main_thread();
#else
    return true;
#endif
}

static const char *serve(const char *request, void *user_data) {
    if (!on_main_thread())
        return "";
    PhpService *service = user_data;
    zval arguments[2];
    zval result;
    ZVAL_STR_COPY(&arguments[0], service->name);
    ZVAL_STRING(&arguments[1], request != NULL ? request : "");
    const bool completed = call_bridge("pmmpcompat\\PluginServices::dispatch", 2, arguments, &result);
    zval_ptr_dtor(&arguments[0]);
    zval_ptr_dtor(&arguments[1]);

    if (service_response != NULL)
        zend_string_release(service_response);
    service_response = completed && Z_TYPE(result) == IS_STRING ? zend_string_copy(Z_STR(result)) : NULL;
    zval_ptr_dtor(&result);
    return service_response != NULL ? ZSTR_VAL(service_response) : "";
}

static void remove_service(PhpService **link) {
    PhpService *service = *link;
    *link = service->next;
    falcon->unregisterService(falcon_plugin, ZSTR_VAL(service->name));
    zend_string_release(service->name);
    efree(service);
}

void pmmp_php_clear_services(void) {
    while (registered_services != NULL)
        remove_service(&registered_services);
    if (service_response != NULL) {
        zend_string_release(service_response);
        service_response = NULL;
    }
}

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_service_flag, 0, 1, _IS_BOOL, 0)
    ZEND_ARG_TYPE_INFO(0, name, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_service_unregister, 0, 1, IS_VOID, 0)
    ZEND_ARG_TYPE_INFO(0, name, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_service_provider, 0, 1, IS_STRING, 1)
    ZEND_ARG_TYPE_INFO(0, name, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_service_call, 0, 2, IS_STRING, 1)
    ZEND_ARG_TYPE_INFO(0, name, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, request, IS_STRING, 0)
ZEND_END_ARG_INFO()

PHP_FUNCTION(falcon_service_register) {
    zend_string *name;
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_PATH_STR(name)
    ZEND_PARSE_PARAMETERS_END();
    if (!on_main_thread() || !FALCON_HAS(registerService) || !FALCON_HAS(unregisterService)
        || ZSTR_LEN(name) == 0)
        RETURN_FALSE;
    for (PhpService *entry = registered_services; entry != NULL; entry = entry->next) {
        if (zend_string_equals(entry->name, name))
            RETURN_FALSE;
    }
    PhpService *service = emalloc(sizeof(*service));
    service->name = zend_string_copy(name);
    if (!falcon->registerService(falcon_plugin, ZSTR_VAL(name), serve, service)) {
        zend_string_release(service->name);
        efree(service);
        RETURN_FALSE;
    }
    service->next = registered_services;
    registered_services = service;
    RETURN_TRUE;
}

PHP_FUNCTION(falcon_service_unregister) {
    zend_string *name;
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_PATH_STR(name)
    ZEND_PARSE_PARAMETERS_END();
    if (!on_main_thread())
        return;
    for (PhpService **link = &registered_services; *link != NULL; link = &(*link)->next) {
        if (zend_string_equals((*link)->name, name)) {
            remove_service(link);
            return;
        }
    }
}

PHP_FUNCTION(falcon_service_exists) {
    zend_string *name;
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_PATH_STR(name)
    ZEND_PARSE_PARAMETERS_END();
    RETURN_BOOL(on_main_thread() && FALCON_HAS(hasService) && falcon->hasService(ZSTR_VAL(name)));
}

PHP_FUNCTION(falcon_service_provider) {
    zend_string *name;
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_PATH_STR(name)
    ZEND_PARSE_PARAMETERS_END();
    if (!on_main_thread() || !FALCON_HAS(serviceProvider))
        RETURN_NULL();
    const char *provider = falcon->serviceProvider(ZSTR_VAL(name));
    if (provider == NULL)
        RETURN_NULL();
    RETURN_STRING(provider);
}

PHP_FUNCTION(falcon_service_call) {
    zend_string *name;
    zend_string *request;
    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_PATH_STR(name)
        Z_PARAM_PATH_STR(request)
    ZEND_PARSE_PARAMETERS_END();
    if (!on_main_thread() || !FALCON_HAS(callService))
        RETURN_NULL();
    int found = 0;
    const char *response = falcon->callService(ZSTR_VAL(name), ZSTR_VAL(request), &found);
    if (!found)
        RETURN_NULL();
    RETURN_STRING(response != NULL ? response : "");
}

const zend_function_entry falcon_service_functions[] = {
    PHP_FE(falcon_service_register, arginfo_service_flag)
    PHP_FE(falcon_service_unregister, arginfo_service_unregister)
    PHP_FE(falcon_service_exists, arginfo_service_flag)
    PHP_FE(falcon_service_provider, arginfo_service_provider)
    PHP_FE(falcon_service_call, arginfo_service_call)
    PHP_FE_END
};
