# Downloads the official thread-safe PHP build for Windows and its development pack, checks them and extracts
# them into one prefix: php8ts.dll, php8embed.lib, lib/php8ts.lib and include/.
param([string]$Prefix = "$PSScriptRoot/../.toolchain/php")
$ErrorActionPreference = "Stop"
$version = "8.3.37"
$base = "https://downloads.php.net/~windows/releases"
$files = @(
    @{ Name = "php-$version-Win32-vs16-x64.zip"; Sha256 = "07984bdcfd4391888954c2e73b1cf2c0d403cfa5fd8cb32ad60ddcd854c6b7de" },
    @{ Name = "php-devel-pack-$version-Win32-vs16-x64.zip"; Sha256 = "8f75d8a9be8eb75701951eb91b420664e1372c862c6212129d59d58480250901" }
)
New-Item -ItemType Directory -Force -Path $Prefix | Out-Null
$work = Join-Path $env:RUNNER_TEMP "php-windows"
if (-not $env:RUNNER_TEMP) { $work = Join-Path ([System.IO.Path]::GetTempPath()) "php-windows" }
New-Item -ItemType Directory -Force -Path $work | Out-Null
foreach ($file in $files) {
    $archive = Join-Path $work $file.Name
    Invoke-WebRequest -Uri "$base/$($file.Name)" -OutFile $archive -UserAgent "pmmp-compat-build"
    $hash = (Get-FileHash $archive -Algorithm SHA256).Hash.ToLower()
    if ($hash -ne $file.Sha256) { throw "$($file.Name): sha256 $hash, expected $($file.Sha256)" }
}
Expand-Archive -Force (Join-Path $work $files[0].Name) $Prefix
$devel = Join-Path $work "devel"
Expand-Archive -Force (Join-Path $work $files[1].Name) $devel
$pack = Get-ChildItem $devel -Directory | Select-Object -First 1
Copy-Item -Recurse -Force (Join-Path $pack.FullName "include") $Prefix
Copy-Item -Recurse -Force (Join-Path $pack.FullName "lib") $Prefix
Write-Host "PHP $version for Windows extracted in $Prefix"
