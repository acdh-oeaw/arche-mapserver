<?php

/**
 * The MIT License
 *
 * Copyright 2019 Austrian Centre for Digital Humanities.
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 * 
 */

namespace acdhOeaw\dissService\mapserver;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;
use acdhOeaw\dissService\mapserver\Cache;
use acdhOeaw\dissService\mapserver\Map;

/**
 * Description of Mapserver
 *
 * @author zozlak
 */
class Mapserver {

    /**
     * 
     * @var array<string>
     */
    static array $skipResponseHeaders = [
        'connection', 'keep-alive', 'proxy-authenticate', 'proxy-authorization',
        'te', 'trailer', 'transfer-encoding', 'upgrade', 'host'
    ];
    private object $config;
    private LoggerInterface $log;
    private Cache $cache;

    public function __construct(object $config, LoggerInterface $log) {
        $this->config = $config;
        $this->log    = $log;
        $this->cache  = new Cache($this->config->cache->db, $this->config->cache->dir, $this->config->cache->keepAlive, $this->log);
    }

    public function serve(string $url): void {
        // fetch the map and prepare the request URL
        $map = $this->cache->getMap($url);
        $url = preg_replace('|&$|', '', $map->getUrl());
        foreach ($_GET as $k => $v) {
            if ($k === 'id') {
                continue;
            }
            $url .= '&' . urlencode($k) . '=' . urlencode($v);
        }

        $this->log->info("Redirecting to $url");
        http_response_code(302);
        header('Location: ' . $url);
    }
}
