<?php

namespace App\Parser;

use Monolog\Logger;

/**
 * Class BookmarkParser
 */
class BookmarkParser
{
    private string $bookmarkTemplate;
    private Logger $log;

    /**
     * @param string $markdown
     * @param array  $bookmarks
     * @param int    $level
     * @param int    $expectedParent
     *
     * @return string
     */
    public function processFolders(string $markdown, array $bookmarks, int $level, int $expectedParent): string
    {
        $this->log->debug(sprintf('Now in processFolders, level %d and expected parent ID #%d.', $level, $expectedParent));
        foreach ($bookmarks as $folderId => $folder) {
            $parentId = $this->getParentFolderId($bookmarks, $folder);
            $this->->debug(sprintf('Parent folder ID of folder "%s" is #%d.', $folder['title'], $parentId));
            if ($parentId === $expectedParent) {
                $this->->debug(sprintf('Parent and expected parent are a match, add folder "%s" (ID #%d) to markdown.', $folder['title'], $folderId));

                // add title:
                $markdown .= str_repeat("\t", $level);
                $markdown .= sprintf("- **%s**\n", $folder['title']);

                // process subfolders
                $nextLevel = $level + 1;
                $markdown  = $this->processFolders($markdown, $bookmarks, $nextLevel, $folderId);

                // add bookmarks from THIS folder
                foreach ($folder['bookmarks'] as $bookmark) {
                    $this->log->debug(sprintf('Will add bookmark from folder "%s" (#%d) to markdown', $folder['title'], $folderId));
                    $markdown .= str_repeat("\t", $nextLevel);

                    $host = parse_url($bookmark['url'], PHP_URL_HOST);
                    if (str_starts_with($host, 'www.')) {
                        $host = substr($host, 4);
                    }

                    // parse template:
                    $markdown .= sprintf($this->bookmarkTemplate, $bookmark['title'], $bookmark['url'], $host);
                    $markdown .= "\n";
                }
            }
        }

        return $markdown;
    }

    /**
     * @param Logger $log
     */
    public function setLog(Logger $log): void
    {
        $this->log = $log;
    }



    /**
     * @param array $bookmarks
     * @param array $folder
     *
     * @return int
     */
    protected function getParentFolderId(array $bookmarks, array $folder): int
    {
        foreach ($bookmarks as $parentId => $parent) {
            if ($parentId === $folder['parent']) {
                return $parentId;
            }
        }

        return 0;
    }

    /**
     * @param string $bookmarkTemplate
     */
    public function setBookmarkTemplate(string $bookmarkTemplate): void
    {
        $this->bookmarkTemplate = $bookmarkTemplate;
    }



}