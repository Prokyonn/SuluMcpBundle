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
class TemplatesResource
{
    /**
     * Caps section/block nesting so a pathological (but acyclic) template cannot
     * blow up the response. Real templates and block libraries never come close —
     * this only bites runaway or malformed metadata.
     */
    private const MAX_DEPTH = 20;

    /** @var array<string, FormMetadata>|null */
    private ?array $globalBlockForms = null;

    public function __construct(
        private readonly MetadataProviderInterface $formMetadataProvider,
    ) {
    }

    /** @return array<string, array<string, mixed>> */
    #[McpResource(
        uri: 'sulu://templates',
        name: 'sulu_templates',
        description: 'Available Sulu templates grouped by content type. Top-level keys are `page`, `article`, and `snippet` (any type with no templates installed is omitted). Each entry maps a template key to its field schema. Use the template key when creating or updating content of that type.',
        mimeType: 'application/json',
    )]
    public function getTemplates(): array
    {
        $result = [];
        foreach (['page', 'article', 'snippet'] as $contentType) {
            $templates = $this->loadTemplatesByType($contentType);
            if ([] !== $templates) {
                $result[$contentType] = $templates;
            }
        }

        return $result;
    }

    /** @return array<string, array<string, mixed>> */
    private function loadTemplatesByType(string $contentType): array
    {
        try {
            $typedMetadata = $this->formMetadataProvider->getMetadata($contentType, 'en', []);
        } catch (\Throwable) {
            return [];
        }

        if (!$typedMetadata instanceof TypedFormMetadata) {
            return [];
        }

        $result = [];
        foreach ($typedMetadata->getForms() as $key => $formMetadata) {
            $result[(string) $key] = $this->normalizeTemplate($formMetadata);
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private function normalizeTemplate(FormMetadata $form): array
    {
        return ['key' => $form->getKey(), 'fields' => $this->normalizeItems($form->getItems(), [], 0)];
    }

    /**
     * Normalizes a list of form items into a flat field list.
     *
     * Sections (`<section>` in the template XML) are presentation-only grouping in
     * Sulu's admin UI — the "content" payload accepted by the create/update tools
     * is a flat map keyed by field name regardless of which section a field is
     * authored under (see ContentNormalizerTrait / PageCreateTool). Flattening here
     * keeps the reported schema honest about that payload shape instead of implying
     * a nested wrapper the client would then have to unwrap. Sections may nest
     * inside sections, so this recurses; the depth cap guards against a runaway
     * (but acyclic) nesting of sections or block types blowing up the payload.
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
            'required' => $item instanceof FieldMetadata && $item->isRequired(),
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
        foreach ($blockField->getTypes() as $typeName => $blockForm) {
            $typeName = (string) $typeName;
            $resolvedForm = $this->resolveBlockForm($typeName, $blockForm);

            if (isset($visiting[$typeName])) {
                $types[$typeName] = [
                    'key' => $typeName,
                    'label' => $resolvedForm->getTitle('en'),
                    'fields' => [],
                    'cyclic' => true,
                ];

                continue;
            }

            if ($depth >= self::MAX_DEPTH) {
                $types[$typeName] = [
                    'key' => $typeName,
                    'label' => $resolvedForm->getTitle('en'),
                    'fields' => [],
                    'truncated' => true,
                ];

                continue;
            }

            $types[$typeName] = [
                'key' => $typeName,
                'label' => $resolvedForm->getTitle('en'),
                'fields' => $this->normalizeItems($resolvedForm->getItems(), $visiting + [$typeName => true], $depth + 1),
            ];
        }

        return $types;
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
}
