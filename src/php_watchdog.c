// the watchdog: when the plugins hold the server's thread for too long, PHP says where it is, as PocketMine-MP does
// when the server stops responding
#include "php_bridge_internal.h"

#include <zend_builtin_functions.h>
#include <zend_smart_str.h>

#include <stdatomic.h>
#include <stdio.h>

#ifdef _WIN32
#include <windows.h>
#else
#include <pthread.h>
#include <time.h>
#include <unistd.h>
#endif

// how long PHP may run without giving the server back before its stack is logged, again each time after
#define WATCHDOG_REPORT_MS 10000

static _Atomic int64_t busy_since = 0;
static atomic_int dump_requested = 0;
static atomic_bool running = false;
static zend_atomic_bool *main_interrupt = NULL;
static void (*previous_interrupt)(zend_execute_data *execute_data) = NULL;
static int depth = 0;

static int64_t now_ms(void) {
#ifdef _WIN32
    return (int64_t) GetTickCount64();
#else
    struct timespec now;
    clock_gettime(CLOCK_MONOTONIC, &now);
    return (int64_t) now.tv_sec * 1000 + now.tv_nsec / 1000000;
#endif
}

static void sleep_ms(unsigned milliseconds) {
#ifdef _WIN32
    Sleep(milliseconds);
#else
    usleep(milliseconds * 1000);
#endif
}

// on the server's thread, at the next opcode PHP runs: the stack goes to the log
static void report_stack(zend_execute_data *execute_data) {
    if (atomic_exchange(&dump_requested, 0) != 0 && tsrm_is_main_thread()) {
        const int64_t since = atomic_load(&busy_since);
        zval trace;
        zend_fetch_debug_backtrace(&trace, 0, DEBUG_BACKTRACE_IGNORE_ARGS, 25);
        smart_str text = {0};
        smart_str_append_printf(&text, "The PocketMine-MP plugins have held the server for %lld ms; PHP is at:",
                                (long long) (since == 0 ? 0 : now_ms() - since));
        zval *frame;
        int index = 0;
        ZEND_HASH_FOREACH_VAL(Z_ARRVAL(trace), frame) {
            zval *file = zend_hash_str_find(Z_ARRVAL_P(frame), "file", sizeof("file") - 1);
            zval *line = zend_hash_str_find(Z_ARRVAL_P(frame), "line", sizeof("line") - 1);
            zval *function = zend_hash_str_find(Z_ARRVAL_P(frame), "function", sizeof("function") - 1);
            zval *class_name = zend_hash_str_find(Z_ARRVAL_P(frame), "class", sizeof("class") - 1);
            smart_str_append_printf(&text, "\n  #%d %s%s%s() %s(%ld)", index++,
                                    class_name != NULL && Z_TYPE_P(class_name) == IS_STRING ? Z_STRVAL_P(class_name) : "",
                                    class_name != NULL ? "::" : "",
                                    function != NULL && Z_TYPE_P(function) == IS_STRING ? Z_STRVAL_P(function) : "{main}",
                                    file != NULL && Z_TYPE_P(file) == IS_STRING ? Z_STRVAL_P(file) : "",
                                    line != NULL && Z_TYPE_P(line) == IS_LONG ? (long) Z_LVAL_P(line) : 0L);
        } ZEND_HASH_FOREACH_END();
        smart_str_0(&text);
        if (falcon != NULL && text.s != NULL)
            falcon->log(falcon_plugin, FALCON_LOG_WARNING, ZSTR_VAL(text.s));
        smart_str_free(&text);
        zval_ptr_dtor(&trace);
    }
    if (previous_interrupt != NULL)
        previous_interrupt(execute_data);
}

#ifdef _WIN32
static DWORD WINAPI watch(LPVOID unused) {
#else
static void *watch(void *unused) {
#endif
    (void) unused;
    int64_t reported = 0;
    while (atomic_load(&running)) {
        sleep_ms(500);
        const int64_t since = atomic_load(&busy_since);
        if (since == 0) {
            reported = 0;
            continue;
        }
        const int64_t last = reported > since ? reported : since;
        if (now_ms() - last >= WATCHDOG_REPORT_MS && main_interrupt != NULL) {
            reported = now_ms();
            atomic_store(&dump_requested, 1);
            zend_atomic_bool_store(main_interrupt, true);
        }
    }
    return 0;
}

void pmmp_watchdog_start(void) {
    if (atomic_exchange(&running, true))
        return;
    main_interrupt = &EG(vm_interrupt);
    previous_interrupt = zend_interrupt_function;
    zend_interrupt_function = report_stack;
#ifdef _WIN32
    HANDLE thread = CreateThread(NULL, 0, watch, NULL, 0, NULL);
    if (thread != NULL)
        CloseHandle(thread);
#else
    pthread_t thread;
    if (pthread_create(&thread, NULL, watch, NULL) == 0)
        pthread_detach(thread);
#endif
}

void pmmp_watchdog_stop(void) {
    atomic_store(&running, false);
}

void pmmp_watchdog_enter(void) {
    if (depth++ == 0)
        atomic_store(&busy_since, now_ms());
}

void pmmp_watchdog_leave(void) {
    if (--depth == 0)
        atomic_store(&busy_since, 0);
}
