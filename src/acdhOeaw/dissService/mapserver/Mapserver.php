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
use acdhOeaw\dissService\mapserver\Cache;
use acdhOeaw\dissService\mapserver\Map;

/**
 * Description of Mapserver
 *
 * @author zozlak
 */
class Mapserver {

    static $skipResponseHeaders = ['connection', 'keep-alive', 'proxy-authenticate',
        'proxy-authorization', 'te', 'trailer', 'transfer-encoding', 'upgrade', 'host'];
    protected $mapserverId;
    private object $config;

    public function __construct(object $config) {
        $this->config = $config;
    }

    public function serve() {
        $this->checkId();

        // initialize map templates and cache
        Map::init($this->getConfig('mapTmplRaster'), $this->getConfig('mapTmplVector'), $this->getConfig('mapServerBase'));
        $cache = new Cache($this->getConfig('db'), $this->getConfig('cacheDir'), $this->getConfig('cacheKeepAlive'));

        // fetch the map and prepare the request URL
        $map = $cache->getMap($this->mapserverId);
        $url = preg_replace('|&$|', '', $map->getUrl());
        foreach ($_GET as $k => $v) {
            $url .= '&' . urlencode($k) . '=' . urlencode($v);
        }

        // proxy the request
        $output  = fopen('php://output', 'w');
        $options = [
            'sink'       => $output,
            'on_headers' => function (Response $r) {
                header('HTTP/1.1 ' . $r->getStatusCode() . ' ' . $r->getReasonPhrase());
                foreach ($r->getHeaders() as $name => $values) {
                    if (in_array(strtolower($name), self::$skipResponseHeaders)) {
                        continue;
                    }
                    foreach ($values as $value) {
                        header(sprintf('%s: %s', $name, $value), false);
                    }
                }
            },
            'verify' => false,
        ];
        $client  = new Client($options);
        $request = new Request('GET', $url);
        try {
            $client->send($request);
        } catch (RequestException $e) {
            
        }
        if (is_resource($output)) {
            fclose($output);
        }
    }

    /**
     * Make sure the map id is a fully qualified ARCHE URI
     */
    private function checkId() {
        $this->mapserverId = urldecode(urldecode($this->mapserverId));
        if (!preg_match('|^https?://|', $this->mapserverId)) {
            $this->mapserverId = $this->getConfig('archeIdPrefix') . $this->mapserverId;
        }
    }
}
