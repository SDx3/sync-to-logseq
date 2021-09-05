<?php
/*
 * Copyright 2021 Sander Dorigo
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy of
 * this software and associated documentation files (the "Software"), to deal in
 * the Software without restriction, including without limitation the rights to
 * use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies
 * of the Software, and to permit persons to whom the Software is furnished to do
 * so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 */

namespace App\Collector;

use Carbon\Carbon;
use GuzzleHttp\Client;
use JsonException;

/**
 * Class BookmarkCollector
 */
class BookmarkCollector implements CollectorInterface
{
    private bool   $skipCache;
    private array  $configuration;
    private array  $collection;
    private array  $folders;
    private string $cacheFile;

    /**
     *
     */
    public function __construct()
    {
        $this->skipCache  = false;
        $this->collection = [];
        $this->folders    = [];
        $this->cacheFile  = sprintf('%s/bookmarks.json', CACHE);
    }

    /**
     * @inheritDoc
     */
    public function setConfiguration(array $configuration): void
    {
        $this->configuration = $configuration;
    }

    /**
     * @inheritDoc
     */
    public function collect(bool $skipCache = false): void
    {
        $this->skipCache = $skipCache;
        $useCache        = true;

        if (true === $this->skipCache) {
            $useCache = false;
        }
        if (false === $this->skipCache && $this->cacheOutOfDate()) {
            $useCache = false;
        }
        if (false === $useCache) {
            $this->collectBookmarks();
            $this->collectFolders();
            $this->mergeCollection();
            $this->saveToCache();
        }
        if (true === $useCache) {
            $this->collectCache();
        }
    }

    /**
     * @inheritDoc
     */
    public function getCollection(): array
    {
        return $this->collection;
    }

    /**
     * @return bool
     */
    private function cacheOutOfDate(): bool
    {
        $content = file_get_contents($this->cacheFile);
        $json    = json_decode($content, true, 128, JSON_THROW_ON_ERROR);
        // diff is over 12hrs
        if (time() - $json['moment'] > (12 * 60 * 60)) {
            return true;
        }
        return false;
    }

    /**
     *
     * @throws JsonException
     */
    private function collectBookmarks(): void
    {
        $client = new Client;
        $opts   = [
            'auth'    => [$this->configuration['username'], $this->configuration['password']],
            'headers' => [
                'Accept' => 'application/json',
            ],
        ];
        // collect all bookmarks with their folder ID:
        $hasMore = true;
        $page    = 0;
        while (true === $hasMore) {
            $res  = $client->get(sprintf('https://%s/index.php/apps/bookmarks/public/rest/v2/bookmark?limit=100&page=%d', $this->configuration['host'], $page), $opts);
            $data = (string) $res->getBody();
            $body = json_decode($data, true, 128, JSON_THROW_ON_ERROR);
            if (isset($body['data'])) {
                if (0 === count($body['data'])) {
                    $hasMore = false;
                }

                foreach ($body['data'] as $entry) {
                    $folderId = $entry['folders'][0] ?? -1;

                    $this->collection[$folderId] = $this->collection[$folderId] ??
                                                   [
                                                       'title'     => '(empty)',
                                                       'bookmarks' => [],
                                                   ];

                    $this->collection[$folderId]['bookmarks'][] = [
                        'title' => $entry['title'],
                        'url'   => $entry['url'],
                        'added' => new Carbon($entry['added'])];
                }
            }
            if (!isset($body['data'])) {
                $hasMore = false;
            }
            $page++;
        }
    }

    /**
     *
     */
    private function saveToCache(): void
    {
        $content = [
            'moment' => time(),
            'data'   => $this->collection,
        ];
        $json    = json_encode($content, JSON_PRETTY_PRINT);
        file_put_contents($this->cacheFile, $json);
    }

    /**
     *
     */
    private function collectFolders(): void
    {
        $client = new Client;
        $opts   = [
            'auth'    => [$this->configuration['username'], $this->configuration['password']],
            'headers' => [
                'Accept' => 'application/json',
            ],
        ];

        // get all folders, then get all bookmarks for that folder
        $res  = $client->get(sprintf('https://%s/index.php/apps/bookmarks/public/rest/v2/folder', $this->configuration['host']), $opts);
        $data = (string) $res->getBody();
        $body = json_decode($data, true);
        $this->parseFolderNames($body['data']);
    }

    /**
     * @param array $array
     */
    private function parseFolderNames(array $array): void
    {
        /** @var array $folder */
        foreach ($array as $folder) {
            $folderId    = $folder['id'];
            $folderTitle = trim($folder['title']);
            $folderTitle = 0 === strlen($folderTitle) ? '(no title)' : $folderTitle;

            $this->folders[$folderId] = [
                'title' => $folderTitle,
            ];

            if (count($folder['children']) > 0) {
                $this->parseFolderNames($folder['children']);
            }
        }
    }

    private function mergeCollection(): void
    {
        /**
         * @var int   $folderId
         * @var array $info
         */
        foreach ($this->collection as $folderId => $info) {
            if (isset($this->folders[$folderId])) {
                $this->collection[$folderId]['title'] = $this->folders[$folderId]['title'];
            }
        }
    }

    private function collectCache(): void
    {
        $content          = file_get_contents($this->cacheFile);
        $json             = json_decode($content, true, 128, JSON_THROW_ON_ERROR);
        $this->collection = $json['data'];
    }
}