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

namespace Sulu\Mcp\Tests\Unit\Infrastructure\Sulu\Metadata;

use Mcp\Capability\Discovery\SchemaValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FieldMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\SchemaMetadataProvider;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\SectionMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\TagMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\TypedFormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\SchemaMetadata\PropertyMetadataMapper\BlockPropertyMetadataMapper;
use Sulu\Bundle\AdminBundle\Metadata\SchemaMetadata\PropertyMetadataMapper\NumberPropertyMetadataMapper;
use Sulu\Bundle\AdminBundle\Metadata\SchemaMetadata\PropertyMetadataMapper\TextPropertyMetadataMapper;
use Sulu\Bundle\AdminBundle\Metadata\SchemaMetadata\PropertyMetadataMapperRegistry;
use Sulu\Bundle\AdminBundle\Metadata\SchemaMetadata\PropertyMetadataMinMaxValueResolver;
use Sulu\Mcp\Infrastructure\Sulu\Metadata\SchemaMetadataAdapter;
use Sulu\Mcp\Tests\Unit\UserInterface\Mcp\Resource\Fixture\ArrayMetadataProvider;
use Symfony\Component\DependencyInjection\ServiceLocator;

/**
 * Uses the real Sulu\Bundle\AdminBundle\Metadata\FormMetadata\SchemaMetadataProvider
 * (Sulu's own @internal JSON Schema generator) wired with real property mappers —
 * built manually here rather than through a compiled container, since it is cheap
 * (see buildSchemaMetadataProvider()) — so these tests pin the adapter's actual
 * output against Sulu's real engine, not a stand-in for it. A Sulu upgrade that
 * changes SchemaMetadataProvider's behavior fails these tests loudly and locally.
 */
#[CoversClass(SchemaMetadataAdapter::class)]
final class SchemaMetadataAdapterTest extends TestCase
{
    /**
     * Real registry with the mappers actually registered for text_line, text_area,
     * number and block in Sulu\Bundle\AdminBundle (confirmed via
     * `bin/adminconsole debug:container --tag=sulu_admin.property_metadata_mapper`).
     * Deliberately does NOT register e.g. 'date' or 'checkbox' — real Sulu doesn't
     * either, so those types exercise the untyped fallback below, just like in
     * production.
     */
    private function buildAdapter(array $globalBlocks = []): SchemaMetadataAdapter
    {
        $minMax = new PropertyMetadataMinMaxValueResolver();

        $schemaMetadataProvider = null;
        $locator = new ServiceLocator([
            'text_line' => static fn () => new TextPropertyMetadataMapper($minMax),
            'text_area' => static fn () => new TextPropertyMetadataMapper($minMax),
            'number' => static fn () => new NumberPropertyMetadataMapper(),
            'block' => static function() use (&$schemaMetadataProvider) {
                return new BlockPropertyMetadataMapper($schemaMetadataProvider);
            },
        ]);
        $registry = new PropertyMetadataMapperRegistry($locator);
        $schemaMetadataProvider = new SchemaMetadataProvider($registry);

        $blockCatalogue = new TypedFormMetadata();
        foreach ($globalBlocks as $key => $form) {
            $blockCatalogue->addForm($key, $form);
        }

        return new SchemaMetadataAdapter(
            $schemaMetadataProvider,
            new ArrayMetadataProvider(['block' => $blockCatalogue]),
        );
    }

    private function globalBlockTag(string $blockName): TagMetadata
    {
        $tag = new TagMetadata();
        $tag->setName('sulu.global_block');
        $tag->setAttributes(['global_block' => $blockName]);

        return $tag;
    }

    /** A `<type ref="$name"/>` as it appears embedded in a referencing form: no items, tagged. */
    private function refType(string $name): FormMetadata
    {
        $type = new FormMetadata();
        $type->setKey($name);
        $type->addTag($this->globalBlockTag($name));

        return $type;
    }

    public function testMappedFieldGetsStandardSchemaAndSuluTypeAnnotation(): void
    {
        $title = new FieldMetadata('title');
        $title->setType('text_line');
        $title->setRequired(true);
        $title->setLabel('Title', 'en');

        $schema = $this->buildAdapter()->generate([$title], 'en');

        $this->assertSame('object', $schema['type']);
        $this->assertSame(['title'], $schema['required']);
        $this->assertSame('string', $schema['properties']['title']['type']);
        $this->assertSame('text_line', $schema['properties']['title']['x-sulu-type']);
    }

    public function testUnmappedFieldTypeStillGetsSuluTypeAnnotationWithoutJsonConstraint(): void
    {
        // 'date' has no registered PropertyMetadataMapper in real Sulu either —
        // SchemaMetadataProvider silently omits it from 'properties' entirely.
        $date = new FieldMetadata('publishedAt');
        $date->setType('date');
        $date->setRequired(true);

        $schema = $this->buildAdapter()->generate([$date], 'en');

        $this->assertSame(['publishedAt'], $schema['required']);
        $this->assertArrayHasKey('publishedAt', $schema['properties']);
        $this->assertSame('date', $schema['properties']['publishedAt']['x-sulu-type']);
        $this->assertArrayNotHasKey('type', $schema['properties']['publishedAt']);
    }

    public function testFieldLabelBecomesStandardTitleKeywordAtTopLevel(): void
    {
        // Top-level fields get no 'title' from the adapter itself — the resource
        // classes attach the form's own label as a sibling 'label' key instead,
        // since a top-level schema has nowhere else to hang its own title. Only
        // nested block-type schemas (with no outer envelope of their own) get one.
        $title = new FieldMetadata('title');
        $title->setType('text_line');
        $title->setLabel('Title', 'en');

        $schema = $this->buildAdapter()->generate([$title], 'en');

        $this->assertArrayNotHasKey('title', $schema);
    }

    public function testFlattensFieldsNestedInASection(): void
    {
        $subtitle = new FieldMetadata('subtitle');
        $subtitle->setType('text_line');

        $header = new SectionMetadata('header');
        $header->addItem($subtitle);

        $schema = $this->buildAdapter()->generate([$header], 'en');

        $this->assertArrayHasKey('subtitle', $schema['properties']);
        $this->assertSame('text_line', $schema['properties']['subtitle']['x-sulu-type']);
        $this->assertArrayNotHasKey('header', $schema['properties']);
    }

    public function testFlattensSectionNestedInsideSection(): void
    {
        $b = new FieldMetadata('b');
        $b->setType('text_line');
        $inner = new SectionMetadata('inner');
        $inner->addItem($b);

        $a = new FieldMetadata('a');
        $a->setType('text_line');
        $outer = new SectionMetadata('outer');
        $outer->addItem($a);
        $outer->addItem($inner);

        $schema = $this->buildAdapter()->generate([$outer], 'en');

        $this->assertSame(['a', 'b'], \array_keys($schema['properties']));
    }

    public function testInlineBlockTypeIsAnnotatedRecursivelyWithoutRef(): void
    {
        $content = new FieldMetadata('content');
        $content->setType('text_editor'); // unmapped, exercises the fallback inside a block too
        $textType = new FormMetadata();
        $textType->setKey('text');
        $textType->setTitle('Text', 'en');
        $textType->addItem($content);

        $blocks = new FieldMetadata('blocks');
        $blocks->setType('block');
        $blocks->addType($textType);

        $schema = $this->buildAdapter()->generate([$blocks], 'en');

        $blockSchema = $schema['properties']['blocks'];
        $this->assertSame('block', $blockSchema['x-sulu-type']);
        $this->assertSame('array', $blockSchema['type']);

        $branch = $blockSchema['items']['allOf'][0];
        $this->assertSame('text', $branch['if']['properties']['type']['const']);
        $this->assertArrayNotHasKey('$ref', $branch['then']);
        $this->assertSame('Text', $branch['then']['title']);
        $this->assertSame('text_editor', $branch['then']['properties']['content']['x-sulu-type']);
        $this->assertArrayNotHasKey('definitions', $schema, 'No global block was involved.');
    }

    public function testGlobalBlockReferenceEmitsRefAndDefinition(): void
    {
        $heading = new FieldMetadata('heading');
        $heading->setType('text_line');
        $heading->setRequired(true);
        $globalHeading = new FormMetadata();
        $globalHeading->setKey('hero');
        $globalHeading->setTitle('Hero', 'en');
        $globalHeading->addItem($heading);

        $blocks = new FieldMetadata('blocks');
        $blocks->setType('block');
        $blocks->addType($this->refType('hero'));

        $schema = $this->buildAdapter(['hero' => $globalHeading])->generate([$blocks], 'en');

        $branch = $schema['properties']['blocks']['items']['allOf'][0];
        $this->assertSame(['$ref' => '#/definitions/hero'], $branch['then']);

        $this->assertArrayHasKey('hero', $schema['definitions']);
        $definition = $schema['definitions']['hero'];
        $this->assertSame('Hero', $definition['title']);
        $this->assertSame(['heading'], $definition['required']);
        $this->assertSame('text_line', $definition['properties']['heading']['x-sulu-type']);
    }

    public function testMultipleDistinctGlobalBlocksEachGetTheirOwnDefinition(): void
    {
        $globalA = new FormMetadata();
        $globalA->setKey('a');
        $globalB = new FormMetadata();
        $globalB->setKey('b');

        $blocks = new FieldMetadata('blocks');
        $blocks->setType('block');
        $blocks->addType($this->refType('a'));
        $blocks->addType($this->refType('b'));

        $schema = $this->buildAdapter(['a' => $globalA, 'b' => $globalB])->generate([$blocks], 'en');

        $this->assertSame(['a', 'b'], \array_keys($schema['definitions']));
    }

    public function testGlobalBlockNestedInsideAnotherGlobalBlockGetsAFlatDefinitionToo(): void
    {
        // 'container' embeds a block field referencing 'cta' — not cyclic, just chained.
        $ctaLabel = new FieldMetadata('label');
        $ctaLabel->setType('text_line');
        $globalCta = new FormMetadata();
        $globalCta->setKey('cta');
        $globalCta->addItem($ctaLabel);

        $nestedBlocks = new FieldMetadata('actions');
        $nestedBlocks->setType('block');
        $nestedBlocks->addType($this->refType('cta'));
        $globalContainer = new FormMetadata();
        $globalContainer->setKey('container');
        $globalContainer->addItem($nestedBlocks);

        $blocks = new FieldMetadata('blocks');
        $blocks->setType('block');
        $blocks->addType($this->refType('container'));

        $schema = $this->buildAdapter(['container' => $globalContainer, 'cta' => $globalCta])
            ->generate([$blocks], 'en');

        // Both definitions live flat at the schema root, not nested inside each other,
        // since '#/definitions/…' is a pointer against the whole document's root.
        // (Insertion order reflects resolution order — 'cta' finishes first, being
        // nested inside 'container' — so this compares the key sets, not the order.)
        $this->assertEqualsCanonicalizing(['container', 'cta'], \array_keys($schema['definitions']));
        $this->assertSame(
            ['$ref' => '#/definitions/cta'],
            $schema['definitions']['container']['properties']['actions']['items']['allOf'][0]['then'],
        );
    }

    public function testSelfReferencingGlobalBlockTerminatesInsteadOfRecursingForever(): void
    {
        $nested = new FieldMetadata('inner');
        $nested->setType('block');
        $nested->addType($this->refType('section'));

        $globalSection = new FormMetadata();
        $globalSection->setKey('section');
        $globalSection->setTitle('Section', 'en');
        $globalSection->addItem($nested);

        $blocks = new FieldMetadata('blocks');
        $blocks->setType('block');
        $blocks->addType($this->refType('section'));

        $schema = $this->buildAdapter(['section' => $globalSection])->generate([$blocks], 'en');

        $this->assertSame(['section'], \array_keys($schema['definitions']));
        $definition = $schema['definitions']['section'];
        $this->assertSame('Section', $definition['title']);
        // The self-reference is still a valid $ref back to the same (now finished) definition.
        $this->assertSame(
            ['$ref' => '#/definitions/section'],
            $definition['properties']['inner']['items']['allOf'][0]['then'],
        );
    }

    public function testMutuallyReferencingGlobalBlocksBothTerminate(): void
    {
        $bRef = new FieldMetadata('bField');
        $bRef->setType('block');
        $bRef->addType($this->refType('b'));
        $globalA = new FormMetadata();
        $globalA->setKey('a');
        $globalA->addItem($bRef);

        $aRef = new FieldMetadata('aField');
        $aRef->setType('block');
        $aRef->addType($this->refType('a'));
        $globalB = new FormMetadata();
        $globalB->setKey('b');
        $globalB->addItem($aRef);

        $blocks = new FieldMetadata('blocks');
        $blocks->setType('block');
        $blocks->addType($this->refType('a'));

        $schema = $this->buildAdapter(['a' => $globalA, 'b' => $globalB])->generate([$blocks], 'en');

        // 'b' resolves first (nested inside 'a''s own resolution), so only the key
        // set — not the insertion order — is meaningful here.
        $this->assertEqualsCanonicalizing(['a', 'b'], \array_keys($schema['definitions']));
    }

    public function testUnresolvableGlobalBlockReferenceGetsAPermissivePlaceholderDefinition(): void
    {
        $blocks = new FieldMetadata('blocks');
        $blocks->setType('block');
        $blocks->addType($this->refType('missing'));

        // No global blocks registered at all.
        $schema = $this->buildAdapter([])->generate([$blocks], 'en');

        $this->assertSame(['type' => 'object'], $schema['definitions']['missing']);
    }

    public function testGeneratedSchemaIsValidJsonSchemaAndRefsResolve(): void
    {
        $heading = new FieldMetadata('heading');
        $heading->setType('text_line');
        $heading->setRequired(true);
        $globalHero = new FormMetadata();
        $globalHero->setKey('hero');
        $globalHero->addItem($heading);

        $title = new FieldMetadata('title');
        $title->setType('text_line');
        $title->setRequired(true);

        $blocks = new FieldMetadata('blocks');
        $blocks->setType('block');
        $blocks->addType($this->refType('hero'));

        $schema = $this->buildAdapter(['hero' => $globalHero])->generate([$title, $blocks], 'en');

        $validator = new SchemaValidator();

        $validPayload = [
            'title' => 'Home',
            'blocks' => [
                ['type' => 'hero', 'heading' => 'Welcome'],
            ],
        ];
        $this->assertSame([], $validator->validateAgainstJsonSchema($validPayload, $schema));

        // Omitting 'heading' must be caught — proof the $ref actually resolved to
        // hero's real (required: [heading]) definition rather than dangling.
        $invalidPayload = [
            'title' => 'Home',
            'blocks' => [
                ['type' => 'hero'],
            ],
        ];
        $errors = $validator->validateAgainstJsonSchema($invalidPayload, $schema);
        $this->assertNotSame([], $errors, 'A block missing its required field must fail validation.');
    }
}
