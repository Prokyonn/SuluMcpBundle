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

namespace Sulu\Mcp\Infrastructure\Sulu\Metadata;

use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FieldMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\ItemMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\SchemaMetadataProvider;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\SectionMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\TypedFormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\MetadataProviderInterface;
use Sulu\Mcp\Application\Metadata\FieldSchemaGeneratorInterface;

/**
 * Wraps Sulu's `@internal` SchemaMetadata generator (SchemaMetadataProvider +
 * PropertyMetadataMapperRegistry) — the same engine the admin UI uses to
 * validate form data — and adds the two things it doesn't do on its own:
 *
 *  - an `x-sulu-type` (and, for blocks, `title`) annotation per property, since
 *    generated JSON Schema only expresses JSON types, never Sulu field types;
 *  - a `definitions` map for every global block reachable from $items, with a
 *    cycle guard. Sulu's own definitions pass (GlobalBlocksTypedFormMetadataVisitor)
 *    recurses into a referenced global block's own items looking for further
 *    global block references and has NO cycle protection of its own — a self-
 *    or mutually-referencing global block makes it recurse forever. That is the
 *    one guard this adapter still needs; a plain (non-global) block type can
 *    never truly cycle, because its fields come from literal, necessarily-finite
 *    XML nesting rather than a name lookup.
 *
 * This is the only class allowed to depend on Sulu's `@internal`
 * `Sulu\Bundle\AdminBundle\Metadata\SchemaMetadata\*` classes and
 * `SchemaMetadataProvider` — everything else in this bundle goes through
 * {@see FieldSchemaGeneratorInterface}.
 *
 * @internal
 */
final class SchemaMetadataAdapter implements FieldSchemaGeneratorInterface
{
    /** @var array<string, TypedFormMetadata> */
    private array $globalBlockCatalogueByLocale = [];

    public function __construct(
        private readonly SchemaMetadataProvider $schemaMetadataProvider,
        private readonly MetadataProviderInterface $formMetadataProvider,
    ) {
    }

    public function generate(array $items, string $locale): array
    {
        $schema = $this->asMap($this->schemaMetadataProvider->getMetadata($items)->toJsonSchema());

        /** @var array<string, array<string, mixed>> $definitions */
        $definitions = [];
        $schema = $this->annotateProperties($schema, $items, $locale, $definitions, []);

        if ([] !== $definitions) {
            $schema['definitions'] = $definitions;
        }

        return $schema;
    }

    /**
     * Merges an `x-sulu-type` (and, for blocks, a `title`) into every property of
     * $node, recursing into block type variants. $node is either the top-level
     * schema or one block type's own object schema — both shapes carry a plain
     * `properties` map, since SchemaMetadataProvider already flattens sections.
     *
     * @param array<string, mixed> $node a schema fragment with a 'properties' map
     * @param ItemMetadata[] $items the items $node's 'properties' were generated from
     * @param array<string, array<string, mixed>> &$definitions accumulator: global block name => its own schema
     * @param array<string, true> $visiting global block names on the current resolution path
     *
     * @return array<string, mixed>
     */
    private function annotateProperties(array $node, array $items, string $locale, array &$definitions, array $visiting): array
    {
        if (!isset($node['properties'])) {
            $properties = [];
        } elseif (\is_array($node['properties'])) {
            $properties = $this->asMap($node['properties']);
        } else {
            // Only reachable if every field in $items is literally named "0", "1", … —
            // Sulu then casts the properties map to an object. Real template field
            // names never look like this, so it is left unannotated rather than guessed at.
            return $node;
        }

        foreach ($this->flattenFields($items) as $field) {
            $name = $field->getName();
            $propertySchema = $this->asMap($properties[$name] ?? null);
            $propertySchema['x-sulu-type'] = $field->getType();

            if ('block' === $field->getType()) {
                $propertySchema = $this->annotateBlockField($propertySchema, $field, $locale, $definitions, $visiting);
            }

            $properties[$name] = $propertySchema;
        }

        if ([] !== $properties) {
            $node['properties'] = $properties;
        }

        return $node;
    }

    /**
     * @param array<string, mixed> $blockPropertySchema
     * @param array<string, array<string, mixed>> &$definitions
     * @param array<string, true> $visiting
     *
     * @return array<string, mixed>
     */
    private function annotateBlockField(array $blockPropertySchema, FieldMetadata $field, string $locale, array &$definitions, array $visiting): array
    {
        $itemsSchema = $blockPropertySchema['items'] ?? null;
        $allOfRaw = \is_array($itemsSchema) ? ($itemsSchema['allOf'] ?? null) : null;
        if (!\is_array($allOfRaw)) {
            return $blockPropertySchema;
        }

        $allOf = [];
        foreach ($allOfRaw as $index => $entryRaw) {
            $entry = $this->asMap($entryRaw);

            $if = $this->asMap($entry['if'] ?? null);
            $ifProperties = $this->asMap($if['properties'] ?? null);
            $typeConst = $this->asMap($ifProperties['type'] ?? null);
            $typeKey = $typeConst['const'] ?? null;

            $blockType = \is_string($typeKey) ? ($field->getTypes()[$typeKey] ?? null) : null;
            if (!$blockType instanceof FormMetadata) {
                $allOf[$index] = $entry;

                continue;
            }

            $then = $this->asMap($entry['then'] ?? null);
            if (isset($then['$ref'])) {
                // Global block: the type's own items are never inlined here (see
                // BlockPropertyMetadataMapper), so there is nothing local to annotate —
                // resolve the definition it points at instead, once per name.
                $this->resolveGlobalBlockDefinition($typeKey, $locale, $definitions, $visiting);
                $allOf[$index] = $entry;

                continue;
            }

            $then = $this->annotateProperties($then, $blockType->getItems(), $locale, $definitions, $visiting);
            $then['title'] = $blockType->getTitle($locale);
            $entry['then'] = $then;
            $allOf[$index] = $entry;
        }

        $itemsSchema = $this->asMap($itemsSchema);
        $itemsSchema['allOf'] = \array_values($allOf);
        $blockPropertySchema['items'] = $itemsSchema;

        return $blockPropertySchema;
    }

    /**
     * @param array<string, array<string, mixed>> &$definitions
     * @param array<string, true> $visiting
     */
    private function resolveGlobalBlockDefinition(string $name, string $locale, array &$definitions, array $visiting): void
    {
        if (isset($definitions[$name]) || isset($visiting[$name])) {
            // Already resolved, or currently being resolved further up the same
            // recursion path — the cycle guard. Either way $ref: '#/definitions/…'
            // already points somewhere valid: at a finished entry, or at the entry
            // the in-progress call further up the stack is still assembling.
            return;
        }

        $globalForm = $this->getGlobalBlockForm($name, $locale);
        if (!$globalForm instanceof FormMetadata) {
            // Referenced but unresolvable (e.g. the global block was removed from the
            // project) — still emit a valid, if permissive, definition so the $ref
            // resolves rather than dangling.
            $definitions[$name] = ['type' => 'object'];

            return;
        }

        $visiting[$name] = true;
        $items = $globalForm->getItems();
        $schema = $this->asMap($this->schemaMetadataProvider->getMetadata($items)->toJsonSchema());
        $schema = $this->annotateProperties($schema, $items, $locale, $definitions, $visiting);
        $schema['title'] = $globalForm->getTitle($locale);
        $definitions[$name] = $schema;
    }

    private function getGlobalBlockForm(string $name, string $locale): ?FormMetadata
    {
        $catalogue = $this->globalBlockCatalogueByLocale[$locale] ??= $this->loadGlobalBlockCatalogue($locale);

        return $catalogue->getForms()[$name] ?? null;
    }

    private function loadGlobalBlockCatalogue(string $locale): TypedFormMetadata
    {
        $metadata = $this->formMetadataProvider->getMetadata('block', $locale, ['ignore_global_blocks' => true]);

        return $metadata instanceof TypedFormMetadata ? $metadata : new TypedFormMetadata();
    }

    /**
     * Flattens SectionMetadata (presentation-only grouping, already flattened the
     * same way by SchemaMetadataProvider itself) down to the FieldMetadata it
     * contains, so every property in the generated schema has a matching
     * FieldMetadata here to read the original Sulu type from.
     *
     * @param ItemMetadata[] $items
     *
     * @return list<FieldMetadata>
     */
    private function flattenFields(array $items): array
    {
        $fields = [];
        foreach ($items as $item) {
            if ($item instanceof SectionMetadata) {
                foreach ($this->flattenFields($item->getItems()) as $nested) {
                    $fields[] = $nested;
                }

                continue;
            }

            if ($item instanceof FieldMetadata) {
                $fields[] = $item;
            }
        }

        return $fields;
    }

    /**
     * Sulu's own toJsonSchema() methods return a bare, undocumented `array`, so
     * every value pulled from one arrives as `mixed` to static analysis. JSON
     * object keys are always strings, so re-keying with `(string)` is a real
     * normalization (not a cast to silence analysis) that also happens to give
     * the rest of this class a precise array<string, mixed> to work with.
     *
     * @return array<string, mixed>
     */
    private function asMap(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        $map = [];
        foreach ($value as $key => $item) {
            $map[(string) $key] = $item;
        }

        return $map;
    }
}
