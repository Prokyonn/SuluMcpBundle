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

namespace Sulu\Mcp\UserInterface\Mcp\Resource;

use Mcp\Capability\Attribute\McpResource;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FieldMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\ItemMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\SectionMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\TypedFormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\MetadataProviderInterface;

/**
 * @internal
 */
class BlocksResource
{
    /**
     * Caps section/block nesting so a pathological (but acyclic) block definition
     * cannot blow up the response. Real block libraries never come close — this
     * only bites runaway or malformed metadata.
     */
    private const MAX_DEPTH = 20;

    /** @var array<string, FormMetadata>|null */
    private ?array $globalBlockForms = null;

    public function __construct(
        private readonly MetadataProviderInterface $formMetadataProvider,
    ) {
    }

    /** @return list<array<string, mixed>> */
    #[McpResource(
        uri: 'sulu://blocks',
        name: 'sulu_blocks',
        description: 'Available block types with their field definitions across all webspaces (per D-02: static URI cannot filter by webspace). Shows which templates each block type can be used in.',
        mimeType: 'application/json',
    )]
    public function getBlocks(): array
    {
        $blockTypes = [];
        $availableInTemplates = [];
        foreach (['page', 'article', 'snippet'] as $contentType) {
            try {
                $typedMetadata = $this->formMetadataProvider->getMetadata($contentType, 'en', []);
            } catch (\Throwable) {
                continue;
            }

            if (!$typedMetadata instanceof TypedFormMetadata) {
                continue;
            }

            $this->collectBlockTypes($typedMetadata, $blockTypes, $availableInTemplates);
        }

        $result = [];
        foreach ($blockTypes as $key => $blockType) {
            $blockType['available_in_templates'] = $availableInTemplates[$key] ?? [];
            $result[] = $blockType;
        }

        return $result;
    }

    /**
     * @param array<string, array<string, mixed>> $blockTypes accumulated across content types, keyed by block type name
     * @param array<string, list<string>> $availableInTemplates accumulated template keys per block type name
     */
    private function collectBlockTypes(TypedFormMetadata $typedMetadata, array &$blockTypes, array &$availableInTemplates): void
    {
        foreach ($typedMetadata->getForms() as $templateKey => $formMetadata) {
            $templateKey = (string) $templateKey;
            foreach ($this->findBlockFields($formMetadata->getItems()) as $item) {
                foreach ($item->getTypes() as $blockTypeName => $blockForm) {
                    $blockTypeName = (string) $blockTypeName;

                    if (!isset($blockTypes[$blockTypeName])) {
                        $resolvedForm = $this->resolveBlockForm($blockTypeName, $blockForm);
                        $blockTypes[$blockTypeName] = [
                            'key' => $blockTypeName,
                            'label' => $resolvedForm->getTitle('en'),
                            'fields' => $this->normalizeItems($resolvedForm->getItems(), [$blockTypeName => true], 0),
                        ];
                        $availableInTemplates[$blockTypeName] = [];
                    }

                    if (!\in_array($templateKey, $availableInTemplates[$blockTypeName], true)) {
                        $availableInTemplates[$blockTypeName][] = $templateKey;
                    }
                }
            }
        }
    }

    /**
     * Finds every block field reachable from $items, descending into sections
     * (`<section>` in the template XML is presentation-only grouping — a block
     * declared inside one is still a real block field).
     *
     * @param ItemMetadata[] $items
     *
     * @return list<FieldMetadata>
     */
    private function findBlockFields(array $items, int $depth = 0): array
    {
        if ($depth >= self::MAX_DEPTH) {
            return [];
        }

        $blockFields = [];
        foreach ($items as $item) {
            if ($item instanceof SectionMetadata) {
                foreach ($this->findBlockFields($item->getItems(), $depth + 1) as $nested) {
                    $blockFields[] = $nested;
                }

                continue;
            }

            if ($item instanceof FieldMetadata && 'block' === $item->getType()) {
                $blockFields[] = $item;
            }
        }

        return $blockFields;
    }

    private function resolveBlockForm(string $blockTypeName, FormMetadata $blockForm): FormMetadata
    {
        if ([] !== $blockForm->getItems()) {
            return $blockForm;
        }

        $globalBlock = $this->getGlobalBlockForms()[$blockTypeName] ?? null;
        if (null !== $globalBlock) {
            return $globalBlock;
        }

        return $blockForm;
    }

    /**
     * @return array<string, FormMetadata>
     */
    private function getGlobalBlockForms(): array
    {
        if (null === $this->globalBlockForms) {
            $blockMetadata = $this->formMetadataProvider->getMetadata('block', 'en', ['ignore_global_blocks' => true]);

            $forms = [];
            if ($blockMetadata instanceof TypedFormMetadata) {
                foreach ($blockMetadata->getForms() as $key => $form) {
                    $forms[(string) $key] = $form;
                }
            }
            $this->globalBlockForms = $forms;
        }

        return $this->globalBlockForms;
    }

    /**
     * Normalizes a list of form items into a flat field list, matching the flat
     * `{fieldName: value}` shape Sulu stores a block's own field values in. A
     * `<section>` inside a block type's definition (see e.g. a "box" block with a
     * `<section name="form">`) is presentation-only grouping, so its children are
     * flattened into the surrounding field list rather than nested under it.
     *
     * @param ItemMetadata[] $items
     * @param array<string, true> $visiting block type names currently on the resolution path
     *
     * @return list<array<string, mixed>>
     */
    private function normalizeItems(array $items, array $visiting, int $depth): array
    {
        $fields = [];
        foreach ($items as $item) {
            if ($item instanceof SectionMetadata) {
                foreach ($this->normalizeSection($item, $visiting, $depth) as $flattened) {
                    $fields[] = $flattened;
                }

                continue;
            }

            $fields[] = $this->normalizeField($item, $visiting, $depth);
        }

        return $fields;
    }

    /**
     * @param array<string, true> $visiting
     *
     * @return list<array<string, mixed>>
     */
    private function normalizeSection(SectionMetadata $section, array $visiting, int $depth): array
    {
        if ($depth >= self::MAX_DEPTH) {
            return [[
                'name' => $section->getName(),
                'type' => 'section',
                'label' => $section->getLabel('en') ?? $section->getName(),
                'truncated' => true,
            ]];
        }

        return $this->normalizeItems($section->getItems(), $visiting, $depth + 1);
    }

    /**
     * @param array<string, true> $visiting block type names currently on the resolution path
     *
     * @return array<string, mixed>
     */
    private function normalizeField(ItemMetadata $item, array $visiting, int $depth): array
    {
        $field = [
            'name' => $item->getName(),
            'type' => $item->getType(),
            'label' => $item->getLabel('en') ?? $item->getName(),
        ];

        if ($item instanceof FieldMetadata && 'block' === $item->getType()) {
            $field['types'] = $this->normalizeBlockTypes($item, $visiting, $depth);
        }

        return $field;
    }

    /**
     * @param array<string, true> $visiting block type names currently on the resolution path
     *
     * @return array<string, array<string, mixed>>
     */
    private function normalizeBlockTypes(FieldMetadata $blockField, array $visiting, int $depth): array
    {
        $types = [];
        foreach ($blockField->getTypes() as $typeName => $nestedBlockForm) {
            $typeName = (string) $typeName;
            $resolvedNested = $this->resolveBlockForm($typeName, $nestedBlockForm);

            if (isset($visiting[$typeName])) {
                $types[$typeName] = [
                    'key' => $typeName,
                    'label' => $resolvedNested->getTitle('en'),
                    'fields' => [],
                    'cyclic' => true,
                ];

                continue;
            }

            if ($depth >= self::MAX_DEPTH) {
                $types[$typeName] = [
                    'key' => $typeName,
                    'label' => $resolvedNested->getTitle('en'),
                    'fields' => [],
                    'truncated' => true,
                ];

                continue;
            }

            $types[$typeName] = [
                'key' => $typeName,
                'label' => $resolvedNested->getTitle('en'),
                'fields' => $this->normalizeItems($resolvedNested->getItems(), $visiting + [$typeName => true], $depth + 1),
            ];
        }

        return $types;
    }
}
