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

use DateTime;
use RuntimeException;
use SplFileInfo;
use stdClass;
use EasyRdf\Graph;
use GuzzleHttp\Client;
use zozlak\util\UUID;

/**
 * Represents a raster or vector map to be stored in a cache.
 *
 * @author zozlak
 */
class Map {

    static private $mimeTypes = [
        'raster' => ['image/tiff', 'image/jpeg', 'image/png', 'image/jp2'],
        'vector' => ['application/vnd.geo+json', 'application/json', 'application/vnd.google-earth.kml+xml',
            'application/gml+xml'],
    ];
    static private $templates;
    static private $baseUrl;

    /**
     * Initializes the class by setting up the templates.
     * @param string $rasterTmplFile mapserver's map file template for raster maps
     * @param string $vectorTmplFile mapserver's map file template for vector maps
     * @param string $baseUrl mapserver's base URL 
     *   (see https://mapserver.org/de/ogc/wms_server.html#setup-a-mapfile-for-your-wms 
     *   - the wms_onlineresource config parameter description)
     */
    static public function init(string $rasterTmplFile, string $vectorTmplFile,
                                string $baseUrl) {
        self::$templates = [
            'raster' => $rasterTmplFile,
            'vector' => $vectorTmplFile,
        ];
        self::$baseUrl   = $baseUrl;
    }

    public $archeId;
    public $id;
    public $type;
    public $localDate;
    public $remoteDate;
    public $reqDate;
    public $checkDate = '1900-01-01 00:00:00';
    public $size;
    public $storageDir;
    private $remoteFileInfo;

    /**
     * 
     * @param string $storageDir directory in which cached maps are stored
     * @param stdClass|null $data initial map object property values
     */
    public function __construct(string $storageDir, ?stdClass $data = null) {
        $this->storageDir = $storageDir;

        foreach ((array) $data as $k => $v) {
            if (property_exists(self::class, $k)) {
                $this->$k = $v;
            }
        }

        if ($this->id === null) {
            $this->id = 'm' . UUID::v4();
        }

        try {
            $info            = new SplFileInfo($this->getLocalPath());
            $this->localDate = $info->getMTime();
        } catch (RuntimeException $ex) {
            
        }
    }

    /**
     * Initialized the map object from a given ARCHE resource
     * @param string $archeId
     * @return void
     */
    public function fetchFromFedora(string $archeId): void {
        $this->archeId = $archeId;

        $info             = $this->getRemoteFileInfo();
        $this->type       = $info->type;
        $this->remoteDate = $info->mTime;
    }

    /**
     * Checks if the local copy is up to date and if not, fetches the latest version.
     * 
     * Returns true if refresh was done and false if it wasn't needed.
     * @param int $keepAlive maximum number of seconds beetween checking 
     *   corresponding Fedora resources state
     * @return bool
     */
    public function refresh(int $keepAlive): bool {
        $refreshed = false;
        $dataFile = $this->getLocalPath();
        $mapFile = $dataFile . '.map';

        $d = (new DateTime('now'))->diff(DateTime::createFromFormat('Y-m-d H:i:s', $this->checkDate));
        $d = $d->format('%a') * 24 * 3600 + $d->h * 3600 + $d->i * 60 + $d->s + $d->f;
        if ($d > $keepAlive || !file_exists($dataFile)) {
            $info = $this->getRemoteFileInfo();
            if ($info->mTime > $this->localDate || !file_exists($dataFile)) {
                $this->copy($info->location);
                $this->localDate = $info->mTime;
                $this->type      = $info->type;
                $this->size      = filesize($dataFile);
                $this->checkDate = date('Y-m-d H:i:s');
                $refreshed       = true;
                if (file_exists($mapFile)) {
                    unlink($mapFile);
                }
            }
        }

        if (!file_exists($mapFile)) {
            $tmpl      = file_get_contents(self::$templates[$this->type]);
            list($xmin, $ymin, $xmax, $ymax) = $this->getExtent();
            $tmpl      = str_replace('%X_MIN%', $xmin, $tmpl);
            $tmpl      = str_replace('%X_MAX%', $xmax, $tmpl);
            $tmpl      = str_replace('%Y_MIN%', $ymin, $tmpl);
            $tmpl      = str_replace('%Y_MAX%', $ymax, $tmpl);
            $tmpl      = str_replace('%ID%', $this->id, $tmpl);
            $tmpl      = str_replace('%NAME%', $this->getName(), $tmpl);
            $tmpl      = str_replace('%FILE%', $dataFile, $tmpl);
            $tmpl      = str_replace('%URL%', $this->getUrl(), $tmpl);
            file_put_contents($mapFile, $tmpl);
            $refreshed = true;
        }

        return $refreshed;
    }

    /**
     * Returns map path in the local cache
     * @return string
     */
    public function getLocalPath(): string {
        return $this->storageDir . '/' . $this->id;
    }

    /**
     * Fetches remote map file metadata (modification time, type, exact location)
     * @return stdClass
     */
    private function getRemoteFileInfo(): RemoteFileInfo {
        if ($this->remoteFileInfo === null) {
            $client = new Client(['allow_redirects' => false, 'verify' => false]);

            // find the real resource URI
            $url = $this->archeId;
            do {
                $response = $client->get($url);
                $location = $response->getHeader('Location');
                if (is_array($location) && count($location) > 0) {
                    $url = $location[0];
                }
            } while ($response->getStatusCode() >= 300 && $response->getStatusCode() < 400);
            $metaUrl = $url . '/fcr:metadata';

            // fetch metadata
            $graph = new Graph();
            $graph->load($metaUrl);
            $meta  = $graph->resource($url);
            $mtime = $meta->getLiteral('http://fedora.info/definitions/v4/repository#lastModified');
            $mime  = $meta->getLiteral('http://www.ebu.ch/metadata/ontologies/ebucore/ebucore#hasMimeType');
            foreach (self::$mimeTypes as $k => $v) {
                if (in_array($mime, $v)) {
                    $type = $k;
                    break;
                }
            }

            $this->remoteFileInfo = new RemoteFileInfo([
                'mTime'    => $mtime,
                'type'     => $type,
                'location' => $url
            ]);
        }
        return $this->remoteFileInfo;
    }

    /**
     * Copies remote map file to the local cache
     * @param string $from file to copy from (can be an URL)
     */
    private function copy(string $from) {
        $in  = fopen($from, 'r');
        $out = fopen($this->getLocalPath(), 'w');
        while (!feof($in)) {
            fwrite($out, fread($in, 1048576)); // 1MB chunks
        }
        fclose($in);
        fclose($out);
    }

    /**
     * Returns base mapserver URL for a given map (the URL ends with an &)
     * @return string
     */
    public function getUrl(): string {
        return self::$baseUrl . '?map=' . $this->id . '.map&';
    }

    /**
     * Returns extent of an already cached map.
     * 
     * Returns coordinates in the xmin, ymin, xmax, ymax order.
     * @return array
     */
    public function getExtent(): array {
        $output = [];
        $cmd    = 'gdalinfo ' . escapeshellarg($this->getLocalPath());
        exec($cmd, $output);
        $n      = 0;
        while (substr($output[$n], 0, 10) !== 'Lower Left') {
            $n++;
        }
        $min = $output[$n];
        while (substr($output[$n], 0, 11) !== 'Upper Right') {
            $n++;
        }
        $max = $output[$n];

        $min  = explode(' ', preg_replace('/^.*[(] *([0-9.]+), *([0-9.]+)[)].*$/', '\\1 \\2', $min));
        $max  = explode(' ', preg_replace('/^.*[(] *([0-9.]+), *([0-9.]+)[)].*$/', '\\1 \\2', $max));
        $xmin = min($min[0], $max[0]);
        $xmax = max($min[0], $max[0]);
        $ymin = min($min[1], $max[1]);
        $ymax = max($min[1], $max[1]);
        return [$xmin, $ymin, $xmax, $ymax];
    }

    /**
     * Returns mapserver, WMS & WFS safe map name.
     * 
     * Strips all parts of the URI up to the last part of the path.
     * 
     * Makes sure the rest starts with a letter (if not, a "map_" prefix is added) 
     * and doesn't contain spaces (they are converted to underscores)
     * @return string
     */
    public function getName(): string {
        $name = $this->archeId;
        $name = preg_replace('|^.*/|', '', $name);
        $name = str_replace(' ', '_', $name);
        if (!preg_match('/^[a-zA-Z]/', $name)) {
            $name = 'map_' . $name;
        }
        return $name;
    }

    /**
     * Sets the map last requested date to the current time.
     * @return void
     */
    public function touch(): void {
        $this->reqDate = date('Y-m-d H:i:s');
    }

}
