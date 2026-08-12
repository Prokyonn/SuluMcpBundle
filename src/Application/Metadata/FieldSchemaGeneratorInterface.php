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

namespace Sulu\Mcp\Application\Metadata;

use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\ItemMetadata;

/**
 * Generates a JSON Schema describing the flat content payload a list of
 * template/block-type items accepts, so `sulu_get_context` never has to
 * hand-roll (and risk drifting from) Sulu's own field-type semantics.
 *
 * Port around Sulu's `@internal` SchemaMetadata generator — see
 * `Sulu\Mcp\Infrastructure\Sulu\Metadata\SchemaMetadataAdapter` for the only
 * place allowed to depend on it directly.
 *
 * @internal
 */
interface FieldSchemaGeneratorInterface
{
    /**
     * Every generated schema property carries an `x-sulu-type` keyword next to
     * the standard JSON Schema keywords, holding the original Sulu field type
     * (e.g. `text_line`, `media_selection`) — JSON Schema output only expresses
     * JSON types (`string`, `array`, `object`, …), so this is the only place a
     * client can still learn which Sulu field type produced a property.
     *
     * A `block` property's type-variant branches (and any referenced global
     * block placed under `definitions`) also carry a standard `title` keyword
     * with the block type's label, since that information has nowhere else to
     * live once sections are flattened away.
     *
     * A block field referencing a *global* block type is emitted as
     * `$ref: '#/definitions/<name>'`; the referenced definition is attached to
     * the very same returned array under `definitions`, so the result is a
     * self-contained JSON Schema document — every `$ref` resolves against `#`,
     * i.e. against this array itself.
     *
     * @param ItemMetadata[] $items
     *
     * @return array<string, mixed>
     */
    public function generate(array $items, string $locale): array;
}
