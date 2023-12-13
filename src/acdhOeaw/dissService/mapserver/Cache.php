<?php

/*
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
 */

namespace acdhOeaw\dissService\mapserver;

use PDO;
use Psr\Log\LoggerInterface;

/**
 * Map cache.
 *
 * @author zozlak
 */
class Cache {

    private string $dir;
    private int $keepAlive;
    private PDO $pdo;
    private LoggerInterface $log;

    /**
     * 
     * @param string $dbConfig
     * @param string $cacheDir
     * @param int $keepAlive
     */
    public function __construct(string $dbConfig, string $cacheDir,
                                int $keepAlive, LoggerInterface $log) {
        $this->dir       = $cacheDir;
        $this->keepAlive = $keepAlive;
        $this->log       = $log;

        $this->pdo = new PDO($dbConfig);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->pdo->query("
            create table if not exists maps(
                url text,
                type text, 
                size int,
                req_date timestamp, 
                check_date timesatmp
             )
        ");
    }

    /**
     * Creates a map object.
     * 
     * If the map is not yet locally cached, caches it first.
     * 
     * If the locally cached map is outdated, fetches an up to date version.
     * @param string $url
     * @return \acdhOeaw\dissService\mapserver\Map
     */
    public function getMap(string $url): Map {
        $this->log->info("Handling $url");
        $query = $this->pdo->prepare('
            SELECT 
                type, size,
                req_date AS "reqDate",
                check_date AS "checkDate"
            FROM maps 
            WHERE url = ?
        ');
        $query->execute([$url]);
        $data  = $query->fetchObject();
        if ($data !== false) {
            $this->log->info("Requested map found in cache");
            $map = new Map($this->dir, $url, $data);
        } else {
            $this->log->info("Requested map not in cache - fetching");
            $map = new Map($this->dir, $url);
            $map->fetch();
            $this->log->info("Metadata fetched: $map->type, $map->remoteDate");
        }
        $map->refresh($this->keepAlive, $this->log);
        $map->touch();
        $this->putMap($map);

        return $map;
    }

    /**
     * Saves locally cached map metadata into the database.
     * @param \acdhOeaw\dissService\mapserver\Map $map
     */
    public function putMap(Map $map): void {
        $query = $this->pdo->prepare("
            INSERT OR REPLACE INTO maps (url, type, size, req_date, check_date)
            VALUES (?, ?, ?, ?, ?)
        ");
        $query->execute([
            $map->url, $map->type, $map->size, date('Y-m-d H:i:s'), $map->checkDate
        ]);
    }
}
