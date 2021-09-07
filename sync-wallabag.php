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

$markdown = "---\npublic: true\ntitle: Articles 📰\n---\n\n- Een overzicht van wat [[Sander Dorigo]] heeft gelezen met [Wallabag](https://github.com/wallabag/wallabag). Dit moet nog handiger ingedeeld worden maar voor nu is het even goed zo.\n";

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
    if(is_string($article['archived_at'])) {
        $article['archived_at'] = new Carbon($article['archived_at'], 'Europe/Amsterdam');
    }
    if(is_string($article['created_at'])) {
        $article['created_at'] = new Carbon($article['created_at'], 'Europe/Amsterdam');
    }
    $single = sprintf("- **[%s](%s)**\n", $article['title'], $article['wallabag_url']);

    if (count($article['tags']) > 0) {
        $single .= '  tags:: ' . join(', ', $article['tags']) . "\n";
    }
    $host = parse_url($article['original_url'], PHP_URL_HOST);
    if (str_starts_with($host, 'www.')) {
        $host = substr($host, 4);
    }
    $single   .= sprintf('  - Gelezen en gearchiveerd op %s', str_replace('  ', ' ',$article['archived_at']->formatLocalized('%A %e %B %Y'))) . "\n";
    $single   .= sprintf('  - Oorspronkelijk opgeslagen op %s', str_replace('  ', ' ',$article['created_at']->formatLocalized('%A %e %B %Y'))) . "\n";
    $single   .= sprintf('  - (origineel artikel op [%s](%s))', $host, $article['original_url']) . "\n";
    $markdown .= $single;
}
//file_put_contents('Artikelen en leesvoer.md', $markdown);
// now update (overwrite!) bookmarks file.
$client = new Client;
$url = sprintf('https://%s/remote.php/dav/files/%s/%s/Artikelen en leesvoer.md', $_ENV['NEXTCLOUD_HOST'], $_ENV['NEXTCLOUD_USERNAME'], $_ENV['NEXTCLOUD_LOGSEQ_PATH']);
$opts   = [
    'auth'    => [$_ENV['NEXTCLOUD_USERNAME'], $_ENV['NEXTCLOUD_PASS']],
    'headers' => [
        'Accept' => 'application/json',
    ],
    'body'    => $markdown,
];
$log->debug(sprintf('Going to upload to %s', $url));
$res    = $client->put($url, $opts);
$log->debug('Done!');
