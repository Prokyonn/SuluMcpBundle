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

namespace Sulu\Mcp\Tests\Unit\UserInterface\Mcp\Resource\Fixture;

use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\ItemMetadata;
use Sulu\Mcp\Application\Metadata\FieldSchemaGeneratorInterface;

/**
 * Real, minimal FieldSchemaGeneratorInterface implementation for tests — records
 * every call and echoes back a schema that identifies exactly which items and
 * locale it was generated for, so TemplatesResource/BlocksResource tests can
 * assert what they hand to the generator without re-testing schema generation
 * itself (that lives in SchemaMetadataAdapterTest).
 *
 * @internal
 */
final class RecordingFieldSchemaGenerator implements FieldSchemaGeneratorInterface
{
    /** @var list<array{items: ItemMetadata[], locale: string}> */
    public array $calls = [];

    public function generate(array $items, string $locale): array
    {
        $this->calls[] = ['items' => $items, 'locale' => $locale];

        return [
            'x-sulu-test-item-names' => \array_map(static fn (ItemMetadata $item): string => $item->getName(), $items),
            'x-sulu-test-locale' => $locale,
        ];
    }
}
