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
elseif (APPLE)
    target_link_libraries(PmmpCompat PRIVATE resolv network m pthread z curl)
elseif (UNIX)
    target_link_libraries(PmmpCompat PRIVATE resolv m pthread z dl curl)
endif ()

# the plugin folder a server takes: cmake --install build --prefix dist gives dist/pmmp-compat
install(TARGETS PmmpCompat LIBRARY DESTINATION pmmp-compat RUNTIME DESTINATION pmmp-compat)
install(FILES plugin.json DESTINATION pmmp-compat)
install(DIRECTORY php DESTINATION pmmp-compat)
if (WIN32)
    install(FILES "${PMMP_PHP_PREFIX}/php8ts.dll" DESTINATION pmmp-compat)
    # the official build ships mbstring as a dll the plugin loads at startup
    install(FILES "${PMMP_PHP_PREFIX}/ext/php_mbstring.dll" "${PMMP_PHP_PREFIX}/ext/php_curl.dll"
            DESTINATION pmmp-compat/ext)
    # what php_curl.dll loads; the plugin points Windows at its own folder for them
    install(FILES "${PMMP_PHP_PREFIX}/libssh2.dll" "${PMMP_PHP_PREFIX}/nghttp2.dll"
            "${PMMP_PHP_PREFIX}/libcrypto-3-x64.dll" "${PMMP_PHP_PREFIX}/libssl-3-x64.dll"
            DESTINATION pmmp-compat OPTIONAL)
endif ()
