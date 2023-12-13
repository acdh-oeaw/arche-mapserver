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

use DateTimeImmutable;
use RuntimeException;
use SplFileInfo;
use quickRdfIo\Util as RdfIoUtil;
use quickRdf\Dataset;
use quickRdf\DataFactory as DF;
use termTemplates\QuadTemplate as QT;
use Psr\Log\LoggerInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;

/**
 * Represents a raster or vector map to be stored in a cache.
 *
 * @author zozlak
 */
class Map {

    const TIME_FORMAT = 'Y-m-d H:i:s';

    /**
     * 
     * @var array<string, array<string>>
     */
    static private array $mimeTypes = [
        'raster' => ['image/tiff', 'image/jpeg', 'image/png', 'image/jp2'],
        'vector' => ['application/vnd.geo+json', 'application/json', 'application/vnd.google-earth.kml+xml',
            'application/gml+xml', 'application/geo+json'],
    ];
    /**
     * 
     * @var array<string, string>
     */
    static private array $templates;
    static private string $baseUrl;

    /**
     * Initializes the class by setting up the templates.
     * @param string $rasterTmplFile mapserver's map file template for raster maps
     * @param string $vectorTmplFile mapserver's map file template for vector maps
     * @param string $baseUrl mapserver's base URL 
     *   (see https://mapserver.org/de/ogc/wms_server.html#setup-a-mapfile-for-your-wms 
     *   - the wms_onlineresource config parameter description)
     */
    static public function init(string $rasterTmplFile, string $vectorTmplFile,
                                string $baseUrl): void {
        self::$templates = [
            'raster' => $rasterTmplFile,
            'vector' => $vectorTmplFile,
        ];
        self::$baseUrl   = $baseUrl;
    }

    public string $url;
    public string $type;
    public int $size;
    public string $reqDate;
    public string $checkDate = '1900-01-01 00:00:00';
    public string $localDate;
    public string $remoteDate;
    public string $storageDir;
    private RemoteFileInfo $remoteFileInfo;

    /**
     * 
     * @param string $storageDir directory in which cached maps are stored
     * @param string $url
     * @param object|null $data initial map object property values
     */
    public function __construct(string $storageDir, string $url,
                                object | null $data = null) {
        $this->storageDir = $storageDir;
        $this->url        = $url;

        foreach ((array) $data as $k => $v) {
            if (property_exists(self::class, $k)) {
                $this->$k = $v;
            }
        }

        try {
            $info            = new SplFileInfo($this->getLocalPath());
            $this->localDate = date(self::TIME_FORMAT, $info->getMTime());
        } catch (RuntimeException $ex) {
            
        }
    }

    /**
     * Initialized the map object from a given ARCHE resource
     * @return void
     */
    public function fetch(): void {
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
    public function refresh(int $keepAlive, LoggerInterface $log): bool {
        $refreshed = false;
        $dataFile  = $this->getLocalPath();
        $mapFile   = $dataFile . '.map';

        $d = (new DateTimeImmutable('now'))->diff(DateTimeImmutable::createFromFormat(self::TIME_FORMAT, $this->checkDate));
        $d = (int) $d->format('%a') * 24 * 3600 + $d->h * 3600 + $d->i * 60 + $d->s + $d->f;
        if ($d > $keepAlive || !file_exists($dataFile)) {
            $info = $this->getRemoteFileInfo();
            if (!file_exists($dataFile) || $info->mTime > $this->localDate) {
                $log->info("Fetching binary from $info->location to " . $this->getLocalPath());
                $this->copy($info->location);
                $this->localDate = $info->mTime;
                $this->type      = $info->type;
                $refreshed       = true;
                if (file_exists($mapFile)) {
                    unlink($mapFile);
                }
            }
            $this->checkDate = date(self::TIME_FORMAT);
        }

        if (!file_exists($mapFile)) {
            $tmpl      = file_get_contents(self::$templates[$this->type]);
            $d         = $this->getGeodata();
            $tmpl      = str_replace('%X_MIN%', $d->xmin, $tmpl);
            $tmpl      = str_replace('%X_MAX%', $d->xmax, $tmpl);
            $tmpl      = str_replace('%Y_MIN%', $d->ymin, $tmpl);
            $tmpl      = str_replace('%Y_MAX%', $d->ymax, $tmpl);
            $tmpl      = str_replace('%IDCOL%', $d->idCol, $tmpl);
            $tmpl      = str_replace('%SRID%', $d->srid, $tmpl);
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
        return $this->storageDir . '/' . md5($this->url);
    }

    /**
     * Fetches remote map file metadata (modification time, type, exact location)
     * @return RemoteFileInfo
     */
    private function getRemoteFileInfo(): RemoteFileInfo {
        if (!isset($this->remoteFileInfo)) {
            // find the real resource URI
            $client    = new Client([
                'allow_redirects' => ['track_redirects' => true],
                'verify'          => false,
                'http_errors'     => false,
            ]);
            $response  = $client->send(new Request('HEAD', $this->url));
            $redirects = array_merge([$this->url], $response->getHeader('X-Guzzle-Redirect-History'));
            $url       = array_pop($redirects);
            $url       = preg_replace('|/metadata$|', '', $url);
            $metaUrl   = $url . '/metadata';
            $response  = $client->send(new Request('GET', $metaUrl));
            if ($response->getStatusCode() !== 200) {
                throw new \RuntimeException("Failed to fetch $this->url metadata");
            }

            // fetch metadata
            $sbj   = DF::namedNode($url);
            $meta  = new Dataset();
            $meta->add(RdfIoUtil::parse($response, new DF()));
            $mtime = (string) $meta->listObjects(new QT($url, DF::namedNode('https://vocabs.acdh.oeaw.ac.at/schema#hasUpdatedDate')))->current();
            $mime  = (string) $meta->listObjects(new QT($url, DF::namedNode('https://vocabs.acdh.oeaw.ac.at/schema#hasFormat')))->current();
            $type  = null;
            foreach (self::$mimeTypes as $k => $v) {
                if (in_array($mime, $v)) {
                    $type = $k;
                    break;
                }
            }
            if ($type === null) {
                throw new \RuntimeException("Unsupported file format $mime");
            }

            $this->remoteFileInfo = new RemoteFileInfo([
                'mTime'    => (new DateTimeImmutable($mtime))->format(self::TIME_FORMAT),
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
    private function copy(string $from): void {
        $in  = fopen($from, 'r');
        $out = fopen($this->getLocalPath(), 'w');
        while (!feof($in)) {
            fwrite($out, fread($in, 1048576)); // 1MB chunks
        }
        fclose($in);
        fclose($out);
        $this->size = filesize($this->getLocalPath());
    }

    /**
     * Returns base mapserver URL for a given map (the URL ends with an &)
     * @return string
     */
    public function getUrl(): string {
        return self::$baseUrl . '?map=' . $this->getLocalPath() . '.map&';
    }

    /**
     * Returns various geodata of an already cached map.
     * 
     * @return object
     */
    public function getGeodata(): object {
        $d = (object) [
                'xmin'  => -180,
                'xmax'  => 180,
                'ymin'  => -90,
                'ymax'  => 90,
                'idCol' => 'id',
                'srid'  => 4326
        ];

        switch ($this->type) {
            case 'raster':
                $d = $this->getRasterGeodata($d);
                break;
            case 'vector':
                $d = $this->getVectorGeodata($d);
                break;
        }
        return $d;
    }

    private function getVectorGeodata(object $d): object {
        $output = [];
        $cmd    = 'ogrinfo -nomd -so ' . escapeshellarg($this->getLocalPath());
        exec($cmd, $output);
        $layer = '';
        foreach ($output as $l) {
            if (substr($l, 0, 2) === '1:') {
                $layer = preg_replace('/^1: ([^ ]+)( .*$)?/', '\\1', $l);
                break;
            }
        }
        $cmd = 'ogrinfo -nomd -so ' . escapeshellarg($this->getLocalPath()) . ' ' . escapeshellarg($layer);
        exec($cmd, $output);

        $wktFlag = 0;
        $idCol   = '';
        foreach ($output as $l) {
            if (substr($l, 0, 7) === 'Extent:') {
                $ll      = explode(' ', preg_replace('/  +/', ' ', trim(preg_replace('/[^0-9. ]/', '', $l))));
                $d->xmin = $ll[0];
                $d->xmax = $ll[2];
                $d->ymin = $ll[1];
                $d->ymax = $ll[3];
            }
            if (substr($l, 0, 14) === 'Layer SRS WKT:') {
                $wktFlag = 1;
            }
            if ($wktFlag === 1 && substr($l, 0, 1) !== ' ') {
                $wktFlag = 2;
                $idCol   = preg_replace('/: .*$/', '', $l);
            }
            if ($wktFlag === 2 && preg_replace('/: .*$/', '', $l) === 'id') {
                $idCol   = 'id';
                $wktFlag = 3;
            }
        }
        $d->idCol = $idCol;
        return $d;
    }

    private function getRasterGeodata(object $d): object {
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

        $min     = explode(' ', preg_replace('/^.*[(] *([0-9.]+), *([0-9.]+)[)].*$/', '\\1 \\2', $min));
        $max     = explode(' ', preg_replace('/^.*[(] *([0-9.]+), *([0-9.]+)[)].*$/', '\\1 \\2', $max));
        $d->xmin = min($min[0], $max[0]);
        $d->xmax = max($min[0], $max[0]);
        $d->ymin = min($min[1], $max[1]);
        $d->ymax = max($min[1], $max[1]);
        return $d;
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
        $name = $this->url;
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
        $this->reqDate = date(self::TIME_FORMAT);
    }
}
