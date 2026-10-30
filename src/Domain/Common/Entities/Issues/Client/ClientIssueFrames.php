<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Issues\Client;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The frames of a client error, innermost first (at most {@see self::MAX_FRAMES}).
 *
 * @property ClientIssueFrame[] $elements
 * @method ClientIssueFrame|null first()
 * @method ClientIssueFrame[] getElements()
 */
class ClientIssueFrames extends ObjectSet
{
    public const int MAX_FRAMES = 30;

    /** The innermost frame of the application's own code; null when every frame is a library's or the runtime's */
    public function getFirstInAppFrame(): ?ClientIssueFrame
    {
        foreach ($this->getElements() as $clientIssueFrame) {
            if ($clientIssueFrame->inApp) {
                return $clientIssueFrame;
            }
        }
        return null;
    }
}
