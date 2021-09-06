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
use Monolog\Logger;

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
    private Logger $logger;

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
        $this->logger->debug('BookmarkCollector will collect.');
        $this->skipCache = $skipCache;
        $useCache        = true;

        if (true === $this->skipCache) {
            $useCache = false;
        }
        if (false === $this->skipCache && $this->cacheOutOfDate()) {
            $useCache = false;
        }
        if (false === $useCache) {
            $this->logger->debug('BookmarkCollector will not use the cache.');
            $this->collectBookmarks();
            $this->collectFolders();
            $this->mergeCollection();
            $this->saveToCache();
        }
        if (true === $useCache) {
            $this->logger->debug('BookmarkCollector will use the cache.');
            $this->collectCache();
        }
        $this->logger->debug('BookmarkCollector is done.');
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
        if (!file_exists($this->cacheFile)) {
            $this->logger->debug('BookmarkCollector cache file does not exist.');
            return true;
        }
        $content = file_get_contents($this->cacheFile);
        $json    = json_decode($content, true, 128, JSON_THROW_ON_ERROR);
        // diff is over 12hrs
        if (time() - $json['moment'] > (12 * 60 * 60)) {
            $this->logger->debug('BookmarkCollector cache is out of date.');
            return true;
        }
        $this->logger->debug('BookmarkCollector cache is fresh.');

        return false;
    }

    /**
     *
     * @throws JsonException
     */
    private function collectBookmarks(): void
    {
        $this->logger->debug('BookmarkCollector will collect fresh bookmarks.');
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
            $this->logger->debug(sprintf('BookmarkCollector is working on page #%d.', $page));
            $res  = $client->get(
                sprintf('https://%s/index.php/apps/bookmarks/public/rest/v2/bookmark?limit=100&page=%d', $this->configuration['host'], $page), $opts
            );
            $data = (string)$res->getBody();
            $body = json_decode($data, true, 128, JSON_THROW_ON_ERROR);
            if (isset($body['data'])) {
                if (0 === count($body['data'])) {
                    $this->logger->debug('BookmarkCollector found no bookmarks on this page, and will stop.');
                    $hasMore = false;
                }
                $this->logger->debug(sprintf('BookmarkCollector found %d bookmarks.', count($body['data'])));
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
                $this->logger->debug('BookmarkCollector result has no body, so no more bookmarks.');
                $hasMore = false;
            }
            $page++;
        }
        $this->logger->debug('BookmarkCollector is done collecting bookmarks.');
    }

    /**
     *
     */
    private function saveToCache(): void
    {
        $this->logger->debug('BookmarkCollector has saved the collection to cache.');
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
        $this->logger->debug('BookmarkCollector is collecting folders.');
        // get all folders, then get all bookmarks for that folder
        $res  = $client->get(sprintf('https://%s/index.php/apps/bookmarks/public/rest/v2/folder', $this->configuration['host']), $opts);
        $data = (string)$res->getBody();
        $body = json_decode($data, true);
        $this->parseFolderNames($body['data']);
        $this->logger->debug('BookmarkCollector is done collecting folders.');
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
        $this->logger->debug('BookmarkCollector has merged folders + bookmarks.');
    }

    /**
     * @throws JsonException
     */
    private function collectCache(): void
    {
        $content          = file_get_contents($this->cacheFile);
        $json             = json_decode($content, true, 128, JSON_THROW_ON_ERROR);
        $this->collection = $json['data'];
        $this->logger->debug('BookmarkCollector has collected bookmarks from the cache.');
    }

    /**
     * @inheritDoc
     */
    public function setLogger(Logger $logger): void
    {
        $this->logger = $logger;
        $this->logger->debug('BookmarkCollector has a logger!');
    }
}