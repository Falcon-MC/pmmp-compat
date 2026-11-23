#!/usr/bin/env python3
"""Wait for a test server, exercise it, and require a clean exit."""

import argparse
import os
from pathlib import Path
import subprocess
import time


def run(server, directory, log, seconds, commands):
    with log.open("wb") as output:
        with subprocess.Popen(
            [str(server), "--no-wizard"],
            cwd=directory,
            stdin=subprocess.PIPE,
            stdout=output,
            stderr=subprocess.STDOUT,
            env={**os.environ, "PMMP_COMPAT_SELFTEST": "1"},
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
    args = parser.parse_args()
    try:
        run(args.server.resolve(), args.directory, args.log, args.seconds, args.command)
    except (OSError, RuntimeError, subprocess.TimeoutExpired) as error:
        parser.exit(1, f"{error}. See {args.log}\n")
