# Puts together the thread-safe PHP the plugin embeds on Windows, in one prefix: PocketMine-MP's own PHP build, which
# has pmmpthread, igbinary, sqlite3, PDO and mysqli, and the official build of the same version for the embed SAPI's
# library and the headers: php8ts.dll, ext/, php8embed.lib, lib/php8ts.lib and include/.
param([string]$Prefix = "$PSScriptRoot/../.toolchain/php")
$ErrorActionPreference = "Stop"
$version = "8.3.29"
$files = @(
    @{ Name = "PHP-8.3-Windows-x64-PM5.zip"; Sha256 = "d688190524aa2ada9d29fb18e2143a9f4fa484b765ae0ef67dcd40371aaf6eaa";
       Url = "https://github.com/pmmp/PHP-Binaries/releases/download/pm5-php-8.3-latest/PHP-8.3-Windows-x64-PM5.zip" },
    @{ Name = "php-$version-Win32-vs16-x64.zip"; Sha256 = "ca23b36638a42616f9ed93502679ff46e2109da841028ba916f3e4764149ecbe";
       Url = "https://downloads.php.net/~windows/releases/archives/php-$version-Win32-vs16-x64.zip" },
    @{ Name = "php-devel-pack-$version-Win32-vs16-x64.zip"; Sha256 = "50d97dcc702e6cbece6a433361a2819e3780e21342f83e2f150884a5262e7a35";
       Url = "https://downloads.php.net/~windows/releases/archives/php-devel-pack-$version-Win32-vs16-x64.zip" }
)
New-Item -ItemType Directory -Force -Path $Prefix | Out-Null
$work = Join-Path $env:RUNNER_TEMP "php-windows"
if (-not $env:RUNNER_TEMP) { $work = Join-Path ([System.IO.Path]::GetTempPath()) "php-windows" }
New-Item -ItemType Directory -Force -Path $work | Out-Null
foreach ($file in $files) {
    $archive = Join-Path $work $file.Name
    Invoke-WebRequest -Uri $file.Url -OutFile $archive -UserAgent "pmmp-compat-build"
    $hash = (Get-FileHash $archive -Algorithm SHA256).Hash.ToLower()
    if ($hash -ne $file.Sha256) { throw "$($file.Name): sha256 $hash, expected $($file.Sha256)" }
}

# PocketMine-MP's PHP: the runtime and its extensions
$pm = Join-Path $work "pm"
Expand-Archive -Force (Join-Path $work $files[0].Name) $pm
$bin = Join-Path $pm "bin/php"
Copy-Item -Force (Join-Path $bin "*.dll") $Prefix
New-Item -ItemType Directory -Force -Path (Join-Path $Prefix "ext") | Out-Null
Copy-Item -Force (Join-Path $bin "ext/*.dll") (Join-Path $Prefix "ext")
New-Item -ItemType Directory -Force -Path (Join-Path $Prefix "lib") | Out-Null
Copy-Item -Force (Join-Path $bin "dev/php8ts.lib") (Join-Path $Prefix "lib")

# the official build of the same version: the embed SAPI, which PocketMine-MP's does not have, and the headers
$official = Join-Path $work "official"
Expand-Archive -Force (Join-Path $work $files[1].Name) $official
Copy-Item -Force (Join-Path $official "php8embed.lib") $Prefix
$devel = Join-Path $work "devel"
Expand-Archive -Force (Join-Path $work $files[2].Name) $devel
$pack = Get-ChildItem $devel -Directory | Select-Object -First 1
Copy-Item -Recurse -Force (Join-Path $pack.FullName "include") $Prefix
Write-Host "PHP $version for Windows put together in $Prefix"
