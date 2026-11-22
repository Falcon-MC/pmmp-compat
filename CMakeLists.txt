cmake_minimum_required(VERSION 3.16)
project(PmmpCompat C CXX)

# pmmp's ext-encoding, built into the plugin for BedrockProtocol, is C++20
set(CMAKE_CXX_STANDARD 20)
set(CMAKE_CXX_STANDARD_REQUIRED ON)
set(CMAKE_C_STANDARD 11)

include(FetchContent)
FetchContent_Declare(
    falcon_plugin_api
    GIT_REPOSITORY https://github.com/Falcon-MC/PluginAPI.git
    GIT_TAG main
    GIT_SHALLOW TRUE
)
set(FALCON_PLUGIN_API_BUILD_EXAMPLES OFF CACHE BOOL "" FORCE)
FetchContent_MakeAvailable(falcon_plugin_api)

# the PHP scripts/build-php.sh installs; on Windows, the official thread-safe build and its development pack
# extracted by scripts/fetch-php-windows.ps1
set(PMMP_PHP_PREFIX "${CMAKE_SOURCE_DIR}/.toolchain/php" CACHE PATH "Prefix of the embedded PHP build")
if (WIN32)
    find_library(PMMP_LIBPHP NAMES php8embed PATHS "${PMMP_PHP_PREFIX}" "${PMMP_PHP_PREFIX}/lib" NO_DEFAULT_PATH REQUIRED)
    find_library(PMMP_LIBPHP_TS NAMES php8ts PATHS "${PMMP_PHP_PREFIX}/lib" "${PMMP_PHP_PREFIX}/dev" NO_DEFAULT_PATH REQUIRED)
    set(PMMP_PHP_INCLUDE "${PMMP_PHP_PREFIX}/include")
else ()
    find_library(PMMP_LIBPHP NAMES libphp.a php PATHS "${PMMP_PHP_PREFIX}/lib" NO_DEFAULT_PATH REQUIRED)
    set(PMMP_PHP_INCLUDE "${PMMP_PHP_PREFIX}/include/php")
endif ()

falcon_add_plugin(PmmpCompat
    src/PmmpCompat.cpp
    src/php_bridge.c
    src/php_world.c
    src/php_living.c
    src/php_server.c
    src/php_watchdog.c
    third_party/ext-encoding/encoding.cpp
    third_party/ext-encoding/classes/ByteBufferReader.cpp
    third_party/ext-encoding/classes/ByteBufferWriter.cpp
    third_party/ext-encoding/classes/Types.cpp)
target_include_directories(PmmpCompat PRIVATE
    "${CMAKE_SOURCE_DIR}/third_party/ext-encoding"
    "${CMAKE_SOURCE_DIR}/src"
    "${PMMP_PHP_INCLUDE}"
    "${PMMP_PHP_INCLUDE}/main"
    "${PMMP_PHP_INCLUDE}/TSRM"
    "${PMMP_PHP_INCLUDE}/Zend"
    "${PMMP_PHP_INCLUDE}/ext"
    "${PMMP_PHP_INCLUDE}/sapi/embed")
target_link_libraries(PmmpCompat PRIVATE "${PMMP_LIBPHP}")

# what php-config --libs lists for the build
if (WIN32)
    target_link_libraries(PmmpCompat PRIVATE "${PMMP_LIBPHP_TS}")
    target_compile_definitions(PmmpCompat PRIVATE ZEND_WIN32 PHP_WIN32 ZTS=1 ZEND_DEBUG=0 WIN32 _WINDOWS)
elseif (UNIX)
    # the libraries the build links against, openssl's and gmp's archives among them on macOS
    execute_process(COMMAND "${PMMP_PHP_PREFIX}/bin/php-config" --libs
                    OUTPUT_VARIABLE PMMP_PHP_LIBS OUTPUT_STRIP_TRAILING_WHITESPACE COMMAND_ERROR_IS_FATAL ANY)
    execute_process(COMMAND "${PMMP_PHP_PREFIX}/bin/php-config" --ldflags
                    OUTPUT_VARIABLE PMMP_PHP_LDFLAGS OUTPUT_STRIP_TRAILING_WHITESPACE COMMAND_ERROR_IS_FATAL ANY)
    if (EXISTS "${PMMP_PHP_PREFIX}/lib/pmmp-extra-libs.txt")
        file(READ "${PMMP_PHP_PREFIX}/lib/pmmp-extra-libs.txt" PMMP_PHP_EXTRA_LIBS)
        string(STRIP "${PMMP_PHP_EXTRA_LIBS}" PMMP_PHP_EXTRA_LIBS)
        string(PREPEND PMMP_PHP_LIBS "${PMMP_PHP_EXTRA_LIBS} ")
    endif ()
    separate_arguments(PMMP_PHP_LIBS UNIX_COMMAND "${PMMP_PHP_LIBS}")
    separate_arguments(PMMP_PHP_LDFLAGS UNIX_COMMAND "${PMMP_PHP_LDFLAGS}")
    target_link_options(PmmpCompat PRIVATE ${PMMP_PHP_LDFLAGS})
    target_link_libraries(PmmpCompat PRIVATE ${PMMP_PHP_LIBS})
endif ()

# the plugin folder a server takes: cmake --install build --prefix dist gives dist/pmmp-compat
install(TARGETS PmmpCompat LIBRARY DESTINATION pmmp-compat RUNTIME DESTINATION pmmp-compat)
install(FILES plugin.json DESTINATION pmmp-compat)
install(DIRECTORY php DESTINATION pmmp-compat)
if (WIN32)
    install(FILES "${PMMP_PHP_PREFIX}/php8ts.dll" DESTINATION pmmp-compat)
    # PocketMine-MP's build keeps these outside php8ts.dll: the plugin loads them at startup
    install(FILES "${PMMP_PHP_PREFIX}/ext/php_pmmpthread.dll" "${PMMP_PHP_PREFIX}/ext/php_openssl.dll"
            "${PMMP_PHP_PREFIX}/ext/php_igbinary.dll" "${PMMP_PHP_PREFIX}/ext/php_mysqli.dll"
            "${PMMP_PHP_PREFIX}/ext/php_sqlite3.dll"
            DESTINATION pmmp-compat/ext)
    # what php8ts.dll and those load; the plugin points Windows at its own folder for them
    install(FILES "${PMMP_PHP_PREFIX}/libssh2.dll" "${PMMP_PHP_PREFIX}/nghttp2.dll"
            "${PMMP_PHP_PREFIX}/libcrypto-3-x64.dll" "${PMMP_PHP_PREFIX}/libssl-3-x64.dll"
            "${PMMP_PHP_PREFIX}/libsqlite3.dll" "${PMMP_PHP_PREFIX}/pthreadvc3.dll" "${PMMP_PHP_PREFIX}/yaml.dll"
            "${PMMP_PHP_PREFIX}/deflate.dll"
            DESTINATION pmmp-compat OPTIONAL)
endif ()
