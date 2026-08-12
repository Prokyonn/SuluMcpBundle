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

use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Mcp\Application\Metadata\ExtensionFieldsProvider;
use Sulu\Mcp\Application\Metadata\FieldValueExampleProvider;
use Sulu\Mcp\Application\Security\ToolVisibilityResolver;
use Sulu\Mcp\Application\Security\WebspacePermissionResolver;
use Sulu\Mcp\UserInterface\Mcp\Resource\BlocksResource;
use Sulu\Mcp\UserInterface\Mcp\Resource\TemplatesResource;
use Sulu\Mcp\UserInterface\Mcp\Resource\WebspacesResource;

/**
 * @internal
 */
class GetContextTool
{
    /**
     * Shared by "seoFields" and "excerptFields" — both are
     * ExtensionFieldsProvider field-definition lists of the same shape.
     */
    private const EXTENSION_FIELD_LIST_SCHEMA = [
        'type' => 'array',
        'items' => [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string'],
                'type' => ['type' => 'string'],
                'label' => ['type' => 'string'],
                'required' => ['type' => 'boolean'],
            ],
            'required' => ['name', 'type', 'label', 'required'],
        ],
    ];

    public function __construct(
        private readonly TemplatesResource $templatesResource,
        private readonly BlocksResource $blocksResource,
        private readonly WebspacesResource $webspacesResource,
        private readonly FieldValueExampleProvider $valueExampleProvider,
        private readonly ExtensionFieldsProvider $extensionFieldsProvider,
        private readonly ToolVisibilityResolver $toolVisibilityResolver,
        private readonly WebspacePermissionResolver $webspacePermissionResolver,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'sulu_get_context',
        description: 'Aggregates all CMS context into a single response. Returns templates (grouped by content type: `page`, `article`, `snippet`), block types, webspaces, and a `fieldTypes` legend mapping each field type to one value example/hint (look up a field\'s `type` in the legend to learn how to fill it). Call this once before creating or editing content to get full CMS awareness — including which article templates are available and which URL routing form each template expects (look at the field with type `route` or `page_tree_route`). Also returns `seoFields` and `excerptFields`: the project\'s configured SEO and excerpt field lists (with name, type, label, and required) to use when passing `seo` or `excerpt` data to create/update tools. Also returns `tools`: the full tool catalogue with per-tool availability and, for unavailable ones, the reason and the permissions required. IMPORTANT: pass the "locale" you intend to work in. Sulu roles can be restricted to specific locales, so a tool may be available in one locale and denied in another; without "locale" the catalogue is reported without any locale restriction and may list tools that will be denied when you call them.',
        outputSchema: [
            'type' => 'object',
            'properties' => [
                // Field lists are dynamic per-template/per-block-type structures with
                // recursive "types" nesting; kept permissive rather than modeled exactly.
                'templates' => ['type' => 'object', 'description' => 'Templates grouped by content type (page, article, snippet); each template\'s field list depends on the project configuration.'],
                'blocks' => ['type' => 'array', 'items' => ['type' => 'object'], 'description' => 'Block type definitions; fields depend on the project configuration.'],
                'webspaces' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'key' => ['type' => 'string'],
                            'name' => ['type' => 'string'],
                            'locales' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'url' => ['type' => ['string', 'null']],
                        ],
                        'required' => ['key', 'name', 'locales', 'url'],
                    ],
                ],
                'seoFields' => self::EXTENSION_FIELD_LIST_SCHEMA,
                'excerptFields' => self::EXTENSION_FIELD_LIST_SCHEMA,
                // Legend keyed by field type name; which types are present depends on
                // which templates/blocks are configured.
                'fieldTypes' => ['type' => 'object', 'description' => 'Field-type legend keyed by type name, each entry an {example, hint?} pair.'],
                'tools' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'name' => ['type' => 'string'],
                            'available' => ['type' => 'boolean'],
                            'requires' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'context' => ['type' => 'string'],
                                        'permission' => ['type' => 'string'],
                                    ],
                                    'required' => ['context', 'permission'],
                                ],
                            ],
                            'reason' => ['type' => ['string', 'null']],
                        ],
                        'required' => ['name', 'available', 'requires', 'reason'],
                    ],
                ],
            ],
            // getContext() has a single, unconditional return statement, so all
            // seven top-level keys are always present.
            'required' => ['templates', 'blocks', 'webspaces', 'seoFields', 'excerptFields', 'fieldTypes', 'tools'],
        ],
    )]
    public function getContext(
        #[Schema(description: 'The locale you intend to work in (e.g. "en"). Sulu roles can be locale-restricted, so availability is locale-dependent. Omit only if you do not yet know the locale.')]
        ?string $locale = null,
    ): array {
        $templates = $this->templatesResource->getTemplates();
        $blocks = $this->blocksResource->getBlocks();
        $extensionFields = $this->extensionFieldsProvider->getExtensionFields();

        $permitted = $this->webspacePermissionResolver->permittedWebspaceKeys(PermissionTypes::VIEW, $locale);
        $webspaces = \array_values(\array_filter(
            $this->webspacesResource->getWebspaces(),
            static fn (array $webspace): bool => \in_array($webspace['key'], $permitted, true)
        ));

        return [
            'templates' => $templates,
            'blocks' => $blocks,
            'webspaces' => $webspaces,
            'seoFields' => $extensionFields['seo'],
            'excerptFields' => $extensionFields['excerpt'],
            'fieldTypes' => $this->buildFieldTypeLegend([$templates, $blocks]),
            'tools' => $this->toolVisibilityResolver->describeAll($locale),
        ];
    }

    /**
     * Builds a single value example/hint per field type present in the payload, so the
     * examples are listed once in a legend instead of repeated on every field.
     *
     * @param array<mixed> $structures
     *
     * @return array<string, array<string, mixed>>
     */
    private function buildFieldTypeLegend(array $structures): array
    {
        $presentTypes = [];
        $this->collectTypes($structures, $presentTypes);

        $legend = [];
        foreach (array_keys($presentTypes) as $type) {
            $valueInfo = $this->valueExampleProvider->describe($type);
            if (null === $valueInfo) {
                continue;
            }

            $entry = ['example' => $valueInfo['example']];
            if (null !== $valueInfo['hint']) {
                $entry['hint'] = $valueInfo['hint'];
            }
            $legend[$type] = $entry;
        }

        \ksort($legend);

        return $legend;
    }

    /**
     * @param array<string, true> $types
     */
    private function collectTypes(mixed $node, array &$types): void
    {
        if (!\is_array($node)) {
            return;
        }

        if (isset($node['type']) && \is_string($node['type'])) {
            $types[$node['type']] = true;
        }

        foreach ($node as $value) {
            $this->collectTypes($value, $types);
        }
    }
}
