<?php

use Carbon\Carbon;
use GuzzleHttp\Client;

setlocale(LC_ALL, ['nl', 'nl_NL.UTF-8', 'nl-NL']);
$articles = [];

require 'vendor/autoload.php';
require 'init.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

$markdown = "public:: true\n- Een overzicht van wat [[Sander Dorigo]] heeft gelezen met [Wallabag](https://github.com/wallabag/wallabag). Dit moet nog handiger ingedeeld worden maar voor nu is het even goed zo.\n";

$log->debug('Start of wallabag script.');

// get an access token
$client   = new Client;
$opts     = [
    'form_params' => [
        'grant_type'    => 'password',
        'client_id'     => $_ENV['CLIENT_ID'],
        'client_secret' => $_ENV['CLIENT_SECRET'],
        'username'      => $_ENV['USERNAME'],
        'password'      => $_ENV['PASSWORD'],
    ],
];
$url      = sprintf('%s/oauth/v2/token', $_ENV['WALLABAG_HOST']);
$response = $client->post($url, $opts);
$body     = (string) $response->getBody();
$token    = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
$log->debug(sprintf('Access token is %s.', $token['access_token']));

// get all public articles until feed runs out.
$client      = new Client;
$page        = 1;
$hasMore     = true;
$articlesUrl = '%s/api/entries.json?archive=1&sort=archived&perPage=5&page=%d&public=0';
$opts        = [
    'headers' => [
        'Authorization' => sprintf('Bearer %s', $token['access_token']),
    ],
];

$log->debug('Collecting archived + not public.');
while (true === $hasMore) {
    $url      = sprintf($articlesUrl, $_ENV['WALLABAG_HOST'], $page);
    $response = $client->get($url, $opts);
    $body     = (string) $response->getBody();
    $results  = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

    $log->addRecord($results['total'] > 0 ? 200 : 100, sprintf('Found %d new article(s).', $results['total']));

    if ($results['pages'] <= $page) {
        $hasMore = false;
    }
    // loop articles
    foreach ($results['_embedded']['items'] as $item) {
        $patchClient = new Client;
        $patchUrl    = sprintf('%s/api/entries/%d.json', $_ENV['WALLABAG_HOST'], $item['id']);
        $patchOpts   = [
            'headers'     => [
                'Authorization' => sprintf('Bearer %s', $token['access_token']),
            ],
            'form_params' => [
                'public' => 1,
            ],
        ];
        $patchRes    = $patchClient->patch($patchUrl, $patchOpts);
        $log->debug(sprintf('Make article #%d public..', $item['id']));
        sleep(2);
    }
    $page++;
}

// get all public + archived articles until feed runs out:
$client      = new Client;
$page        = 1;
$hasMore     = true;
$articlesUrl = '%s/api/entries.json?archive=1&sort=archived&perPage=5&page=%d&public=1&detail=metadata';
$opts        = [
    'headers' => [
        'Authorization' => sprintf('Bearer %s', $token['access_token']),
    ],
];

while (true === $hasMore) {
    $url      = sprintf($articlesUrl, $_ENV['WALLABAG_HOST'], $page);
    $response = $client->get($url, $opts);
    $body     = (string) $response->getBody();
    $results  = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

    if (1 === $page) {
        $log->debug(sprintf('Found %d article(s) to share.', $results['total']));
    }
    $log->debug(sprintf('Working on page %d of %d...', $page, $results['pages']));

    if ($results['pages'] <= $page) {
        // no more pages
        $hasMore = false;
    }
    // loop articles and save them:
    foreach ($results['_embedded']['items'] as $item) {
        $article = [
            'title'        => $item['title'],
            'original_url' => $item['url'],
            'archived_at'  => new Carbon($item['archived_at']),
            'created_at'  => new Carbon($item['created_at']),
            'wallabag_url' => sprintf('%s/share/%s', $_ENV['WALLABAG_HOST'], $item['uid']),
            'tags'         => [],
        ];

        foreach ($item['tags'] as $tag) {
            $article['tags'][] = $tag['label'];
        }

        $articles[] = $article;
    }
    sleep(2);
    $page++;
}


/** @var array $article */
foreach ($articles as $article) {
    $single = sprintf("- **[%s](%s)**\n", $article['title'], $article['wallabag_url']);

    if (count($article['tags']) > 0) {
        $single .= '  tags:: ' . join(', ', $article['tags']) . "\n";
    }
    $host = parse_url($article['original_url'], PHP_URL_HOST);
    if (str_starts_with($host, 'www.')) {
        $host = substr($host, 4);
    }
    $single   .= sprintf('  - Gelezen en gearchiveerd op %s', $article['archived_at']->formatLocalized('%A %e %B %Y')) . "\n";
    $single   .= sprintf('  - Oorspronkelijk opgeslagen op %s', $article['created_at']->formatLocalized('%A %e %B %Y')) . "\n";
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
