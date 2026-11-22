// the embedded interpreter, kept in C: PHP's headers and its thread-safe globals are meant to be used from C
#include "php_bridge.h"
#include "php_bridge_internal.h"

#include <php_embed.h>
#include <zend_exceptions.h>
#include <zend_gc.h>
#include <zend_stream.h>

#include <stddef.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>

static pmmp_log_sink log_sink = NULL;
static char *plugin_folder = NULL;
const FalconServerApi *falcon = NULL;
FalconPlugin *falcon_plugin = NULL;
static bool php_running = false;


static void return_text(zval *return_value, const char *text) {
    RETURN_STRING(text != NULL ? text : "");
}

static void emit(enum pmmp_log_level level, const char *text, size_t length) {
    // a line at a time: the logger adds its own line ends
    while (length > 0 && (text[length - 1] == '\n' || text[length - 1] == '\r'))
        --length;
    if (log_sink != NULL && length > 0)
        log_sink(level, text, length);
}

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_falcon_log, 0, 1, IS_VOID, 0)
    ZEND_ARG_TYPE_INFO(0, message, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, level, IS_LONG, 0, "0")
ZEND_END_ARG_INFO()

// falcon_log(message, level): 0 info, 1 warning, 2 error
PHP_FUNCTION(falcon_log) {
    zend_string *message;
    zend_long level = PMMP_LOG_INFO;
    ZEND_PARSE_PARAMETERS_START(1, 2)
        Z_PARAM_STR(message)
        Z_PARAM_OPTIONAL
        Z_PARAM_LONG(level)
    ZEND_PARSE_PARAMETERS_END();
    if (level < PMMP_LOG_INFO || level > PMMP_LOG_ERROR)
        level = PMMP_LOG_INFO;
    emit((enum pmmp_log_level) level, ZSTR_VAL(message), ZSTR_LEN(message));
}

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_falcon_plugin_folder, 0, 0, IS_STRING, 0)
ZEND_END_ARG_INFO()

// the folder holding the plugins/ and plugin_data/ folders
PHP_FUNCTION(falcon_plugin_folder) {
    ZEND_PARSE_PARAMETERS_NONE();
    RETURN_STRING(plugin_folder != NULL ? plugin_folder : "");
}

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_falcon_guard, 0, 1, _IS_BOOL, 0)
    ZEND_ARG_TYPE_INFO(0, callback, IS_CALLABLE, 0)
ZEND_END_ARG_INFO()

// what a fatal error leaves behind: it unwinds straight here, past every frame the callback pushed
struct guard_state {
    zend_execute_data *execute_data;
    zend_vm_stack stack;
    zval *stack_top;
    zval *stack_end;
    zend_class_entry *fake_scope;
    int error_reporting;
    bool autoloading;
};

static void guard_save(struct guard_state *state) {
    state->execute_data = EG(current_execute_data);
    state->stack = EG(vm_stack);
    state->stack_top = EG(vm_stack_top);
    state->stack_end = EG(vm_stack_end);
    state->fake_scope = EG(fake_scope);
    state->error_reporting = EG(error_reporting);
    state->autoloading = EG(in_autoload) != NULL && zend_hash_num_elements(EG(in_autoload)) > 0;
}

static void guard_restore(const struct guard_state *state) {
    // the frames above ours are dropped with what they held: a leak, the price of going on
    while (EG(vm_stack) != state->stack && EG(vm_stack) != NULL) {
        zend_vm_stack page = EG(vm_stack);
        EG(vm_stack) = page->prev;
        efree(page);
    }
    EG(vm_stack_top) = state->stack_top;
    EG(vm_stack_end) = state->stack_end;
    EG(current_execute_data) = state->execute_data;
    EG(fake_scope) = state->fake_scope;
    EG(error_reporting) = state->error_reporting;
    // a class whose autoload died would otherwise never be looked for again
    if (!state->autoloading && EG(in_autoload) != NULL)
        zend_hash_clean(EG(in_autoload));
    EG(exit_status) = 0;
    CG(unclean_shutdown) = 0;
    gc_protect(0);
}

// calls a static method of the runtime's Bridge class from Falcon's side, as falcon_guard protects a callback
bool call_bridge(const char *method, uint32_t count, zval *arguments, zval *result) {
    ZVAL_UNDEF(result);
    if (!php_running)
        return false;
    zval function;
    ZVAL_STRING(&function, method);
    struct guard_state state;
    guard_save(&state);
    bool completed = true;
    pmmp_watchdog_enter();
    zend_try {
        completed = call_user_function(NULL, NULL, &function, result, count, arguments) == SUCCESS;
    } zend_catch {
        completed = false;
    } zend_end_try();
    pmmp_watchdog_leave();
    if (!completed)
        guard_restore(&state);
    // the Bridge catches what PHP throws; what is left is exit(), which must not end the server's PHP
    if (EG(exception) != NULL)
        zend_clear_exception();
    zval_ptr_dtor(&function);
    return completed;
}

// falcon_guard(callback): false when a fatal error or exit() stopped the callback; exceptions pass through
PHP_FUNCTION(falcon_guard) {
    zend_fcall_info call;
    zend_fcall_info_cache cache;
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_FUNC(call, cache)
    ZEND_PARSE_PARAMETERS_END();

    struct guard_state state;
    guard_save(&state);
    zval result;
    ZVAL_UNDEF(&result);
    call.retval = &result;
    bool completed = true;
    zend_try {
        zend_call_function(&call, &cache);
    } zend_catch {
        completed = false;
    } zend_end_try();

    if (!completed) {
        guard_restore(&state);
        RETURN_FALSE;
    }
    zval_ptr_dtor(&result);
    // exit() unwinds as an exception nothing can catch, which would end the server's PHP
    if (EG(exception) != NULL && zend_is_unwind_exit(EG(exception))) {
        zend_clear_exception();
        EG(exit_status) = 0;
        RETURN_FALSE;
    }
    RETURN_TRUE;
}

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_falcon_warning, 0, 1, IS_VOID, 0)
    ZEND_ARG_TYPE_INFO(0, message, IS_STRING, 0)
ZEND_END_ARG_INFO()

// raises an E_WARNING from the calling function, as an extension's function would; trigger_error() only has E_USER_*
PHP_FUNCTION(falcon_warning) {
    zend_string *message;
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_STR(message)
    ZEND_PARSE_PARAMETERS_END();
    zend_error(E_WARNING, "%s", ZSTR_VAL(message));
}

// --- what the PHP side calls of Falcon: players, events and commands, by handle ---

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_handle_string, 0, 1, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, handle, IS_LONG, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_handle_long, 0, 1, IS_LONG, 0)
    ZEND_ARG_TYPE_INFO(0, handle, IS_LONG, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_handle_bool, 0, 1, _IS_BOOL, 0)
    ZEND_ARG_TYPE_INFO(0, handle, IS_LONG, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_handle_text_void, 0, 2, IS_VOID, 0)
    ZEND_ARG_TYPE_INFO(0, handle, IS_LONG, 0)
    ZEND_ARG_TYPE_INFO(0, text, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_handle_text_bool, 0, 2, _IS_BOOL, 0)
    ZEND_ARG_TYPE_INFO(0, handle, IS_LONG, 0)
    ZEND_ARG_TYPE_INFO(0, text, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_handle_flag_void, 0, 2, IS_VOID, 0)
    ZEND_ARG_TYPE_INFO(0, handle, IS_LONG, 0)
    ZEND_ARG_TYPE_INFO(0, flag, _IS_BOOL, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_handle_two_texts_void, 0, 3, IS_VOID, 0)
    ZEND_ARG_TYPE_INFO(0, handle, IS_LONG, 0)
    ZEND_ARG_TYPE_INFO(0, first, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, second, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_text_bool, 0, 1, _IS_BOOL, 0)
    ZEND_ARG_TYPE_INFO(0, text, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_none_array, 0, 0, IS_ARRAY, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_register_command, 0, 3, _IS_BOOL, 0)
    ZEND_ARG_TYPE_INFO(0, name, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, description, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, usage, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_command_set_aliases, 0, 2, _IS_BOOL, 0)
    ZEND_ARG_TYPE_INFO(0, name, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, aliases, IS_ARRAY, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_handle_long_void, 0, 2, IS_VOID, 0)
    ZEND_ARG_TYPE_INFO(0, handle, IS_LONG, 0)
    ZEND_ARG_TYPE_INFO(0, value, IS_LONG, 0)
ZEND_END_ARG_INFO()

#define HANDLE_ARGUMENT(variable) \
    zend_long variable; \
    ZEND_PARSE_PARAMETERS_START(1, 1) \
        Z_PARAM_LONG(variable) \
    ZEND_PARSE_PARAMETERS_END()

#define HANDLE_TEXT_ARGUMENTS(variable, text) \
    zend_long variable; \
    zend_string *text; \
    ZEND_PARSE_PARAMETERS_START(2, 2) \
        Z_PARAM_LONG(variable) \
        Z_PARAM_STR(text) \
    ZEND_PARSE_PARAMETERS_END()

PHP_FUNCTION(falcon_event_player) {
    HANDLE_ARGUMENT(event);
    RETURN_LONG(TO_LONG(falcon->eventPlayer(HANDLE(FalconEvent, event))));
}

PHP_FUNCTION(falcon_event_message) {
    HANDLE_ARGUMENT(event);
    return_text(return_value, falcon->eventMessage(HANDLE(FalconEvent, event)));
}

PHP_FUNCTION(falcon_event_set_message) {
    HANDLE_TEXT_ARGUMENTS(event, message);
    falcon->eventSetMessage(HANDLE(FalconEvent, event), ZSTR_VAL(message));
}

PHP_FUNCTION(falcon_event_cause) {
    HANDLE_ARGUMENT(event);
    return_text(return_value, falcon->eventCause(HANDLE(FalconEvent, event)));
}

PHP_FUNCTION(falcon_event_is_cancelled) {
    HANDLE_ARGUMENT(event);
    RETURN_BOOL(falcon->eventIsCancelled(HANDLE(FalconEvent, event)) != 0);
}

PHP_FUNCTION(falcon_event_set_cancelled) {
    zend_long event;
    bool cancelled;
    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_LONG(event)
        Z_PARAM_BOOL(cancelled)
    ZEND_PARSE_PARAMETERS_END();
    falcon->eventSetCancelled(HANDLE(FalconEvent, event), cancelled ? 1 : 0);
}

PHP_FUNCTION(falcon_event_state) {
    HANDLE_ARGUMENT(event);
    RETURN_BOOL(falcon->eventState(HANDLE(FalconEvent, event)) != 0);
}

PHP_FUNCTION(falcon_event_game_mode) {
    HANDLE_ARGUMENT(event);
    RETURN_LONG((zend_long) falcon->eventGameMode(HANDLE(FalconEvent, event)));
}

PHP_FUNCTION(falcon_player_game_mode) {
    HANDLE_ARGUMENT(player);
    RETURN_LONG((zend_long) falcon->playerGameMode(HANDLE(FalconPlayer, player)));
}

PHP_FUNCTION(falcon_player_set_game_mode) {
    zend_long player;
    zend_long gameMode;
    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_LONG(player)
        Z_PARAM_LONG(gameMode)
    ZEND_PARSE_PARAMETERS_END();
    falcon->playerSetGameMode(HANDLE(FalconPlayer, player), (FalconGameMode) gameMode);
}

PHP_FUNCTION(falcon_player_name) {
    HANDLE_ARGUMENT(player);
    return_text(return_value, falcon->playerName(HANDLE(FalconPlayer, player)));
}

PHP_FUNCTION(falcon_player_xuid) {
    HANDLE_ARGUMENT(player);
    return_text(return_value, falcon->playerXuid(HANDLE(FalconPlayer, player)));
}

PHP_FUNCTION(falcon_player_is_op) {
    HANDLE_ARGUMENT(player);
    RETURN_BOOL(falcon->playerIsOperator(HANDLE(FalconPlayer, player)) != 0);
}

PHP_FUNCTION(falcon_player_send_message) {
    HANDLE_TEXT_ARGUMENTS(player, message);
    falcon->playerSendMessage(HANDLE(FalconPlayer, player), ZSTR_VAL(message));
}

PHP_FUNCTION(falcon_player_send_title) {
    zend_long player;
    zend_string *title;
    zend_string *subtitle;
    ZEND_PARSE_PARAMETERS_START(3, 3)
        Z_PARAM_LONG(player)
        Z_PARAM_STR(title)
        Z_PARAM_STR(subtitle)
    ZEND_PARSE_PARAMETERS_END();
    falcon->playerSendTitle(HANDLE(FalconPlayer, player), ZSTR_VAL(title), ZSTR_VAL(subtitle));
}

PHP_FUNCTION(falcon_player_send_action_bar) {
    HANDLE_TEXT_ARGUMENTS(player, message);
    falcon->playerSendActionBar(HANDLE(FalconPlayer, player), ZSTR_VAL(message));
}

PHP_FUNCTION(falcon_player_kick) {
    HANDLE_TEXT_ARGUMENTS(player, reason);
    falcon->playerKick(HANDLE(FalconPlayer, player), ZSTR_VAL(reason));
}

PHP_FUNCTION(falcon_player_chat) {
    HANDLE_TEXT_ARGUMENTS(player, message);
    if (!FALCON_HAS(playerChat))
        RETURN_FALSE;
    RETURN_BOOL(falcon->playerChat(HANDLE(FalconPlayer, player), ZSTR_VAL(message)) != 0);
}

PHP_FUNCTION(falcon_player_execute_command) {
    HANDLE_TEXT_ARGUMENTS(player, line);
    RETURN_BOOL(falcon->playerExecuteCommand(HANDLE(FalconPlayer, player), ZSTR_VAL(line)) != 0);
}

PHP_FUNCTION(falcon_online_players) {
    ZEND_PARSE_PARAMETERS_NONE();
    const uint32_t count = falcon->onlinePlayerCount();
    array_init_size(return_value, count);
    for (uint32_t index = 0; index < count; ++index) {
        FalconPlayer *player = falcon->onlinePlayer(index);
        if (player != NULL)
            add_next_index_long(return_value, TO_LONG(player));
    }
}

PHP_FUNCTION(falcon_execute_command) {
    zend_string *line;
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_STR(line)
    ZEND_PARSE_PARAMETERS_END();
    RETURN_BOOL(falcon->serverExecuteCommand(ZSTR_VAL(line)) != 0);
}

PHP_FUNCTION(falcon_sender_player) {
    HANDLE_ARGUMENT(sender);
    RETURN_LONG(TO_LONG(falcon->senderPlayer(HANDLE(FalconCommandSender, sender))));
}

// a PocketMine-MP command Falcon received: the Bridge finds it by the label it was registered with
static int run_command(FalconCommandSender *sender, const char *const *arguments, uint32_t count, void *label) {
    zval call[3];
    ZVAL_STRING(&call[0], (const char *) label);
    ZVAL_LONG(&call[1], TO_LONG(sender));
    array_init_size(&call[2], count);
    for (uint32_t index = 0; index < count; ++index)
        add_next_index_string(&call[2], arguments[index] != NULL ? arguments[index] : "");
    zval result;
    const bool completed = call_bridge("pmmpcompat\\Bridge::command", 3, call, &result);
    const int handled = completed && Z_TYPE(result) == IS_TRUE ? 1 : 0;
    zval_ptr_dtor(&result);
    for (int index = 0; index < 3; ++index)
        zval_ptr_dtor(&call[index]);
    return handled;
}

// user data of an event subscription: the event type, and the PocketMine-MP priority it stands for
#define SUBSCRIPTION(type, priority) ((void *) (uintptr_t) (((type) << 4) | (priority)))

static void pass_event(FalconEvent *event, void *subscription) {
    const uintptr_t packed = (uintptr_t) subscription;
    zval call[3];
    ZVAL_LONG(&call[0], (zend_long) (packed >> 4));
    ZVAL_LONG(&call[1], (zend_long) (packed & 0xF));
    ZVAL_LONG(&call[2], TO_LONG(event));
    zval result;
    call_bridge("pmmpcompat\\Bridge::event", 3, call, &result);
    zval_ptr_dtor(&result);
}

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_falcon_listen, 0, 1, IS_VOID, 0)
    ZEND_ARG_TYPE_INFO(0, type, IS_LONG, 0)
ZEND_END_ARG_INFO()

// falcon_listen(type): Falcon passes that event to the Bridge at LOWEST to HIGHEST, PocketMine-MP's 5 to 1;
// PocketMine-MP's MONITOR runs within HIGHEST
PHP_FUNCTION(falcon_listen) {
    zend_long type;
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_LONG(type)
    ZEND_PARSE_PARAMETERS_END();
    for (FalconEventPriority priority = FALCON_PRIORITY_LOWEST; priority <= FALCON_PRIORITY_HIGHEST; ++priority)
        falcon->subscribe(falcon_plugin, (FalconEventType) type, priority, 0, pass_event,
                          SUBSCRIPTION((uintptr_t) type, 5u - priority));
}

PHP_FUNCTION(falcon_register_command) {
    zend_string *name;
    zend_string *description;
    zend_string *usage;
    ZEND_PARSE_PARAMETERS_START(3, 3)
        Z_PARAM_STR(name)
        Z_PARAM_STR(description)
        Z_PARAM_STR(usage)
    ZEND_PARSE_PARAMETERS_END();
    // the label comes back with each run; registered commands last as long as the server
    char *label = malloc(ZSTR_LEN(name) + 1);
    if (label == NULL)
        RETURN_FALSE;
    memcpy(label, ZSTR_VAL(name), ZSTR_LEN(name) + 1);
    FalconCommandDescriptor descriptor;
    descriptor.name = label;
    descriptor.description = ZSTR_VAL(description);
    descriptor.usage = ZSTR_VAL(usage);
    // PocketMine-MP checks the command's permissions itself, with its own refusal message
    descriptor.permission = FALCON_PERMISSION_ANY;
    descriptor.handler = run_command;
    descriptor.userData = label;
    if (falcon->registerCommand(falcon_plugin, &descriptor) == 0) {
        free(label);
        RETURN_FALSE;
    }
    RETURN_TRUE;
}

PHP_FUNCTION(falcon_command_set_aliases) {
    zend_string *name;
    HashTable *aliases;
    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_STR(name)
        Z_PARAM_ARRAY_HT(aliases)
    ZEND_PARSE_PARAMETERS_END();
    if (!FALCON_HAS(commandSetAliases))
        RETURN_FALSE;
    const uint32_t count = zend_hash_num_elements(aliases);
    const char **names = emalloc(sizeof(const char *) * (count > 0 ? count : 1));
    uint32_t used = 0;
    zval *alias;
    ZEND_HASH_FOREACH_VAL(aliases, alias) {
        if (Z_TYPE_P(alias) == IS_STRING)
            names[used++] = Z_STRVAL_P(alias);
    } ZEND_HASH_FOREACH_END();
    const int set = falcon->commandSetAliases(falcon_plugin, ZSTR_VAL(name), names, used);
    efree(names);
    RETURN_BOOL(set != 0);
}

static const zend_function_entry falcon_functions[] = {
    PHP_FE(falcon_log, arginfo_falcon_log)
    PHP_FE(falcon_plugin_folder, arginfo_falcon_plugin_folder)
    PHP_FE(falcon_guard, arginfo_falcon_guard)
    PHP_FE(falcon_warning, arginfo_falcon_warning)
    ZEND_RAW_FENTRY("falcon_event_player", ZEND_FN(falcon_event_player), arginfo_handle_long, 0)
    ZEND_RAW_FENTRY("falcon_event_message", ZEND_FN(falcon_event_message), arginfo_handle_string, 0)
    ZEND_RAW_FENTRY("falcon_event_set_message", ZEND_FN(falcon_event_set_message), arginfo_handle_text_void, 0)
    ZEND_RAW_FENTRY("falcon_event_cause", ZEND_FN(falcon_event_cause), arginfo_handle_string, 0)
    ZEND_RAW_FENTRY("falcon_event_is_cancelled", ZEND_FN(falcon_event_is_cancelled), arginfo_handle_bool, 0)
    ZEND_RAW_FENTRY("falcon_event_set_cancelled", ZEND_FN(falcon_event_set_cancelled), arginfo_handle_flag_void, 0)
    ZEND_RAW_FENTRY("falcon_event_state", ZEND_FN(falcon_event_state), arginfo_handle_bool, 0)
    ZEND_RAW_FENTRY("falcon_event_game_mode", ZEND_FN(falcon_event_game_mode), arginfo_handle_long, 0)
    ZEND_RAW_FENTRY("falcon_player_game_mode", ZEND_FN(falcon_player_game_mode), arginfo_handle_long, 0)
    ZEND_RAW_FENTRY("falcon_player_set_game_mode", ZEND_FN(falcon_player_set_game_mode), arginfo_handle_long_void, 0)
    ZEND_RAW_FENTRY("falcon_listen", ZEND_FN(falcon_listen), arginfo_falcon_listen, 0)
    ZEND_RAW_FENTRY("falcon_player_name", ZEND_FN(falcon_player_name), arginfo_handle_string, 0)
    ZEND_RAW_FENTRY("falcon_player_xuid", ZEND_FN(falcon_player_xuid), arginfo_handle_string, 0)
    ZEND_RAW_FENTRY("falcon_player_is_op", ZEND_FN(falcon_player_is_op), arginfo_handle_bool, 0)
    ZEND_RAW_FENTRY("falcon_player_send_message", ZEND_FN(falcon_player_send_message), arginfo_handle_text_void, 0)
    ZEND_RAW_FENTRY("falcon_player_send_title", ZEND_FN(falcon_player_send_title), arginfo_handle_two_texts_void, 0)
    ZEND_RAW_FENTRY("falcon_player_send_action_bar", ZEND_FN(falcon_player_send_action_bar), arginfo_handle_text_void, 0)
    ZEND_RAW_FENTRY("falcon_player_kick", ZEND_FN(falcon_player_kick), arginfo_handle_text_void, 0)
    ZEND_RAW_FENTRY("falcon_player_chat", ZEND_FN(falcon_player_chat), arginfo_handle_text_bool, 0)
    ZEND_RAW_FENTRY("falcon_player_execute_command", ZEND_FN(falcon_player_execute_command), arginfo_handle_text_bool, 0)
    ZEND_RAW_FENTRY("falcon_online_players", ZEND_FN(falcon_online_players), arginfo_none_array, 0)
    ZEND_RAW_FENTRY("falcon_execute_command", ZEND_FN(falcon_execute_command), arginfo_text_bool, 0)
    ZEND_RAW_FENTRY("falcon_sender_player", ZEND_FN(falcon_sender_player), arginfo_handle_long, 0)
    ZEND_RAW_FENTRY("falcon_register_command", ZEND_FN(falcon_register_command), arginfo_register_command, 0)
    ZEND_RAW_FENTRY("falcon_command_set_aliases", ZEND_FN(falcon_command_set_aliases), arginfo_command_set_aliases, 0)
    PHP_FE_END
};

// pmmp's ext-encoding, compiled into this plugin: BedrockProtocol reads and writes packets with it
extern zend_module_entry encoding_module_entry;

// the world, block, item and entity functions live in php_world.c; ext-encoding's classes start with this module,
// as a second module could only start once the engine no longer takes new persistent strings
static PHP_MINIT_FUNCTION(falcon) {
    if (zend_register_functions(NULL, falcon_world_functions, NULL, MODULE_PERSISTENT) != SUCCESS)
        return FAILURE;
    if (zend_register_functions(NULL, falcon_living_functions, NULL, MODULE_PERSISTENT) != SUCCESS)
        return FAILURE;
    if (zend_register_functions(NULL, falcon_server_functions, NULL, MODULE_PERSISTENT) != SUCCESS)
        return FAILURE;
    return encoding_module_entry.module_startup_func(type, module_number);
}

static zend_module_entry falcon_module = {
    STANDARD_MODULE_HEADER,
    "falcon",
    falcon_functions,
    PHP_MINIT(falcon), NULL, NULL, NULL, NULL,
    "0.1.0",
    STANDARD_MODULE_PROPERTIES
};

// the embed SAPI resets its function list in php_embed_init, its startup hook is ours to set
#ifdef PHP_WIN32
// the extensions PocketMine-MP's Windows build keeps outside php8ts.dll, beside the plugin in ext/
static char *windows_ini = NULL;

static void load_windows_extensions(sapi_module_struct *module) {
    static const char extensions[] = "extension=pmmpthread\nextension=openssl\nextension=igbinary\n"
                                     "extension=mysqli\nextension=sqlite3\n";
    const char *base = module->ini_entries != NULL ? module->ini_entries : "";
    const char *folder = plugin_folder != NULL ? plugin_folder : ".";
    const size_t length = strlen(base) + strlen(folder) + sizeof("extension_dir=\"/ext\"\n") + sizeof(extensions);
    free(windows_ini);
    windows_ini = malloc(length);
    if (windows_ini == NULL)
        return;
    snprintf(windows_ini, length, "%sextension_dir=\"%s/ext\"\n%s", base, folder, extensions);
    module->ini_entries = windows_ini;
}
#endif

static zend_result startup_with_falcon(sapi_module_struct *module) {
#ifdef PHP_WIN32
    // the dlls the extensions load sit beside the plugin, which Windows does not look in by itself
    if (plugin_folder != NULL) {
        wchar_t folder[MAX_PATH];
        if (MultiByteToWideChar(CP_UTF8, 0, plugin_folder, -1, folder, MAX_PATH) > 0)
            SetDllDirectoryW(folder);
    }
    load_windows_extensions(module);
#endif
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

void pmmp_php_set_falcon(const FalconServerApi *api, FalconPlugin *plugin) {
    falcon = api;
    falcon_plugin = plugin;
}

void pmmp_php_tick(void) {
    zval result;
    call_bridge("pmmpcompat\\Bridge::tick", 0, NULL, &result);
    zval_ptr_dtor(&result);
}

int pmmp_php_start(pmmp_log_sink sink, const char *folder) {
    log_sink = sink;
    free(plugin_folder);
    const size_t length = strlen(folder);
    plugin_folder = malloc(length + 1);
    if (plugin_folder != NULL)
        memcpy(plugin_folder, folder, length + 1);
    // PHP answers to "cli" as under PocketMine-MP: plugins check PHP_SAPI, and pmmpthread starts only there
    php_embed_module.name = (char *) "cli";
    php_embed_module.startup = startup_with_falcon;
    php_embed_module.ub_write = write_output;
    php_embed_module.log_message = write_log;
    php_embed_module.ini_defaults = ini_defaults;
    // the server's own settings only, never a php.ini found on the machine
    php_embed_module.php_ini_ignore = 1;
    if (php_embed_init(0, NULL) != SUCCESS)
        return 1;
    php_running = true;
    pmmp_watchdog_start();
    return 0;
}

// an uncaught exception is reported, a fatal error unwinds to here: neither reaches the server
int pmmp_php_run_file(const char *path) {
    int failed = 1;
    pmmp_watchdog_enter();
    zend_try {
        zend_file_handle handle;
        zend_stream_init_filename(&handle, path);
        failed = php_execute_script(&handle) ? 0 : 1;
        zend_destroy_file_handle(&handle);
    } zend_catch {
        failed = 1;
    } zend_end_try();
    pmmp_watchdog_leave();
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
    pmmp_watchdog_stop();
    php_running = false;
    php_embed_shutdown();
    log_sink = NULL;
    free(plugin_folder);
    plugin_folder = NULL;
}
