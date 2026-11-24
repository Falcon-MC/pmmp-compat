#!/usr/bin/env python3
"""Wait for a test server, exercise it, and require a clean exit."""

import argparse
import os
from pathlib import Path
import subprocess
import time


def run(server, directory, log, seconds, commands, client=None, client_port=None):
    environment = dict(os.environ)
    if client is None:
        environment["PMMP_COMPAT_SELFTEST"] = "1"
    else:
        environment.pop("PMMP_COMPAT_SELFTEST", None)
    with log.open("wb") as output:
        with subprocess.Popen(
            [str(server), "--no-wizard"],
            cwd=directory,
            stdin=subprocess.PIPE,
            stdout=output,
            stderr=subprocess.STDOUT,
            env=environment,
            text=True,
        ) as process:
            try:
                deadline = time.monotonic() + 45
                with log.open("rb") as reader:
                    tail = b""
                    while b"Server started." not in tail:
                        tail = (tail[-128:] + reader.read())
                        if process.poll() is not None:
                            raise RuntimeError(f"Server exited during startup ({process.returncode})")
                        if time.monotonic() >= deadline:
                            raise RuntimeError("Server startup timed out")
                        if b"Server started." not in tail:
                            time.sleep(0.1)

                if client is not None:
                    subprocess.run([str(client), str(client_port)], cwd=directory, check=True, timeout=seconds)
                else:
                    try:
                        process.wait(timeout=seconds)
                    except subprocess.TimeoutExpired:
                        pass
                    else:
                        raise RuntimeError(f"Server exited before stop ({process.returncode})")

                # Falcon polls stdin between reads, so write each command separately.
                for command in commands:
                    process.stdin.write(f"{command}\n")
                    process.stdin.flush()
                    time.sleep(0.25)
                process.stdin.write("stop\n")
                # EOF releases Falcon's detached console reader, which can block process exit on Windows.
                process.stdin.close()
                if process.wait(timeout=15) != 0:
                    raise RuntimeError(f"Server stop failed ({process.returncode})")
            finally:
                if process.poll() is None:
                    process.kill()
                    process.wait()


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("server", type=Path)
    parser.add_argument("directory", type=Path)
    parser.add_argument("log", type=Path)
    parser.add_argument("--seconds", type=float, default=7)
    parser.add_argument("--command", action="append", default=[])
    parser.add_argument("--client", type=Path)
    parser.add_argument("--client-port", type=int)
    args = parser.parse_args()
    if (args.client is None) != (args.client_port is None):
        parser.error("--client and --client-port must be used together")
    try:
        run(args.server.resolve(), args.directory, args.log, args.seconds, args.command,
            args.client.resolve() if args.client is not None else None, args.client_port)
    except (OSError, RuntimeError, subprocess.TimeoutExpired, subprocess.CalledProcessError) as error:
        parser.exit(1, f"{error}. See {args.log}\n")
