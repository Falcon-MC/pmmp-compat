import socket
import struct
import sys
import time


def query(port):
    endpoint = ("127.0.0.1", port)
    session = b"QE01"
    deadline = time.monotonic() + 40

    with socket.socket(socket.AF_INET, socket.SOCK_DGRAM) as client:
        client.connect(endpoint)
        client.settimeout(0.25)
        while time.monotonic() < deadline:
            client.send(b"\xfe\xfd\x09" + session)
            try:
                reply = client.recv(2048)
            except (socket.timeout, ConnectionRefusedError, ConnectionResetError):
                continue
            break
        else:
            raise RuntimeError("Query listener did not answer a challenge")

        assert reply[:5] == b"\x09" + session and reply[-1:] == b"\0", reply
        token = struct.pack(">i", int(reply[5:-1]))
        client.settimeout(1.0)
        request = b"\xfe\xfd\x00" + session + token
        client.send(request)
        assert client.recv(2048).startswith(b"\x00" + session)

        with socket.socket(socket.AF_INET, socket.SOCK_DGRAM) as different_endpoint:
            different_endpoint.connect(endpoint)
            different_endpoint.settimeout(0.25)
            different_endpoint.send(request)
            try:
                different_endpoint.recv(2048)
            except socket.timeout:
                pass
            else:
                raise AssertionError("A different endpoint reused the challenge")

        for _ in range(20):
            client.send(request + b"\0" * 4)
            reply = client.recv(4096)
            if reply.startswith(b"\x00" + session) and b"fixture\0yes\0" in reply:
                return
            time.sleep(0.1)

        raise AssertionError("Full query reply lacked the regenerated plugin data")


if __name__ == "__main__":
    query(int(sys.argv[1]))
    print("Query challenge, short stats and plugin-modified full stats passed")
