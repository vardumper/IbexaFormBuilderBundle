<?php

declare(strict_types=1);

namespace vardumper\IbexaFormBuilderBundle\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Base for cancellable events. cancel() skips the guarded action without silencing later listeners
 * (unlike stopPropagation(), which would prevent subsequent listeners from observing the event).
 */
abstract class AbstractCancellableEvent extends Event
{
    private bool $cancelled = false;

    public function cancel(): void
    {
        $this->cancelled = true;
    }

    public function isCancelled(): bool
    {
        return $this->cancelled;
    }
}
