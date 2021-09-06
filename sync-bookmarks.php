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

$markdown = "public:: true\n\n- Publieke bookmarks van [[Sander Dorigo]], gegenereerd met een [handig tooltje](https://github.com/SDx3/sync-to-logseq).\n";

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

uasort($bookmarks, function (array $a, array $b) {
    return strcmp($a['title'], $b['title']);
});
$level          = 0;
$expectedParent = 0;
$markdown       = processFolders($markdown, $bookmarks, $level, $expectedParent);

echo $markdown;

/**
 * @param string $markdown
 * @param array  $bookmarks
 * @param int    $level
 * @param int    $expectedParent
 *
 * @return string
 */
function processFolders(string $markdown, array $bookmarks, int $level, int $expectedParent): string
{
    global $log;
    $log->debug(sprintf('Now in processFolders, level %d and expected parent ID #%d.', $level, $expectedParent));
    foreach ($bookmarks as $folderId => $folder) {
        $parentId = getParentFolderId($bookmarks, $folder);
        $log->debug(sprintf('Parent folder ID of folder "%s" is #%d.', $folder['title'], $parentId));
        if ($parentId === $expectedParent) {
            $log->debug(sprintf('Parent and expected parent are a match, add folder "%s" (ID #%d) to markdown.', $folder['title'], $folderId));

            // add title:
            $markdown .= str_repeat("\t", $level);
            $markdown .= sprintf("- **%s**\n", $folder['title']);

            // process subfolders
            $nextLevel = $level + 1;
            $markdown  = processFolders($markdown, $bookmarks, $nextLevel, $folderId);

            // add bookmarks from THIS folder
            foreach ($folder['bookmarks'] as $bookmark) {
                $log->debug(sprintf('Will add bookmarks from folder "%s" (#%d) to markdown', $folder['title'], $folderId));
                $markdown .= str_repeat("\t", $nextLevel);

                $host = parse_url($bookmark['url'], PHP_URL_HOST);
                if (str_starts_with($host, 'www.')) {
                    $host = substr($host, 4);
                }
                $markdown .= sprintf("- [%s](%s) (%s)", $bookmark['title'], $bookmark['url'], $host);
                $markdown .= "\n";

                // add time of addition:
                $markdown .= str_repeat("\t", $nextLevel);
                $markdown .= sprintf("  Gebookmarkt op %s", str_replace('  ', ' ', $bookmark['added']->formatLocalized('%A %e %B %Y')));


                $markdown .= "\n";
            }
        }
    }

    return $markdown;
}

/**
 * @param array $bookmarks
 * @param array $folder
 *
 * @return int
 */
function getParentFolderId(array $bookmarks, array $folder): int
{
    foreach ($bookmarks as $parentId => $parent) {
        if ($parentId === $folder['parent']) {
            return $parentId;
        }
    }

    return 0;
    //var_dump($folder);
    //exit;
}

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