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

use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormGroup;
use Sulu\Bundle\AdminBundle\Metadata\GroupProviderInterface;

/**
 * In-memory {@see GroupProviderInterface} returning a fixed set of groups.
 */
final class FakeGroupProvider implements GroupProviderInterface
{
    /**
     * @param array<string, FormGroup> $groups
     */
    public function __construct(private readonly array $groups = [])
    {
    }

    public function getGroups(string $key): array
    {
        return $this->groups;
    }
}
