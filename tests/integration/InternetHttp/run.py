#!/usr/bin/env python3
"""Run the embedded Internet API against a loopback HTTP service."""

import argparse
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import os
from pathlib import Path
import shutil
import socket
import subprocess
import sys
import tempfile
from threading import Thread


class Handler(BaseHTTPRequestHandler):
    def respond(self, status, body, headers=()):
        self.send_response(status)
        for name, value in headers:
            self.send_header(name, value)
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_GET(self):
        if self.path == "/redirect":
            self.respond(302, b"", [("Location", "/echo")])
        elif self.path == "/echo":
            self.respond(200, self.headers.get("X-Probe", "").encode(), [("X-Fixture", "response")])
        elif self.path == "/close":
            self.connection.shutdown(socket.SHUT_RDWR)
            self.close_connection = True
        else:
            self.respond(404, b"missing")

    def do_POST(self):
        body = self.rfile.read(int(self.headers["Content-Length"]))
        self.respond(201, body, [("X-Content-Type", self.headers["Content-Type"])])

    def log_message(self, *args):
        pass


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("server", type=Path)
    parser.add_argument("plugin", type=Path)
    parser.add_argument("--port", type=int, default=19750)
    args = parser.parse_args()
    fixture = Path(__file__).resolve().parent
    runner = fixture.parents[2] / "scripts" / "run-test-server.py"
    with tempfile.TemporaryDirectory(prefix="pmmp-http-") as temporary:
        work = Path(temporary)
        bridge = work / "plugins" / "pmmp-compat"
        shutil.copytree(args.plugin, bridge)
        plugin = bridge / "plugins" / "InternetHttp"
        shutil.copytree(fixture / "src", plugin / "src")
        shutil.copy2(fixture / "plugin.yml", plugin)
        (work / "server.properties").write_text(
            f"server-port={args.port}\nserver-portv6={args.port + 1}\nonline-mode=false\n"
        )
        log = work / "captured.log"
        with ThreadingHTTPServer(("127.0.0.1", 0), Handler) as endpoint:
            http = Thread(target=endpoint.serve_forever)
            http.start()
            try:
                result = subprocess.run(
                    [sys.executable, str(runner), str(args.server), str(work), str(log), "--seconds", "1"],
                    env={**os.environ, "PMMP_COMPAT_HTTP_TEST_ORIGIN": f"http://127.0.0.1:{endpoint.server_port}"},
                )
            finally:
                endpoint.shutdown()
                http.join()
        output = log.read_text(errors="replace")
        marker = "[InternetHttp] HTTP compatibility passed"
        if result.returncode or marker not in output:
            print(output)
            return 1
        print(marker)
        return 0


if __name__ == "__main__":
    sys.exit(main())
