<?php

declare(strict_types=1);

namespace Crab\Actors;

/**
 * The actors made in one container. Stopping an unused plugin must not
 * construct its service or resolve the broadcast carrier.
 *
 * @internal
 */
final class Lifecycle
{
    public ?Actors $actors = null;
}
