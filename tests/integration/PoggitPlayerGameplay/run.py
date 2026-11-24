#!/usr/bin/env python3
"""Test native gameplay with the original BlazinFly and XGTShowPlayerHP archives."""

import argparse
from pathlib import Path
import shutil
import subprocess
import tempfile
import time


def run(server, shim, client, archive, port):
    work = Path(tempfile.mkdtemp(prefix="pmmp-poggit-gameplay-"))
    print(f"Logs are in {work}", flush=True)
    fixture = Path(__file__).resolve().parent
    bridge = work / "plugins" / "pmmp-compat"
    shutil.copytree(shim, bridge)
    plugins = bridge / "plugins"
    target = plugins / "PoggitPlayerGameplay"
    shutil.copytree(fixture / "src", target / "src")
    shutil.copy2(fixture / "plugin.yml", target)
    for original in (
        "iiFlamiinBlaze/BlazinFly/1.10.0/BlazinFly_1.10.0.phar",
        "XGDavid/XGTShowPlayerHP/2.0.0/XGTShowPlayerHP_2.0.0.phar",
    ):
        shutil.copy2(archive / original, plugins)
    metrics = bridge / "plugin_data" / "XGTShowPlayerHP" / "bStats"
    metrics.mkdir(parents=True)
    (metrics / "config.yml").write_text("enabled: false\nplugin-id: 28722\n")
    (work / "server.properties").write_text(
        f"server-port={port}\nserver-portv6={port + 1}\n"
        "transport=nethernet\nonline-mode=false\nallow-unofficial-clients=true\n"
        "gamemode=survival\npvp=true\nview-distance=6\nspawn-protection=0\n"
        "level-seed=1896762365222302013\n"
    )
    log = work / "captured.log"
    with log.open("wb") as output, subprocess.Popen(
        [server, "--no-wizard"], cwd=work, stdin=subprocess.PIPE, stdout=output,
        stderr=subprocess.STDOUT, text=True,
    ) as process:
        try:
            deadline = time.monotonic() + 45
            while "Server started." not in log.read_text(errors="replace"):
                if process.poll() is not None or time.monotonic() >= deadline:
                    raise RuntimeError("Falcon failed to start")
                time.sleep(0.1)
            subprocess.run([client, str(port)], check=True, timeout=45)
        finally:
            if process.poll() is None:
                process.stdin.write("stop\n")
                process.stdin.close()
                try:
                    if process.wait(timeout=15) != 0:
                        raise RuntimeError("Falcon shutdown failed")
                except subprocess.TimeoutExpired:
                    process.kill()
                    process.wait()
                    raise
    contents = log.read_text(errors="replace")
    for event in ("PluginEnableEvent", "PluginDisableEvent"):
        if f"[PoggitPlayerGameplay] {event} passed" not in contents:
            raise RuntimeError(f"Missing {event} marker in {log}")


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("server", type=Path)
    parser.add_argument("shim", type=Path)
    parser.add_argument("client", type=Path)
    parser.add_argument("archive", type=Path)
    parser.add_argument("--port", type=int, default=19754)
    args = parser.parse_args()
    try:
        run(args.server.resolve(), args.shim.resolve(), args.client.resolve(), args.archive.resolve(), args.port)
    except (OSError, RuntimeError, subprocess.CalledProcessError, subprocess.TimeoutExpired) as error:
        parser.exit(1, f"{error}\n")
