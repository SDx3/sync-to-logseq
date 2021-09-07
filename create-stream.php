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

// create a stream by picking up:
// - bookmarks
// - wallabag
// - tumblr likes
// - twitter bookmarks
// - published from RSS
// and sorting them by date and time in a new markdown file.

// bookmarks:
use App\Collector\BookmarkCollector;
use App\Collector\WallabagCollector;
use Carbon\Carbon;
use GuzzleHttp\Client;

require 'vendor/autoload.php';
require 'init.php';

$log->debug('Now creating a thought stream yay');

// collect bookmarks
$configuration = [
    'username' => $_ENV['NEXTCLOUD_USERNAME'],
    'password' => $_ENV['NEXTCLOUD_PASS'],
    'host'     => $_ENV['NEXTCLOUD_HOST'],
];
$collector     = new BookmarkCollector;
$collector->setConfiguration($configuration);
$collector->setLogger($log);
$collector->collect();
$bookmarks = $collector->getCollection();

// collect wallabag
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

$dates = [];

// loop and add to array
$log->debug('Now looping bookmarks.');

/** @var array $folder */
foreach ($bookmarks as $folder) {
    /** @var array $bookmark */
    foreach ($folder['bookmarks'] as $bookmark) {
        if (is_string($bookmark['added'])) {
            $bookmark['added'] = new Carbon($bookmark['added'], 'Europe/Amsterdam');
        }
        $bookmark['folder']              = $folder['title'];
        $dateString                      = (string)$bookmark['added']->format('Ymd');
        $timeString                      = $bookmark['added']->format('His');
        $dates[$dateString]              = array_key_exists($dateString, $dates) ? $dates[$dateString] : [];
        $dates[$dateString][$timeString] = array_key_exists($timeString, $dates[$dateString]) ? $dates[$dateString][$timeString] : [];
        // add bookmark to this array verbatim:
        $dates[$dateString][$timeString][] = [
            'type' => 'bookmark',
            'data' => $bookmark,
        ];

    }
}
$log->debug('Now looping articles.');

/** @var array $article */
foreach ($articles as $article) {
    if (is_string($article['archived_at'])) {
        $article['archived_at'] = new Carbon($article['archived_at'], 'Europe/Amsterdam');
    }
    $dateString                      = (string)$article['archived_at']->format('Ymd');
    $timeString                      = $article['archived_at']->format('His');
    $dates[$dateString]              = array_key_exists($dateString, $dates) ? $dates[$dateString] : [];
    $dates[$dateString][$timeString] = array_key_exists($timeString, $dates[$dateString]) ? $dates[$dateString][$timeString] : [];
    // add article to this array verbatim:
    $dates[$dateString][$timeString][] = [
        'type' => 'article',
        'data' => $article,
    ];
}
// sort by date
krsort($dates, SORT_STRING);
$log->debug(sprintf('Collected all bookmarks and articles, grouped in %d specific date(s).', count(array_keys($dates))));

// loop to generate MD file.
$markdown = "public:: true\n";
foreach ($dates as $date => $content) {
    krsort($content);
    $dateObject = Carbon::createFromFormat('Ymd', $date, 'Europe/Amsterdam');
    $markdown   .= sprintf("- %s\n  heading:: true\n", str_replace('  ', ' ', $dateObject->formatLocalized('%A %e %B %Y')));
    // all entries for this date slot:
    foreach ($content as $timeSlot => $entries) {
        // each entry for this timeslot
        foreach ($entries as $entry) {
            switch ($entry['type']) {
                default:
                    die(sprintf('Script cannot handle type "%s"', $entry['type']));
                case 'bookmark':
                    $host = parse_url($entry['data']['url'], PHP_URL_HOST);
                    if (str_starts_with($host, 'www.')) {
                        $host = substr($host, 4);
                    }
                    $sentence = sprintf("    - ⭐ [%s](%s) (%s)\n", $entry['data']['title'], $entry['data']['url'], $host);
                    $markdown .= $sentence;
                    break;
                case 'article':
                    $host = parse_url($entry['data']['original_url'], PHP_URL_HOST);
                    if (str_starts_with($host, 'www.')) {
                        $host = substr($host, 4);
                    }

                    $sentence = sprintf("    - 📰 [%s](%s)\n", $entry['data']['title'], $entry['data']['wallabag_url']);
                    $sentence .= sprintf("      Origineel artikel op [%s](%s)\n", $host, $entry['data']['original_url']);

                    $markdown .= $sentence;
                    //                    var_dump($article);
                    //                    exit;
                    break;
            }
        }
    }
}
// temp
// now update (overwrite!) bookmarks file.
$client = new Client;
$url    = sprintf('https://%s/remote.php/dav/files/%s/%s/Stream.md', $_ENV['NEXTCLOUD_HOST'], $_ENV['NEXTCLOUD_USERNAME'], $_ENV['NEXTCLOUD_LOGSEQ_PATH']);
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
