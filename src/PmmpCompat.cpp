#include "php_bridge.h"

#include <falcon/Falcon.hpp>

#include <cstdlib>
#include <filesystem>
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
        // the library sits beside the plugins/ and plugin_data/ folders, one up from the data folder
        std::error_code failure;
        std::filesystem::path data = std::filesystem::absolute(dataFolder(), failure);
        if (failure)
            data = dataFolder();
        if (!data.has_filename())
            data = data.parent_path();
        const std::filesystem::path folder = data.parent_path();
        const std::string runtime = (folder / "php").generic_string();
        if (pmmp_php_start(toLogger, folder.generic_string().c_str()) != 0) {
            logger().error("PHP did not start");
            activeLogger = nullptr;
            return false;
        }
        mStarted = true;
        logger().info(std::string("PHP ") + pmmp_php_version() + " (" + pmmp_php_threading() + ") started");

        if (std::getenv("PMMP_COMPAT_SELFTEST") != nullptr)
            selfTest(runtime);

        if (pmmp_php_run_file((runtime + "/bootstrap.php").c_str()) != 0)
            logger().error("the PocketMine-MP plugins did not all load: a fatal error stopped PHP");
        return true;
    }

    void onDisable() override {
        if (mStarted) {
            pmmp_php_run_code("\\pmmpcompat\\Runtime::stop();", "shutdown");
            pmmp_php_stop();
        }
        mStarted = false;
        logger().info("PHP stopped");
        activeLogger = nullptr;
    }

private:
    // checks the interpreter itself; CI asks for it with PMMP_COMPAT_SELFTEST
    void selfTest(const std::string &runtime) {
        if (pmmp_php_run_file((runtime + "/selftest.php").c_str()) != 0)
            logger().warning("the self test stopped early");
        // the interpreter stays usable after the fatal error the self test ends with
        if (pmmp_php_run_code("falcon_log('PHP still runs after the fatal error');", "after the self test") != 0)
            logger().error("PHP did not recover from the fatal error");
        logger().info("PHP memory: " + megabytes(pmmp_php_memory(0)) + " now, " + megabytes(pmmp_php_memory(1))
                      + " at most");
    }

    bool mStarted = false;
};

FALCON_PLUGIN(PmmpCompat)
