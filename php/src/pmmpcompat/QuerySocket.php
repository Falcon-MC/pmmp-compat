<?php

declare(strict_types=1);

namespace pmmpcompat;

use pocketmine\network\query\QueryInfo;

final class QuerySocket{
    /** @var resource */
    private $socket;
    private string $secret;

    public function __construct(string $host, int $port){
        if($port < 1 || $port > 65535 || filter_var($host, FILTER_VALIDATE_IP) === false){
            throw new \InvalidArgumentException("Invalid query endpoint");
        }
        $address = str_contains($host, ':') ? "[$host]" : $host;
        $socket = @stream_socket_server("udp://$address:$port", $errno, $error, STREAM_SERVER_BIND);
        if($socket === false){
            throw new \RuntimeException("Could not bind query socket $address:$port: $error");
        }
        stream_set_blocking($socket, false);
        $this->socket = $socket;
        $this->secret = random_bytes(32);
    }

    public function tick(QueryInfo $query) : void{
        $window = intdiv(time(), 30);
        for($count = 0; $count < 32; ++$count){
            $peer = '';
            $packet = @stream_socket_recvfrom($this->socket, 64, 0, $peer);
            if($packet === false || $peer === ''){
                return;
            }
            $reply = $this->answer($packet, $peer, $window, $query);
            if($reply !== null){
                @stream_socket_sendto($this->socket, $reply, 0, $peer);
            }
        }
    }

    private function answer(string $packet, string $peer, int $window, QueryInfo $query) : ?string{
        $length = strlen($packet);
        if($length < 7 || substr($packet, 0, 2) !== "\xfe\xfd"){
            return null;
        }
        $type = $packet[2];
        $header = substr($packet, 2, 5);
        if($type === "\x09" && $length === 7){
            $token = unpack('N', $this->token($peer, $window))[1];
            $token = $token > 0x7fffffff ? $token - 0x100000000 : $token;
            return $header . $token . "\x00";
        }
        if($type !== "\x00" || ($length !== 11 && $length !== 15)){
            return null;
        }
        $token = substr($packet, 7, 4);
        if(!hash_equals($this->token($peer, $window), $token)
            && !hash_equals($this->token($peer, $window - 1), $token)){
            return null;
        }
        $body = $length === 15 ? $query->getLongQuery() : $query->getShortQuery();
        // keep replies below one IPv4 UDP datagram
        return strlen($body) <= 65502 ? $header . $body : null;
    }

    private function token(string $peer, int $window) : string{
        return substr(hash_hmac('sha256', $window . ':' . $peer, $this->secret, true), 0, 4);
    }

    public function close() : void{
        fclose($this->socket);
    }
}
