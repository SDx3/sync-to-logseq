<?php
declare(strict_types=1);

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

$content = "public:: true\n\n- Public bookmarks of [[Sander Dorigo]], generated using the [bookmark sync script](https://github.com/SDx3/bookmark-sync).\n";

// collect all bookmarks with their folder ID:
$res   = $client->get(sprintf('https://%s/index.php/apps/bookmarks/public/rest/v2/bookmark?limit=250', $_ENV['NEXTCLOUD_HOST']), $opts);
$data  = (string) $res->getBody();
$body  = json_decode($data, true);
$total = 0;
if (isset($body['data'])) {
    foreach ($body['data'] as $entry) {
        $folderId               = $entry['folders'][0] ?? -1;
        $bookmarks[$folderId][] = ['title' => $entry['title'], 'url' => $entry['url']];
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