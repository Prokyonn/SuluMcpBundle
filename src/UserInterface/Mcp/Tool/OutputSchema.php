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

namespace Sulu\Mcp\UserInterface\Mcp\Tool;

/**
 * @internal
 */
final class OutputSchema
{
    public const PAGINATION_PROPERTIES = [
        'total' => ['type' => 'integer'],
        'page' => ['type' => 'integer'],
        'limit' => ['type' => 'integer'],
    ];

    public const ERROR_PROPERTY = ['type' => 'string'];

    public const HINT_PROPERTY = ['type' => 'string'];

    public const FREEFORM_OBJECT = ['type' => 'object'];
}
