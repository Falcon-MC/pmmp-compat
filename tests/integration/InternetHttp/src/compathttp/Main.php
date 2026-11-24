<?php

declare(strict_types=1);

namespace compathttp;

use pocketmine\plugin\PluginBase;
use pocketmine\utils\Internet;

final class Main extends PluginBase{
    protected function onEnable() : void{
        $origin = getenv('PMMP_COMPAT_HTTP_TEST_ORIGIN');
        $this->check(is_string($origin), 'Fixture HTTP origin missing');
        $this->check(extension_loaded('curl'), 'cURL extension missing');

        $result = Internet::getURL($origin . '/redirect', 2, ['X-Probe: get']);
        $this->check($result?->getBody() === 'get' && $result->getCode() === 200, 'GET or redirect failed');
        $headers = $result->getHeaders();
        $this->check(($headers[0]['location'] ?? null) === '/echo'
            && ($headers[1]['x-fixture'] ?? null) === 'response', 'Redirect response headers missing');

        $body = "raw\x00payload";
        $result = Internet::postURL($origin . '/echo', $body, 2, ['Content-Type: application/octet-stream']);
        $this->check($result?->getBody() === $body && $result->getCode() === 201
            && ($result->getHeaders()[0]['x-content-type'] ?? null) === 'application/octet-stream', 'Binary POST failed');
        $result = Internet::postURL($origin . '/echo', ['item' => 'diamond'], 2);
        $this->check($result?->getCode() === 201 && str_contains($result->getBody(), "name=\"item\"\r\n\r\ndiamond")
            && str_starts_with($result->getHeaders()[0]['x-content-type'] ?? '', 'multipart/form-data;'), 'Multipart POST failed');

        $result = Internet::getURL($origin . '/missing', 2);
        $this->check($result?->getCode() === 404 && $result->getBody() === 'missing', 'HTTP error response lost');
        $result = Internet::getURL($origin . '/close', 2, [], $error);
        $this->check($result === null && is_string($error) && $error !== '', 'Transport error lost');

        $responseCode = null;
        $result = Internet::simpleCurl($origin . '/redirect', 2, [], [CURLOPT_FOLLOWLOCATION => false],
            static function(\CurlHandle $handle) use (&$responseCode) : void{
                $responseCode = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            });
        $this->check($result->getCode() === 302 && $responseCode === 302, 'cURL option or completion callback ignored');
        Internet::$online = false;
        try{
            $result = Internet::getURL($origin . '/echo', 2, [], $error);
            $this->check($result === null && str_contains($error, 'offline'), 'Offline guard ignored');
        }finally{
            Internet::$online = true;
        }
        $this->getLogger()->info('HTTP compatibility passed');
    }

    private function check(bool $condition, string $message) : void{
        if(!$condition){
            throw new \RuntimeException($message);
        }
    }
}
