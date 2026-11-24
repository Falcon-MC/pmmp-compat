#!/usr/bin/env python3
"""Run the original CustomItemLoader with a Bedrock protocol client on two server runs."""

import argparse
from pathlib import Path
import shutil
import subprocess
import tempfile
import time


def run(server, shim, client, archive, port):
    fixture = Path(__file__).resolve().parent
    work = Path(tempfile.mkdtemp(prefix="pmmp-custom-item."))
    print(f"Logs are in {work}", flush=True)
    plugin = work / "plugins" / "pmmp-compat"
    shutil.copytree(shim, plugin)
    plugins = plugin / "plugins"
    plugins.mkdir()
    shutil.copyfile(archive, plugins / "CustomItemLoader.phar")
    probe = plugins / "CustomItemProbe"
    probe.mkdir()
    shutil.copyfile(fixture / "plugin.yml", probe / "plugin.yml")
    shutil.copytree(fixture / "src", probe / "src")
    config = plugin / "plugin_data" / "CustomItemLoader"
    config.mkdir(parents=True)
    shutil.copyfile(fixture / "config.yml", config / "config.yml")
    (work / "server.properties").write_text(
        f"server-port={port}\nserver-portv6={port + 1}\n"
        "transport=nethernet\nonline-mode=false\nallow-unofficial-clients=true\n"
    )

    for phase, outcome in (("play", "consumed"), ("restart", "persisted")):
        log = work / f"{phase}.log"
        with log.open("wb") as output, subprocess.Popen(
            [server, "--no-wizard"], cwd=work, stdin=subprocess.PIPE,
            stdout=output, stderr=subprocess.STDOUT, text=True,
        ) as process:
            try:
                deadline = time.monotonic() + 45
                while "Server started." not in log.read_text(errors="replace"):
                    if process.poll() is not None or time.monotonic() >= deadline:
                        raise RuntimeError(f"Startup failed during {phase}")
                    time.sleep(0.1)
                subprocess.run([client, str(port), phase], cwd=work, check=True, timeout=30)
                report = log.read_text(errors="replace")
                if f"CustomItemLoader item {outcome}" not in report or "Cannot register custom item fixture:" in report:
                    raise RuntimeError(f"Custom item verification failed during {phase}")
                print(f"CustomItemLoader item {outcome}")
            finally:
                if process.poll() is None:
                    process.stdin.write("stop\n")
                    process.stdin.close()
                    try:
                        process.wait(timeout=20)
                    except subprocess.TimeoutExpired:
                        process.kill()
                        process.wait()
                        raise
            if process.returncode != 0:
                raise RuntimeError(f"Shutdown failed during {phase}")


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    for name in ("server", "shim", "client", "archive"):
        parser.add_argument(name, type=Path)
    parser.add_argument("--port", type=int, default=19557)
    args = parser.parse_args()
    try:
        run(args.server.resolve(), args.shim.resolve(), args.client.resolve(), args.archive.resolve(), args.port)
    except (OSError, RuntimeError, subprocess.CalledProcessError, subprocess.TimeoutExpired) as error:
        parser.exit(1, f"{error}\n")
