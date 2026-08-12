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
use Sulu\Mcp\UserInterface\Mcp\Resource\BlocksResource;

#[CoversClass(BlocksResource::class)]
final class BlocksResourceSectionTest extends TestCase
{
    public function testFindsBlockFieldDeclaredInsideSection(): void
    {
        $refTextForm = new FormMetadata();
        $refTextForm->setKey('text');

        $blockField = new FieldMetadata('homeBlocks');
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

        $resource = new BlocksResource(new ArrayMetadataProvider([
            'page' => $pageMetadata,
            'block' => $blockMetadata,
        ]));

        $result = $resource->getBlocks();

        $this->assertCount(1, $result);
        $this->assertSame('text', $result[0]['key']);
        $this->assertContains('homepage', $result[0]['available_in_templates']);
    }

    public function testFlattensSectionInsideBlockTypesOwnFields(): void
    {
        $title = new FieldMetadata('title');
        $title->setType('text_line');

        $triggerTitle = new FieldMetadata('triggerTitle');
        $triggerTitle->setType('text_line');

        $formSection = new SectionMetadata('form');
        $formSection->addItem($triggerTitle);

        $boxForm = new FormMetadata();
        $boxForm->setKey('box');
        $boxForm->setTitle('Box', 'en');
        $boxForm->addItem($title);
        $boxForm->addItem($formSection);

        $blockField = new FieldMetadata('blocks');
        $blockField->setType('block');
        $blockField->addType($boxForm);

        $templateForm = new FormMetadata();
        $templateForm->setKey('default');
        $templateForm->addItem($blockField);

        $pageMetadata = new TypedFormMetadata();
        $pageMetadata->addForm('default', $templateForm);

        $resource = new BlocksResource(new ArrayMetadataProvider(['page' => $pageMetadata]));

        $result = $resource->getBlocks();

        $this->assertCount(1, $result);
        $names = \array_column($result[0]['fields'], 'name');
        $this->assertSame(['title', 'triggerTitle'], $names);
        $this->assertNotContains('form', $names);
    }

    public function testDiscoversBlockTypesInArticleTemplatesToo(): void
    {
        $quoteForm = new FormMetadata();
        $quoteForm->setKey('quote');
        $textField = new FieldMetadata('text');
        $textField->setType('text_editor');
        $quoteForm->addItem($textField);

        $blockField = new FieldMetadata('blocks');
        $blockField->setType('block');
        $blockField->addType($quoteForm);

        $articleForm = new FormMetadata();
        $articleForm->setKey('article_default');
        $articleForm->addItem($blockField);

        $articleMetadata = new TypedFormMetadata();
        $articleMetadata->addForm('article_default', $articleForm);

        $resource = new BlocksResource(new ArrayMetadataProvider(['article' => $articleMetadata]));

        $result = $resource->getBlocks();

        $this->assertCount(1, $result);
        $this->assertSame('quote', $result[0]['key']);
        $this->assertSame(['article_default'], $result[0]['available_in_templates']);
    }

    public function testModeratelyNestedSectionIsNotTruncated(): void
    {
        $blockTypeForm = new FormMetadata();
        $blockTypeForm->setKey('text');

        $blockField = new FieldMetadata('blocks');
        $blockField->setType('block');
        $blockField->addType($blockTypeForm);

        $form = new FormMetadata();
        $form->setKey('default');
        $form->addItem($this->wrapInNestedSections($blockField, 5));

        $typed = new TypedFormMetadata();
        $typed->addForm('default', $form);

        $resource = new BlocksResource(new ArrayMetadataProvider([
            'page' => $typed,
            'block' => new TypedFormMetadata(),
        ]));

        $result = $resource->getBlocks();

        $this->assertCount(1, $result);
    }

    public function testDeeplyNestedAcyclicSectionStopsBlockScanning(): void
    {
        $blockTypeForm = new FormMetadata();
        $blockTypeForm->setKey('text');

        $blockField = new FieldMetadata('blocks');
        $blockField->setType('block');
        $blockField->addType($blockTypeForm);

        $form = new FormMetadata();
        $form->setKey('default');
        $form->addItem($this->wrapInNestedSections($blockField, 25));

        $typed = new TypedFormMetadata();
        $typed->addForm('default', $form);

        $resource = new BlocksResource(new ArrayMetadataProvider(['page' => $typed]));

        $this->assertSame([], $resource->getBlocks());
    }

    private function wrapInNestedSections(FieldMetadata $item, int $depth): SectionMetadata
    {
        $current = new SectionMetadata('section_'.($depth - 1));
        $current->addItem($item);

        for ($i = $depth - 2; $i >= 0; --$i) {
            $wrapper = new SectionMetadata('section_'.$i);
            $wrapper->addItem($current);
            $current = $wrapper;
        }

        return $current;
    }
}
