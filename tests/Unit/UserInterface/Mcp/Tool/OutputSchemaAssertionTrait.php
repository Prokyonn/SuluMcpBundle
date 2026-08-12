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

namespace Sulu\Mcp\Tests\Unit\UserInterface\Mcp\Tool;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Discovery\SchemaValidator;

/**
 * Asserts that a tool method's real return value validates against the
 * `outputSchema` it declares on its `#[McpTool]` attribute.
 *
 * Reuses `Mcp\Capability\Discovery\SchemaValidator`, the JSON Schema validator
 * already shipped by `mcp/sdk` (backed by the already-vendored
 * `opis/json-schema`) to validate tool *input* — no new dependency needed to
 * validate output with the same rules.
 *
 * @internal
 */
trait OutputSchemaAssertionTrait
{
    /**
     * @param class-string $toolClass
     * @param array<string, mixed> $result
     */
    private function assertResultMatchesOutputSchema(string $toolClass, string $method, array $result): void
    {
        $reflection = new \ReflectionMethod($toolClass, $method);
        $attributes = $reflection->getAttributes(McpTool::class);
        self::assertCount(1, $attributes, \sprintf('%s::%s must have exactly one #[McpTool] attribute', $toolClass, $method));

        $instance = $attributes[0]->newInstance();
        self::assertIsArray($instance->outputSchema, \sprintf('%s::%s must declare an outputSchema', $toolClass, $method));

        $errors = (new SchemaValidator())->validateAgainstJsonSchema($result, $instance->outputSchema);

        self::assertSame(
            [],
            $errors,
            \sprintf(
                "Result does not match the declared outputSchema for %s::%s:\n%s\n\nResult was:\n%s",
                $toolClass,
                $method,
                \json_encode($errors, \JSON_PRETTY_PRINT),
                \json_encode($result, \JSON_PRETTY_PRINT),
            ),
        );
    }
}
