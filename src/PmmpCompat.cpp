#include "php_bridge.h"

#include <falcon/Falcon.hpp>

#include <string>

namespace {
    // the interpreter calls back without a plugin at hand
    const falcon::Logger *activeLogger = nullptr;

    void toLogger(pmmp_log_level level, const char *text, size_t length) {
        if (activeLogger == nullptr)
            return;
        const std::string line(text, length);
        if (level == PMMP_LOG_ERROR)
            activeLogger->error(line);
        else if (level == PMMP_LOG_WARNING)
            activeLogger->warning(line);
        else
            activeLogger->info(line);
    }

    std::string megabytes(size_t bytes) {
        return std::to_string(bytes / (1024 * 1024)) + "." + std::to_string(bytes % (1024 * 1024) * 10 / (1024 * 1024))
               + " MB";
    }
}

class PmmpCompat : public falcon::Plugin {
public:
    bool onEnable() override {
        activeLogger = &logger();
        if (pmmp_php_start(toLogger) != 0) {
            logger().error("PHP did not start");
            activeLogger = nullptr;
            return false;
        }
        mStarted = true;
        logger().info(std::string("PHP ") + pmmp_php_version() + " (" + pmmp_php_threading() + ") started");

        // the scripts ship next to the library, one folder up from the data folder
        const std::string scripts = dataFolder() + "/../php";
        if (pmmp_php_run_file((scripts + "/selftest.php").c_str()) != 0)
            logger().warning("the self test stopped early");
        // the interpreter stays usable after the fatal error the self test ends with
        if (pmmp_php_run_code("falcon_log('PHP still runs after the fatal error');", "after the self test") != 0)
            logger().error("PHP did not recover from the fatal error");
        logger().info("PHP memory: " + megabytes(pmmp_php_memory(0)) + " now, " + megabytes(pmmp_php_memory(1))
                      + " at most");
        return true;
    }

    void onDisable() override {
        if (mStarted)
            pmmp_php_stop();
        mStarted = false;
        logger().info("PHP stopped");
        activeLogger = nullptr;
    }

private:
    bool mStarted = false;
};

FALCON_PLUGIN(PmmpCompat)
