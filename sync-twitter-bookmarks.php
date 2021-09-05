<?php
declare(strict_types=1);

use Abraham\TwitterOAuth\TwitterOAuth;
use GuzzleHttp\Client;

require 'vendor/autoload.php';
require 'init.php';

// set up Twitter API
$connection = new TwitterOAuth($_ENV['TWITTER_CONSUMER_KEY'], $_ENV['TWITTER_CONSUMER_SECRET'], $_ENV['TWITTER_ACCESS_TOKEN'], $_ENV['TWITTER_ACCESS_TOKEN_SECRET']);
$connection->get('account/verify_credentials');
$connection->setApiVersion('2');

// login info and setup
$log->debug('Now syncing Twitter.');
$params= [];
$res = $connection->get('timeline/bookmark', $params);

var_dump($res);

///2/timeline/bookmarks