<?php

namespace App\Parser;

/**
 * Class BookmarkParser
 */
class BookmarkParser
{
    /**
     * @param string $markdown
     * @param array  $bookmarks
     * @param int    $level
     * @param int    $expectedParent
     *
     * @return string
     */
    public static function processFolders(string $markdown, array $bookmarks, int $level, int $expectedParent): string
    {
        global $log;
        $log->debug(sprintf('Now in processFolders, level %d and expected parent ID #%d.', $level, $expectedParent));
        foreach ($bookmarks as $folderId => $folder) {
            $parentId = self::getParentFolderId($bookmarks, $folder);
            $log->debug(sprintf('Parent folder ID of folder "%s" is #%d.', $folder['title'], $parentId));
            if ($parentId === $expectedParent) {
                $log->debug(sprintf('Parent and expected parent are a match, add folder "%s" (ID #%d) to markdown.', $folder['title'], $folderId));

                // add title:
                $markdown .= str_repeat("\t", $level);
                $markdown .= sprintf("- **%s**\n", $folder['title']);

                // process subfolders
                $nextLevel = $level + 1;
                $markdown  = self::processFolders($markdown, $bookmarks, $nextLevel, $folderId);

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
    protected static function getParentFolderId(array $bookmarks, array $folder): int
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
}