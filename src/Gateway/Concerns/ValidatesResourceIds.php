<?php

namespace Laravel\Ai\Gateway\Concerns;

use InvalidArgumentException;

trait ValidatesResourceIds
{
    /**
     * Ensure the given provider resource ID is a single URL path segment.
     *
     * @throws InvalidArgumentException
     */
    protected function resourceId(string $id): string
    {
        if (preg_match('/^(?!\.{1,2}$)[A-Za-z0-9._~-]+$/', $id) !== 1) {
            throw new InvalidArgumentException("[{$id}] is not a valid provider resource ID.");
        }

        return $id;
    }
}
