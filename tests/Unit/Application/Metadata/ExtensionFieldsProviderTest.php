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

namespace Sulu\Mcp\Tests\Unit\Application\Metadata;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FieldMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\MetadataProviderInterface;
use Sulu\Mcp\Application\Metadata\ExtensionFieldsProvider;

#[CoversClass(ExtensionFieldsProvider::class)]
final class ExtensionFieldsProviderTest extends TestCase
{
    use ProphecyTrait;

    public function testReturnsSeoAndExcerptFieldsWithStrippedNames(): void
    {
        $provider = $this->prophesize(MetadataProviderInterface::class);
        $provider->getMetadata('content_seo_metadata', Argument::cetera())
            ->willReturn($this->form(['seo/title' => 'text_line', 'seoNoIndex' => 'checkbox'], ['seoNoIndex' => true]));
        $provider->getMetadata('content_excerpt_metadata', Argument::cetera())
            ->willReturn($this->form(['excerpt/image' => 'single_media_selection']));
        $provider->getMetadata('content_excerpt_taxonomies', Argument::cetera())
            ->willReturn($this->form(['excerptCategories' => 'category_selection']));
        $provider->getMetadata(Argument::cetera())->willReturn($this->form([]));

        $resource = new ExtensionFieldsProvider($provider->reveal());
        $result = $resource->getExtensionFields();

        $this->assertSame([
            ['name' => 'title', 'type' => 'text_line', 'label' => 'title', 'required' => false],
            ['name' => 'seoNoIndex', 'type' => 'checkbox', 'label' => 'seoNoIndex', 'required' => true],
        ], $result['seo']);
        $this->assertSame([
            ['name' => 'image', 'type' => 'single_media_selection', 'label' => 'image', 'required' => false],
            ['name' => 'excerptCategories', 'type' => 'category_selection', 'label' => 'excerptCategories', 'required' => false],
        ], $result['excerpt']);
    }

    /**
     * @param array<string,string> $fields name => type
     * @param array<string,bool> $required name => isRequired (defaults to false)
     */
    private function form(array $fields, array $required = []): FormMetadata
    {
        $form = new FormMetadata();
        foreach ($fields as $name => $type) {
            $field = new FieldMetadata($name);
            $field->setType($type);
            $strippedName = \str_contains($name, '/') ? \substr($name, (int) \strrpos($name, '/') + 1) : $name;
            $field->setLabel($strippedName, 'en');
            $field->setRequired($required[$name] ?? false);
            $form->addItem($field);
        }

        return $form;
    }
}
