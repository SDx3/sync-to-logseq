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

use Carbon\Carbon;
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

$content = "public:: true\n\n- Publieke bookmarks van [[Sander Dorigo]], gegenereerd met een [handig tooltje](https://github.com/SDx3/sync-to-logseq).\n";

// collect all bookmarks with their folder ID:
$res   = $client->get(sprintf('https://%s/index.php/apps/bookmarks/public/rest/v2/bookmark?limit=250', $_ENV['NEXTCLOUD_HOST']), $opts);
$data  = (string) $res->getBody();
$body  = json_decode($data, true);
$total = 0;
if (isset($body['data'])) {
    foreach ($body['data'] as $entry) {
        $folderId               = $entry['folders'][0] ?? -1;
        $bookmarks[$folderId][] = ['title' => $entry['title'], 'url' => $entry['url'], 'added' => new Carbon($entry['added'])];
        $total++;
    }
}
$log->debug(sprintf('Found %d bookmark(s).', $total));

// get all folders, then get all bookmarks for that folder
$res  = $client->get(sprintf('https://%s/index.php/apps/bookmarks/public/rest/v2/folder', $_ENV['NEXTCLOUD_HOST']), $opts);
$data = (string) $res->getBody();
$body = json_decode($data, true);

$content = processFolders($content, $bookmarks, $body['data'], 0);
$log->debug('Processed all folders');

function processFolders(string $content, array $bookmarks, array $folders, int $level): string
{
    /** @var array $folder */
    foreach ($folders as $folder) {
        $folderId = $folder['id'];
        if ((isset($bookmarks[$folderId]) && count($bookmarks[$folderId]) > 0) || count($folder['children']) > 0) {
            if ('' !== $folder['title']) {
                $content .= str_repeat("\t", $level);
                $content .= sprintf('- **%s**', $folder['title']);
                $content .= "\n";
                $set     = $bookmarks[$folderId] ?? [];
                asort($set);
                /** @var array $bookmark */
                foreach ($set as $bookmark) {
                    $host = parse_url($bookmark['url'], PHP_URL_HOST);
                    if (str_starts_with($host, 'www.')) {
                        $host = substr($host, 4);
                    }
                    $content .= str_repeat("\t", $level + 1);
                    $content .= sprintf('- [%s](%s) (%s)', $bookmark['title'], $bookmark['url'], $host);
                    $content .= "\n";
                    // add time of addition:
                    $content .= sprintf('  Gebookmarkt op %s', $bookmark['added']->formatLocalized('%A %e %B %Y'));
                    $content .= "\n";
                }
            }
        }

        if (count($folder['children']) > 0) {
            $content = processFolders($content, $bookmarks, $folder['children'], $level + 1);
        }
    }

    return $content;
}

// now update (overwrite!) bookmarks file.
$client = new Client;
$url    = sprintf('https://%s/remote.php/dav/files/%s/%s/Bookmarks.md', $_ENV['NEXTCLOUD_HOST'], $_ENV['NEXTCLOUD_USERNAME'], $_ENV['NEXTCLOUD_LOGSEQ_PATH']);
$opts   = [
    'auth'    => [$_ENV['NEXTCLOUD_USERNAME'], $_ENV['NEXTCLOUD_PASS']],
    'headers' => [
        'Accept' => 'application/json',
    ],
    'body'    => $content,
];
$log->debug(sprintf('Going to upload to %s', $url));
$res = $client->put($url, $opts);
$log->debug('Done!');