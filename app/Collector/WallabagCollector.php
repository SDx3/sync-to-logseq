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

use GuzzleHttp\Client;

/**
 * Class WallabagCollector
 */
class WallabagCollector implements CollectorInterface
{
    private bool   $skipCache;
    private array  $configuration;
    private array  $collection;
    private array  $folders;
    private string $cacheFile;
    private array  $token;

    /**
     *
     */
    public function __construct()
    {
        $this->skipCache  = false;
        $this->collection = [];
        $this->token      = [];
        $this->cacheFile  = sprintf('%s/wallabag.json', CACHE);
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
            $this->getAccessToken();
            $this->makePublicArticles();
            $this->collectArchivedArticles();
            $this->saveToCache();
        }
        if (true === $useCache) {
            $this->collectCache();
        }
    }

    /**
     * @return bool
     */
    private function cacheOutOfDate(): bool
    {
        if (!file_exists($this->cacheFile)) {
            return true;
        }
        $content = file_get_contents($this->cacheFile);
        $json    = json_decode($content, true, 128, JSON_THROW_ON_ERROR);
        // diff is over 12hrs
        if (time() - $json['moment'] > (12 * 60 * 60)) {
            return true;
        }
        return false;
    }


    /**
     * @inheritDoc
     */
    public function getCollection(): array
    {
        return $this->collection;
    }

    /**
     *
     */
    private function makePublicArticles(): void
    {
        $client      = new Client;
        $page        = 1;
        $hasMore     = true;
        $articlesUrl = '%s/api/entries.json?archive=1&sort=archived&perPage=5&page=%d&public=0';
        $opts        = [
            'headers' => [
                'Authorization' => sprintf('Bearer %s', $this->token['access_token']),
            ],
        ];

        while (true === $hasMore) {
            $url      = sprintf($articlesUrl, $this->configuration['host'], $page);
            $response = $client->get($url, $opts);
            $body     = (string) $response->getBody();
            $results  = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

            //$log->addRecord($results['total'] > 0 ? 200 : 100, sprintf('Found %d new article(s).', $results['total']));

            if ($results['pages'] <= $page) {
                $hasMore = false;
            }
            // loop articles
            foreach ($results['_embedded']['items'] as $item) {
                $patchClient = new Client;
                $patchUrl    = sprintf('%s/api/entries/%d.json', $_ENV['WALLABAG_HOST'], $item['id']);
                $patchOpts   = [
                    'headers'     => [
                        'Authorization' => sprintf('Bearer %s', $this->token['access_token']),
                    ],
                    'form_params' => [
                        'public' => 1,
                    ],
                ];
                $patchClient->patch($patchUrl, $patchOpts);
                //$log->debug(sprintf('Make article #%d public..', $item['id']));
                sleep(2);
            }
            $page++;
        }
    }

    /**
     *
     */
    private function getAccessToken(): void
    {
        $client      = new Client;
        $opts        = [
            'form_params' => [
                'grant_type'    => 'password',
                'client_id'     => $this->configuration['client_id'],
                'client_secret' => $this->configuration['client_secret'],
                'username'      => $this->configuration['username'],
                'password'      => $this->configuration['password'],
            ],
        ];
        $url         = sprintf('%s/oauth/v2/token', $this->configuration['host']);
        $response    = $client->post($url, $opts);
        $body        = (string) $response->getBody();
        $this->token = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
    }

    /**
     * 
     */
    private function collectArchivedArticles(): void
    {
    }
}