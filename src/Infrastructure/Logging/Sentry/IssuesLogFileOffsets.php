<?php

declare(strict_types=1);

namespace DDD\Infrastructure\Logging\Sentry;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The read positions of a forwarder, one per file, persisted as JSON.
 *
 * @property IssuesLogFileOffset[] $elements;
 * @method IssuesLogFileOffset[] getElements()
 * @method IssuesLogFileOffset|null getByUniqueKey(string $uniqueKey)
 */
class IssuesLogFileOffsets extends ObjectSet
{
    public function getForFile(string $filePath): ?IssuesLogFileOffset
    {
        return $this->getByUniqueKey(IssuesLogFileOffset::uniqueKeyStatic($filePath));
    }

    /** Replaces the file's position */
    public function setForFile(IssuesLogFileOffset $issuesLogFileOffset): void
    {
        $previousOffset = $this->getForFile($issuesLogFileOffset->filePath);
        if ($previousOffset !== null) {
            $this->remove($previousOffset);
        }
        $this->add($issuesLogFileOffset);
    }

    public static function fromStateJson(string $json): static
    {
        $issuesLogFileOffsets = new static();
        $decoded = json_decode($json, true);
        foreach (is_array($decoded) ? $decoded : [] as $filePath => $fileState) {
            if (is_array($fileState) && isset($fileState['inode'], $fileState['offset'])) {
                $issuesLogFileOffset = new IssuesLogFileOffset((string)$filePath, (int)$fileState['inode'], (int)$fileState['offset']);
                $issuesLogFileOffsets->add($issuesLogFileOffset);
            }
        }
        return $issuesLogFileOffsets;
    }

    /** `{"<path>": {"inode": …, "offset": …}}`, only files that still exist */
    public function toStateJson(): string
    {
        $statesByFile = [];
        foreach ($this->getElements() as $issuesLogFileOffset) {
            if (is_file($issuesLogFileOffset->filePath)) {
                $statesByFile[$issuesLogFileOffset->filePath] = ['inode' => $issuesLogFileOffset->inode, 'offset' => $issuesLogFileOffset->offset];
            }
        }
        return (string)json_encode($statesByFile, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
