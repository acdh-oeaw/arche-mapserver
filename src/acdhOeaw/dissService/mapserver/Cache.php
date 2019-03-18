<?php

/*
 * The MIT License
 *
 * Copyright 2019 zozlak.
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

/**
 * Map cache.
 *
 * @author zozlak
 */
class Cache {

    private $dir;
    private $keepAlive;
    private $pdo;

    /**
     * 
     * @param string $dbConfig
     * @param string $cacheDir
     * @param int $keepAlive
     */
    public function __construct(string $dbConfig, string $cacheDir,
                                int $keepAlive) {
        $this->dir       = $cacheDir;
        $this->keepAlive = $keepAlive;

        $this->pdo = new PDO($dbConfig);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->pdo->query("
            create table if not exists maps(
                arche_id text primary key, 
                id text, 
                type text, 
                req_date timestamp, 
                check_date timesatmp,
                size int
             )
        ");
    }

    /**
     * Creates a map object.
     * 
     * If the map is not yet locally cached, caches it first.
     * 
     * If the locally cached map is outdated, fetches an up to date version.
     * @param string $archeId
     * @return \acdhOeaw\dissService\mapserver\Map
     */
    public function getMap(string $archeId): Map {
        $query = $this->pdo->prepare('
            SELECT 
                id, type, size,
                arche_id AS "archeId", 
                req_date AS "reqDate",
                check_date AS "checkDate"
            FROM maps 
            WHERE arche_id = ?
        ');
        $query->execute([$archeId]);
        $data  = $query->fetchObject();
        if ($data !== false) {
            $map = new Map($this->dir, $data);
        } else {
            $map = new Map($this->dir);
            $map->fetchFromFedora($archeId);
        }
        $map->refresh($this->keepAlive);
        $map->touch();
        $this->putMap($map);

        return $map;
    }

    /**
     * Saves locally cached map metadata into the database.
     * @param \acdhOeaw\dissService\mapserver\Map $map
     */
    public function putMap(Map $map) {
        $query = $this->pdo->prepare("
            INSERT OR REPLACE INTO maps (arche_id, id, type, req_date, check_date, size)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $query->execute([
            $map->archeId, $map->id, $map->type, $map->reqDate, $map->checkDate,
            $map->size
        ]);
    }

}
