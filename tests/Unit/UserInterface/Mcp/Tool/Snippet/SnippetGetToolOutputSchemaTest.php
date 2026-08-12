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
use Sulu\Mcp\UserInterface\Mcp\Tool\Snippet\SnippetGetTool;
use Sulu\Snippet\Domain\Exception\SnippetNotFoundException;
use Sulu\Snippet\Domain\Model\SnippetInterface;
use Sulu\Snippet\Domain\Repository\SnippetRepositoryInterface;

#[CoversClass(SnippetGetTool::class)]
final class SnippetGetToolOutputSchemaTest extends TestCase
{
    use OutputSchemaAssertionTrait;
    use ProphecyTrait;

    public function testSuccessResultMatchesOutputSchema(): void
    {
        $snippet = $this->prophesize(SnippetInterface::class);
        $snippet->getUuid()->willReturn('snippet-uuid');

        $dimensionContent = $this->prophesize(DimensionContentInterface::class);

        $snippetRepository = $this->prophesize(SnippetRepositoryInterface::class);
        $snippetRepository->getOneBy(Argument::any(), Argument::any())->willReturn($snippet->reveal());

        $contentManager = $this->prophesize(ContentManagerInterface::class);
        $contentManager->resolve(Argument::any(), Argument::any())->willReturn($dimensionContent->reveal());
        $contentManager->normalize(Argument::any())->willReturn(['title' => 'Footer']);

        $tool = new SnippetGetTool($snippetRepository->reveal(), $contentManager->reveal());

        $result = $tool->getSnippet('en', 'snippet-uuid');

        $this->assertResultMatchesOutputSchema(SnippetGetTool::class, 'getSnippet', $result);
    }

    public function testNotFoundResultMatchesOutputSchema(): void
    {
        $snippetRepository = $this->prophesize(SnippetRepositoryInterface::class);
        $snippetRepository->getOneBy(Argument::any(), Argument::any())
            ->willThrow(new SnippetNotFoundException(['uuid' => 'bad']));

        $contentManager = $this->prophesize(ContentManagerInterface::class);

        $tool = new SnippetGetTool($snippetRepository->reveal(), $contentManager->reveal());

        $result = $tool->getSnippet('en', 'bad');

        $this->assertResultMatchesOutputSchema(SnippetGetTool::class, 'getSnippet', $result);
    }
}
