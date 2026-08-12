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

namespace Sulu\Mcp\Tests\Unit\UserInterface\Mcp\Resource;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FieldMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\SectionMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\TypedFormMetadata;
use Sulu\Mcp\Tests\Unit\UserInterface\Mcp\Resource\Fixture\ArrayMetadataProvider;
use Sulu\Mcp\UserInterface\Mcp\Resource\TemplatesResource;

#[CoversClass(TemplatesResource::class)]
final class TemplatesResourceSectionTest extends TestCase
{
    public function testFlattensSectionFieldsIntoParentFieldList(): void
    {
        $title = new FieldMetadata('title');
        $title->setType('text_line');

        $subtitle = new FieldMetadata('subtitle');
        $subtitle->setType('text_line');

        $header = new SectionMetadata('header');
        $header->setLabel('Header', 'en');
        $header->addItem($subtitle);

        $form = new FormMetadata();
        $form->setKey('default');
        $form->addItem($title);
        $form->addItem($header);

        $typed = new TypedFormMetadata();
        $typed->addForm('default', $form);

        $resource = new TemplatesResource(new ArrayMetadataProvider(['page' => $typed]));

        $fields = $resource->getTemplates()['page']['default']['fields'];
        $names = \array_column($fields, 'name');

        $this->assertSame(['title', 'subtitle'], $names);
        $this->assertNotContains('header', $names);
        foreach ($fields as $field) {
            $this->assertNotSame('section', $field['type']);
        }

        $subtitleField = $fields[1];
        $this->assertSame('text_line', $subtitleField['type']);
        $this->assertArrayHasKey('required', $subtitleField);
    }

    public function testFlattensSectionNestedInsideSection(): void
    {
        $a = new FieldMetadata('a');
        $a->setType('text_line');
        $b = new FieldMetadata('b');
        $b->setType('text_line');

        $inner = new SectionMetadata('inner');
        $inner->addItem($b);

        $outer = new SectionMetadata('outer');
        $outer->addItem($a);
        $outer->addItem($inner);

        $form = new FormMetadata();
        $form->setKey('default');
        $form->addItem($outer);

        $typed = new TypedFormMetadata();
        $typed->addForm('default', $form);

        $resource = new TemplatesResource(new ArrayMetadataProvider(['page' => $typed]));

        $fields = $resource->getTemplates()['page']['default']['fields'];

        $this->assertSame(['a', 'b'], \array_column($fields, 'name'));
    }

    public function testBlockFieldInsideSectionStillResolvesBlockTypes(): void
    {
        $refTextForm = new FormMetadata();
        $refTextForm->setKey('text');

        $blockField = new FieldMetadata('blocks');
        $blockField->setType('block');
        $blockField->addType($refTextForm);

        $content = new SectionMetadata('content');
        $content->addItem($blockField);

        $form = new FormMetadata();
        $form->setKey('homepage');
        $form->addItem($content);

        $pageMetadata = new TypedFormMetadata();
        $pageMetadata->addForm('homepage', $form);

        $globalText = new FormMetadata();
        $globalText->setKey('text');
        $globalText->setTitle('Text', 'en');
        $contentField = new FieldMetadata('content');
        $contentField->setType('text_editor');
        $globalText->addItem($contentField);

        $blockMetadata = new TypedFormMetadata();
        $blockMetadata->addForm('text', $globalText);

        $resource = new TemplatesResource(new ArrayMetadataProvider([
            'page' => $pageMetadata,
            'block' => $blockMetadata,
        ]));

        $fields = $resource->getTemplates()['page']['homepage']['fields'];

        $this->assertCount(1, $fields);
        $this->assertSame('blocks', $fields[0]['name']);
        $this->assertArrayHasKey('types', $fields[0]);
        $this->assertArrayHasKey('text', $fields[0]['types']);
        $this->assertSame('content', $fields[0]['types']['text']['fields'][0]['name']);
    }

    public function testModeratelyNestedSectionsAreNotTruncated(): void
    {
        $form = new FormMetadata();
        $form->setKey('default');
        $form->addItem($this->buildNestedSections(5));

        $typed = new TypedFormMetadata();
        $typed->addForm('default', $form);

        $resource = new TemplatesResource(new ArrayMetadataProvider(['page' => $typed]));

        $fields = $resource->getTemplates()['page']['default']['fields'];

        $this->assertSame(['leaf'], \array_column($fields, 'name'));
        $this->assertArrayNotHasKey('truncated', $fields[0]);
    }

    public function testDeeplyNestedAcyclicSectionsAreDepthCapped(): void
    {
        $form = new FormMetadata();
        $form->setKey('default');
        $form->addItem($this->buildNestedSections(25));

        $typed = new TypedFormMetadata();
        $typed->addForm('default', $form);

        $resource = new TemplatesResource(new ArrayMetadataProvider(['page' => $typed]));

        $fields = $resource->getTemplates()['page']['default']['fields'];

        $this->assertCount(1, $fields);
        $this->assertSame('section_20', $fields[0]['name']);
        $this->assertSame('section', $fields[0]['type']);
        $this->assertTrue($fields[0]['truncated']);
        $this->assertNotContains('leaf', \array_column($fields, 'name'));
    }

    private function buildNestedSections(int $depth): SectionMetadata
    {
        $leaf = new FieldMetadata('leaf');
        $leaf->setType('text_line');

        $current = new SectionMetadata('section_'.($depth - 1));
        $current->addItem($leaf);

        for ($i = $depth - 2; $i >= 0; --$i) {
            $wrapper = new SectionMetadata('section_'.$i);
            $wrapper->addItem($current);
            $current = $wrapper;
        }

        return $current;
    }
}
