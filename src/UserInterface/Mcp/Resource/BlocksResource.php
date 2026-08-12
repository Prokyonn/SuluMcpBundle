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
use Sulu\Mcp\Application\Metadata\FieldSchemaGeneratorInterface;

/**
 * @internal
 */
class BlocksResource
{
    /** @var array<string, FormMetadata>|null */
    private ?array $globalBlockForms = null;

    public function __construct(
        private readonly MetadataProviderInterface $formMetadataProvider,
        private readonly FieldSchemaGeneratorInterface $schemaGenerator,
    ) {
    }

    /** @return list<array<string, mixed>> */
    #[McpResource(
        uri: 'sulu://blocks',
        name: 'sulu_blocks',
        description: 'Available block types with their field definitions across all webspaces (per D-02: static URI cannot filter by webspace). Each entry is `{key, label, schema, available_in_templates}`, where `schema` is a JSON Schema for one block instance\'s content and `available_in_templates` shows which templates each block type can be used in. Each schema property also carries `x-sulu-type` — the underlying Sulu field type (see `fieldTypes` in sulu_get_context) — since JSON Schema itself only expresses JSON types.',
        mimeType: 'application/json',
    )]
    public function getBlocks(): array
    {
        /** @var array<string, FormMetadata> $blockForms */
        $blockForms = [];
        /** @var array<string, list<string>> $availableInTemplates */
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

            $this->collectBlockTypes($typedMetadata, $blockForms, $availableInTemplates);
        }

        $result = [];
        foreach ($blockForms as $key => $form) {
            $result[] = [
                'key' => $key,
                'label' => $form->getTitle('en'),
                'schema' => $this->schemaGenerator->generate($form->getItems(), 'en'),
                'available_in_templates' => $availableInTemplates[$key] ?? [],
            ];
        }

        return $result;
    }

    /**
     * @param array<string, FormMetadata> $blockForms           accumulated across content types, keyed by block type name
     * @param array<string, list<string>> $availableInTemplates accumulated template keys per block type name
     */
    private function collectBlockTypes(TypedFormMetadata $typedMetadata, array &$blockForms, array &$availableInTemplates): void
    {
        foreach ($typedMetadata->getForms() as $templateKey => $formMetadata) {
            $templateKey = (string) $templateKey;
            foreach ($this->findBlockFields($formMetadata->getItems()) as $item) {
                foreach ($item->getTypes() as $blockTypeName => $blockForm) {
                    $blockTypeName = (string) $blockTypeName;

                    if (!isset($blockForms[$blockTypeName])) {
                        $blockForms[$blockTypeName] = $this->resolveBlockForm($blockTypeName, $blockForm);
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
    private function findBlockFields(array $items): array
    {
        $blockFields = [];
        foreach ($items as $item) {
            if ($item instanceof SectionMetadata) {
                foreach ($this->findBlockFields($item->getItems()) as $nested) {
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

    /**
     * A block type declared with `<type ref="name"/>` (tagged `sulu.global_block`
     * during parsing, see PropertiesXmlParser) carries no items of its own — the
     * real field list lives in the separately-registered global block form.
     */
    private function resolveBlockForm(string $blockTypeName, FormMetadata $blockForm): FormMetadata
    {
        if (null === $blockForm->findTag('sulu.global_block')) {
            return $blockForm;
        }

        return $this->getGlobalBlockForms()[$blockTypeName] ?? $blockForm;
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
