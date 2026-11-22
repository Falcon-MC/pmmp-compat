#pragma once

#include <stddef.h>

#ifdef __cplusplus
extern "C" {
#endif

enum pmmp_log_level {
    PMMP_LOG_INFO = 0,
    PMMP_LOG_WARNING = 1,
    PMMP_LOG_ERROR = 2
};

// where PHP's output, its errors and falcon_log() go
typedef void (*pmmp_log_sink)(enum pmmp_log_level level, const char *text, size_t length);

// starts the interpreter on the calling thread; 0 on success
int pmmp_php_start(pmmp_log_sink sink);

// runs a script file; 0 when it ran to its end, 1 when a fatal error stopped it
int pmmp_php_run_file(const char *path);

// runs a string of PHP code without the opening tag, as eval does; same results as pmmp_php_run_file
int pmmp_php_run_code(const char *code, const char *name);

// the version of the embedded PHP, such as "8.3.37"
const char *pmmp_php_version(void);

// "thread-safe" or "not thread-safe"
const char *pmmp_php_threading(void);

// the bytes the interpreter holds now and at most so far
size_t pmmp_php_memory(int peak);

void pmmp_php_stop(void);

#ifdef __cplusplus
}
#endif
