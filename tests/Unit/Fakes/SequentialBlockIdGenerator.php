<?php

declare(strict_types=1);

/*
 * This file is part of Sulu.
 *
 * (c) Sulu GmbH
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Sulu\Mcp\Tests\Unit\Fakes;

use Sulu\Bundle\AdminBundle\Application\BlockIdGenerator\BlockIdGeneratorInterface;

/**
 * In-memory {@see BlockIdGeneratorInterface} returning a fixed sequence of ids.
 */
final class SequentialBlockIdGenerator implements BlockIdGeneratorInterface
{
    private int $callCount = 0;

    /**
     * @param list<string> $ids
     */
    public function __construct(private readonly array $ids = ['generated-id'])
    {
    }

    public function generateId(): string
    {
        $id = $this->ids[$this->callCount] ?? $this->ids[\array_key_last($this->ids)];
        ++$this->callCount;

        return $id;
    }

    public function callCount(): int
    {
        return $this->callCount;
    }
}
