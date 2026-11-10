<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Workers;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * @property WorkersCheckFinding[] $elements
 * @method WorkersCheckFinding[] getElements()
 */
class WorkersCheckFindings extends ObjectSet
{
    public function addFinding(string $severity, string $code, string $message, ?string $transport = null): void
    {
        $workersCheckFinding = new WorkersCheckFinding($severity, $code, $message, $transport);
        $this->add($workersCheckFinding);
    }

    public function hasErrors(): bool
    {
        foreach ($this->getElements() as $workersCheckFinding) {
            if ($workersCheckFinding->severity === WorkersCheckFinding::SEVERITY_ERROR) {
                return true;
            }
        }
        return false;
    }
}
