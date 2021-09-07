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

declare(strict_types=1);

use App\Collector\BookmarkCollector;
use App\Parser\BookmarkParser;
use GuzzleHttp\Client;

require 'vendor/autoload.php';
require 'init.php';

// login info and setup
$log->debug('Now syncing bookmarks.');
$bookmarks = [];
$client    = new Client();
$opts      = [
    'auth'    => [$_ENV['NEXTCLOUD_USERNAME'], $_ENV['NEXTCLOUD_PASS']],
    'headers' => [
        'Accept' => 'application/json',
    ],
];

$markdown = "- ---\npublic: true\ntitle: ⭐ Bookmarks ⭐\n---\n- Publieke bookmarks van [[Sander Dorigo]], gegenereerd met een [handig tooltje](https://github.com/SDx3/sync-to-logseq).\n";

// collect bookmarks
$configuration = [
    'username' => $_ENV['NEXTCLOUD_USERNAME'],
    'password' => $_ENV['NEXTCLOUD_PASS'],
    'host'     => $_ENV['NEXTCLOUD_HOST'],
];
$collector     = new BookmarkCollector;
$collector->setConfiguration($configuration);
$collector->setLogger($log);
$collector->collect(true);
$bookmarks = $collector->getCollection();

// sort folders
uasort($bookmarks, function (array $a, array $b) {
    return strcmp($a['title'], $b['title']);
});

// loop folders and generate markdown:
$level          = 0;
$expectedParent = 0;
$markdown       = BookmarkParser::processFolders($markdown, $bookmarks, $level, $expectedParent);

// now update (overwrite!) bookmarks file.
$client = new Client;
$url    = sprintf('https://%s/remote.php/dav/files/%s/%s/Bookmarks.md', $_ENV['NEXTCLOUD_HOST'], $_ENV['NEXTCLOUD_USERNAME'], $_ENV['NEXTCLOUD_LOGSEQ_PATH']);
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