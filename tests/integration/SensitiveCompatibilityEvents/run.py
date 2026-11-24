#!/usr/bin/env python3
"""Exercise gameplay events against a running Falcon and installed pmmp-compat."""

import argparse
from pathlib import Path
import shutil
import subprocess
import tempfile
import time

MARKER = "[SensitiveCompatibilityEvents] sensitive compatibility events passed"


def wait_for(server, log, marker, seconds):
    deadline = time.monotonic() + seconds
    while time.monotonic() < deadline:
        if marker in log.read_text(errors="replace"):
            return
        if server.poll() is not None:
            raise RuntimeError(f"Falcon exited with status {server.returncode}")
        time.sleep(0.1)
    raise RuntimeError(f"Timed out waiting for {marker!r}")


def run(server_bin, shim, client_bin, port):
    work = Path(tempfile.mkdtemp(prefix="pmmp-sensitive-events."))
    print(f"Logs are in {work}", flush=True)
    fixture = Path(__file__).resolve().parent
    plugin = work / "plugins" / "pmmp-compat"
    shutil.copytree(shim, plugin)
    target = plugin / "plugins" / "SensitiveCompatibilityEvents"
    target.mkdir(parents=True)
    shutil.copytree(fixture / "src", target / "src")
    shutil.copy(fixture / "plugin.yml", target)
    (work / "server.properties").write_text(
        f"server-port={port}\nserver-portv6={port + 1}\n"
        "transport=nethernet\nonline-mode=false\nallow-unofficial-clients=true\n"
        "view-distance=6\nspawn-protection=0\n"
    )
    log = work / "captured.log"
    with log.open("wb") as output, subprocess.Popen(
        [server_bin, "--no-wizard"], cwd=work, stdin=subprocess.PIPE, stdout=output,
        stderr=subprocess.STDOUT, text=True,
    ) as server:
        try:
            wait_for(server, log, "Server started.", 45)
            subprocess.run([client_bin, str(port)], cwd=work, check=True, timeout=50)
            wait_for(server, log, MARKER, 8)
            if "Sensitive event fixture failed" in log.read_text(errors="replace"):
                raise RuntimeError("A fixture assertion failed")
            print(MARKER)
        finally:
            if server.poll() is None:
                server.stdin.write("stop\n")
                server.stdin.close()
                try:
                    if server.wait(timeout=20) != 0:
                        raise RuntimeError("Falcon stopped with an error")
                except subprocess.TimeoutExpired:
                    server.kill()
                    server.wait()
                    raise


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("server", type=Path)
    parser.add_argument("shim", type=Path)
    parser.add_argument("client", type=Path)
    parser.add_argument("--port", type=int, default=19584)
    args = parser.parse_args()
    try:
        run(args.server.resolve(), args.shim.resolve(), args.client.resolve(), args.port)
    except (OSError, RuntimeError, subprocess.CalledProcessError, subprocess.TimeoutExpired) as error:
        parser.exit(1, f"{error}\n")
