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
use Prophecy\PhpUnit\ProphecyTrait;
use Sulu\Bundle\TagBundle\Tag\TagInterface;
use Sulu\Bundle\TagBundle\Tag\TagRepositoryInterface;
use Sulu\Mcp\Tests\Unit\UserInterface\Mcp\Tool\OutputSchemaAssertionTrait;
use Sulu\Mcp\UserInterface\Mcp\Tool\Taxonomy\TagListTool;

#[CoversClass(TagListTool::class)]
final class TagListToolOutputSchemaTest extends TestCase
{
    use OutputSchemaAssertionTrait;
    use ProphecyTrait;

    public function testSuccessResultMatchesOutputSchema(): void
    {
        $tag = $this->prophesize(TagInterface::class);
        $tag->getId()->willReturn(1);
        $tag->getName()->willReturn('tag-1');

        $tagRepository = $this->prophesize(TagRepositoryInterface::class);
        $tagRepository->findAll()->willReturn([$tag->reveal()]);

        $tool = new TagListTool($tagRepository->reveal());

        $result = $tool->listTags();

        $this->assertResultMatchesOutputSchema(TagListTool::class, 'listTags', $result);
    }

    public function testEmptyResultMatchesOutputSchema(): void
    {
        $tagRepository = $this->prophesize(TagRepositoryInterface::class);
        $tagRepository->findAll()->willReturn([]);

        $tool = new TagListTool($tagRepository->reveal());

        $result = $tool->listTags();

        $this->assertResultMatchesOutputSchema(TagListTool::class, 'listTags', $result);
    }

    public function testErrorResultMatchesOutputSchema(): void
    {
        $tagRepository = $this->prophesize(TagRepositoryInterface::class);
        $tagRepository->findAll()->willThrow(new \RuntimeException('DB error'));

        $tool = new TagListTool($tagRepository->reveal());

        $result = $tool->listTags();

        $this->assertResultMatchesOutputSchema(TagListTool::class, 'listTags', $result);
    }
}
