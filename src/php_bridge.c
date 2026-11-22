// the embedded interpreter, kept in C: PHP's headers and its thread-safe globals are meant to be used from C
#include "php_bridge.h"

#include <php_embed.h>
#include <zend_exceptions.h>
#include <zend_stream.h>

#include <string.h>

static pmmp_log_sink log_sink = NULL;

static void emit(enum pmmp_log_level level, const char *text, size_t length) {
    // a line at a time: the logger adds its own line ends
    while (length > 0 && (text[length - 1] == '\n' || text[length - 1] == '\r'))
        --length;
    if (log_sink != NULL && length > 0)
        log_sink(level, text, length);
}

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_falcon_log, 0, 1, IS_VOID, 0)
    ZEND_ARG_TYPE_INFO(0, message, IS_STRING, 0)
ZEND_END_ARG_INFO()

PHP_FUNCTION(falcon_log) {
    zend_string *message;
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_STR(message)
    ZEND_PARSE_PARAMETERS_END();
    emit(PMMP_LOG_INFO, ZSTR_VAL(message), ZSTR_LEN(message));
}

static const zend_function_entry falcon_functions[] = {
    PHP_FE(falcon_log, arginfo_falcon_log)
    PHP_FE_END
};

static zend_module_entry falcon_module = {
    STANDARD_MODULE_HEADER,
    "falcon",
    falcon_functions,
    NULL, NULL, NULL, NULL, NULL,
    "0.1.0",
    STANDARD_MODULE_PROPERTIES
};

// the embed SAPI resets its function list in php_embed_init, its startup hook is ours to set
static zend_result startup_with_falcon(sapi_module_struct *module) {
    return php_module_startup(module, &falcon_module);
}

// echo and print
static size_t write_output(const char *text, size_t length) {
    emit(PMMP_LOG_INFO, text, length);
    return length;
}

// errors, warnings and notices the error log would get
static void write_log(const char *message, int syslog_type) {
    (void) syslog_type;
    emit(PMMP_LOG_ERROR, message, strlen(message));
}

static void set_default(HashTable *configuration, const char *name, const char *value) {
    zval entry;
    ZVAL_NEW_STR(&entry, zend_string_init(value, strlen(value), 1));
    zend_hash_str_update(configuration, name, strlen(name), &entry);
}

// read before any ini file; phar.readonly can only be turned off this early
static void ini_defaults(HashTable *configuration) {
    // errors go to the log once, not to the output as well
    set_default(configuration, "display_errors", "0");
    set_default(configuration, "log_errors", "1");
    // PocketMine-DevTools builds phars
    set_default(configuration, "phar.readonly", "0");
}

int pmmp_php_start(pmmp_log_sink sink) {
    log_sink = sink;
    php_embed_module.startup = startup_with_falcon;
    php_embed_module.ub_write = write_output;
    php_embed_module.log_message = write_log;
    php_embed_module.ini_defaults = ini_defaults;
    // the server's own settings only, never a php.ini found on the machine
    php_embed_module.php_ini_ignore = 1;
    if (php_embed_init(0, NULL) != SUCCESS)
        return 1;
    return 0;
}

// an uncaught exception is reported, a fatal error unwinds to here: neither reaches the server
int pmmp_php_run_file(const char *path) {
    int failed = 1;
    zend_try {
        zend_file_handle handle;
        zend_stream_init_filename(&handle, path);
        failed = php_execute_script(&handle) ? 0 : 1;
        zend_destroy_file_handle(&handle);
    } zend_catch {
        failed = 1;
    } zend_end_try();
    return failed;
}

int pmmp_php_run_code(const char *code, const char *name) {
    int failed = 1;
    zend_try {
        failed = zend_eval_string_ex(code, NULL, name, true) == SUCCESS ? 0 : 1;
    } zend_catch {
        failed = 1;
    } zend_end_try();
    return failed;
}

const char *pmmp_php_version(void) {
    return PHP_VERSION;
}

const char *pmmp_php_threading(void) {
#ifdef ZTS
    return "thread-safe";
#else
    return "not thread-safe";
#endif
}

size_t pmmp_php_memory(int peak) {
    return peak ? zend_memory_peak_usage(false) : zend_memory_usage(false);
}

void pmmp_php_stop(void) {
    php_embed_shutdown();
    log_sink = NULL;
}
