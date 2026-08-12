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

namespace Sulu\Mcp\Tests\Unit\UserInterface\Mcp\Tool\Snippet;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Sulu\Content\Application\ContentManager\ContentManagerInterface;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Mcp\Tests\Unit\UserInterface\Mcp\Tool\OutputSchemaAssertionTrait;
use Sulu\Mcp\UserInterface\Mcp\Tool\Snippet\SnippetListTool;
use Sulu\Snippet\Domain\Model\SnippetInterface;
use Sulu\Snippet\Domain\Repository\SnippetRepositoryInterface;

/**
 * Asserts SnippetListTool's real return value against its declared outputSchema.
 */
#[CoversClass(SnippetListTool::class)]
final class SnippetListToolOutputSchemaTest extends TestCase
{
    use OutputSchemaAssertionTrait;
    use ProphecyTrait;

    public function testSuccessResultMatchesOutputSchema(): void
    {
        $snippet = $this->prophesize(SnippetInterface::class);
        $snippet->getUuid()->willReturn('s-uuid');

        $dimensionContent = $this->prophesize(DimensionContentInterface::class);

        $snippetRepository = $this->prophesize(SnippetRepositoryInterface::class);
        $snippetRepository->countBy(Argument::any())->willReturn(1);
        $snippetRepository->findIdentifiersBy(Argument::any(), Argument::any())->willReturn(['s-uuid']);
        $snippetRepository->findBy(Argument::any(), Argument::any(), Argument::any())->willReturn([$snippet->reveal()]);

        $contentManager = $this->prophesize(ContentManagerInterface::class);
        $contentManager->resolve(Argument::any(), Argument::any())->willReturn($dimensionContent->reveal());
        $contentManager->normalize(Argument::any())->willReturn(['title' => 'Footer', 'template' => 'footer']);

        $tool = new SnippetListTool($snippetRepository->reveal(), $contentManager->reveal());

        $result = $tool->listSnippets('en');

        $this->assertResultMatchesOutputSchema(SnippetListTool::class, 'listSnippets', $result);
    }

    public function testEmptyResultMatchesOutputSchema(): void
    {
        $snippetRepository = $this->prophesize(SnippetRepositoryInterface::class);
        $snippetRepository->countBy(Argument::any())->willReturn(0);
        $snippetRepository->findIdentifiersBy(Argument::any(), Argument::any())->willReturn([]);

        $contentManager = $this->prophesize(ContentManagerInterface::class);

        $tool = new SnippetListTool($snippetRepository->reveal(), $contentManager->reveal());

        $result = $tool->listSnippets('en');

        $this->assertResultMatchesOutputSchema(SnippetListTool::class, 'listSnippets', $result);
    }

    public function testErrorResultMatchesOutputSchema(): void
    {
        $snippetRepository = $this->prophesize(SnippetRepositoryInterface::class);
        $snippetRepository->countBy(Argument::any())->willThrow(new \RuntimeException('DB error'));

        $contentManager = $this->prophesize(ContentManagerInterface::class);

        $tool = new SnippetListTool($snippetRepository->reveal(), $contentManager->reveal());

        $result = $tool->listSnippets('en');

        $this->assertResultMatchesOutputSchema(SnippetListTool::class, 'listSnippets', $result);
    }
}
