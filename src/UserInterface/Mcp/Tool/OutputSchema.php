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
 * Reusable JSON Schema property fragments for `#[McpTool(outputSchema: ...)]`
 * declarations, so the same envelope shapes aren't copy-pasted across tools.
 *
 * Kept to plain constants deliberately: this is shared vocabulary, not a
 * schema-building DSL.
 *
 * @internal
 */
final class OutputSchema
{
    /**
     * `total`/`page`/`limit` properties shared by paginated list envelopes.
     */
    public const PAGINATION_PROPERTIES = [
        'total' => ['type' => 'integer'],
        'page' => ['type' => 'integer'],
        'limit' => ['type' => 'integer'],
    ];

    /**
     * The `error` property returned on a tool's failure path.
     */
    public const ERROR_PROPERTY = ['type' => 'string'];

    /**
     * The `hint` property returned alongside `error`, or standalone on other
     * advisory paths (e.g. permission-scoped empty results).
     */
    public const HINT_PROPERTY = ['type' => 'string'];

    /**
     * A deliberately permissive object, used where the response carries Sulu
     * content whose keys depend on the project's template configuration and
     * cannot be enumerated up front.
     */
    public const FREEFORM_OBJECT = ['type' => 'object'];
}
