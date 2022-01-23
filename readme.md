# Sync thing

Three little scripts that sync bookmarks and articles to Logseq. It generates Markdown files.

## Basic instructions

- First clone the repository, and copy `.env.example` to `.env`
- You'll need [composer](https://getcomposer.org/) to install the dependencies using `composer install`
- Filling in the details in the `.env` file is enough. You only need the Wallabag + Nextcloud details.
  - Both should be reachable from the web, be sure to use `https://`

## Nextcloud config

`NEXTCLOUD_LOGSEQ_PATH` is the path where the files will be stored. Be sure to include the "pages" directory, ie. `misc/logseq/pages`.

## Running it

Once configured and installed, run one of the following scripts

 - `php sync-bookmarks.php` generates `Bookmarks.md` based on your Nextcloud Bookmarks
 - `php sync-wallabag.php` generates `Artikelen en leesvoer.md` based on your Wallabag articles
 - `php create-stream.php` generates `Stream.md`, a date-based stream of articles and bookmarks

 The Wallabag script will make any archived Wallabag article public automatically.

If you have questions, let me know.
