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

use App\Collector\WallabagCollector;
use Carbon\Carbon;
use GuzzleHttp\Client;

$articles = [];

require 'vendor/autoload.php';
require 'init.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

$markdown = "---\npublic: true\n---\n\n- Dit is een overzicht van alles dat [ik]([[Sander Dorigo]]) online heb 📰 gelezen 📰, en opgeslagen heb in [Wallabag](https://github.com/wallabag/wallabag).\n";
$markdown .= "  - Deze lijst is gesorteerd op tijd (laatst gelezen eerst)\n";
$markdown .= "  - Dit moet nog handiger ingedeeld worden maar voor nu is het even goed zo.\n";

$log->debug('Start of wallabag script.');

// collect bookmarks
$collector     = new WallabagCollector;
$configuration = [
    'client_id'     => $_ENV['WALLABAG_CLIENT_ID'],
    'client_secret' => $_ENV['WALLABAG_CLIENT_SECRET'],
    'username'      => $_ENV['WALLABAG_USERNAME'],
    'password'      => $_ENV['WALLABAG_PASSWORD'],
    'host'          => $_ENV['WALLABAG_HOST'],
];
$collector->setConfiguration($configuration);
$collector->setLogger($log);
$collector->collect();
$articles = $collector->getCollection();

/** @var array $article */
foreach ($articles as $article) {
    $single = '';

    // parse original host name
    $host = parse_url($article['original_url'], PHP_URL_HOST);
    if (str_starts_with($host, 'www.')) {
        $host = substr($host, 4);
    }

    // make Carbon objects:
    if (is_string($article['archived_at'])) {
        $article['archived_at'] = new Carbon($article['archived_at'], 'Europe/Amsterdam');
    }
    if (is_string($article['created_at'])) {
        $article['created_at'] = new Carbon($article['created_at'], 'Europe/Amsterdam');
    }

    // add link to article:
    $single .= sprintf("- **[%s](%s)**\n", $article['title'], $article['wallabag_url']);
    $single .= sprintf('  (origineel op [%s](%s))', $host, $article['original_url']) . "\n";

    // add tags if present:
    if (count($article['tags']) > 0) {
        //$single .= '  tags:: ' . join(', ', $article['tags']) . "\n";
    }

    // add "archived on"
    $single .= sprintf('  - Gelezen op %s', str_replace('  ', ' ', $article['archived_at']->formatLocalized('%A %e %B %Y'))) . "\n";

    // add "saved on" (currently disabled):
    //$single   .= sprintf('  - Oorspronkelijk opgeslagen op %s', str_replace('  ', ' ', $article['created_at']->formatLocalized('%A %e %B %Y'))) . "\n";

    // add annotations (if present)
    if(count($article['annotations']) > 0) {
        $single .= "  - Opmerkingen\n";
        foreach($article['annotations'] as $annotation) {
            $single .= sprintf("    - > %s\n      %s\n", $annotation['quote'], $annotation['text']);
        }
    }


    $markdown .= $single;
}

// now update (overwrite!) bookmarks file.
$client = new Client;
$url    = sprintf('https://%s/remote.php/dav/files/%s/%s/Artikelen en leesvoer.md', $_ENV['NEXTCLOUD_HOST'], $_ENV['NEXTCLOUD_USERNAME'], $_ENV['NEXTCLOUD_LOGSEQ_PATH']);
$opts   = [
    'auth'    => [$_ENV['NEXTCLOUD_USERNAME'], $_ENV['NEXTCLOUD_PASS']],
    'headers' => [
        'Accept' => 'application/json',
    ],
    'body'    => $markdown,
];
$log->debug(sprintf('Going to upload to %s', $url));
$res = $client->put($url, $opts);
$log->debug('Done!');
