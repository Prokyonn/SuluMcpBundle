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

namespace Sulu\Mcp\Tests\Unit\UserInterface\Mcp\Tool\Taxonomy;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Sulu\Bundle\CategoryBundle\Api\Category as ApiCategory;
use Sulu\Bundle\CategoryBundle\Category\CategoryManagerInterface;
use Sulu\Mcp\Tests\Unit\UserInterface\Mcp\Tool\OutputSchemaAssertionTrait;
use Sulu\Mcp\UserInterface\Mcp\Tool\Taxonomy\CategoryListTool;

/**
 * Asserts CategoryListTool's real return value against its declared outputSchema.
 */
#[CoversClass(CategoryListTool::class)]
final class CategoryListToolOutputSchemaTest extends TestCase
{
    use OutputSchemaAssertionTrait;
    use ProphecyTrait;

    public function testSuccessResultWithNestedChildrenMatchesOutputSchema(): void
    {
        $child = $this->prophesize(ApiCategory::class);
        $child->getId()->willReturn(2);
        $child->getName()->willReturn('PHP');
        $child->getKey()->willReturn('php');
        $child->getChildren()->willReturn([]);

        $parent = $this->prophesize(ApiCategory::class);
        $parent->getId()->willReturn(1);
        // Real behaviour: Category::getName() can fall back to null even though
        // its own docblock claims a non-nullable string.
        $parent->getName()->willReturn(null);
        $parent->getKey()->willReturn('technology');
        $parent->getChildren()->willReturn([$child->reveal()]);

        $categoryManager = $this->prophesize(CategoryManagerInterface::class);
        $categoryManager->findChildrenByParentId(Argument::any())->willReturn([$parent->reveal()]);
        $categoryManager->getApiObjects(Argument::any(), Argument::any())->willReturn([$parent->reveal()]);

        $tool = new CategoryListTool($categoryManager->reveal());

        $result = $tool->listCategories('en');

        $this->assertResultMatchesOutputSchema(CategoryListTool::class, 'listCategories', $result);
    }

    public function testErrorResultMatchesOutputSchema(): void
    {
        $categoryManager = $this->prophesize(CategoryManagerInterface::class);
        $categoryManager->findChildrenByParentId(Argument::any())->willThrow(new \RuntimeException('DB error'));

        $tool = new CategoryListTool($categoryManager->reveal());

        $result = $tool->listCategories('en');

        $this->assertResultMatchesOutputSchema(CategoryListTool::class, 'listCategories', $result);
    }
}
