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

namespace Sulu\Mcp\Tests\Unit\UserInterface\Mcp\Tool\Page;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Sulu\Content\Application\ContentManager\ContentManagerInterface;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Mcp\Application\Security\ToolPermissionCheckerInterface;
use Sulu\Mcp\Tests\Unit\UserInterface\Mcp\Tool\OutputSchemaAssertionTrait;
use Sulu\Mcp\UserInterface\Mcp\Tool\Page\PageGetTool;
use Sulu\Page\Domain\Exception\PageNotFoundException;
use Sulu\Page\Domain\Model\PageInterface;
use Sulu\Page\Domain\Repository\PageRepositoryInterface;

#[CoversClass(PageGetTool::class)]
final class PageGetToolOutputSchemaTest extends TestCase
{
    use OutputSchemaAssertionTrait;
    use ProphecyTrait;

    public function testSuccessResultMatchesOutputSchema(): void
    {
        $page = $this->prophesize(PageInterface::class);
        $page->getUuid()->willReturn('page-uuid');
        $page->getWebspaceKey()->willReturn('example');

        $dimensionContent = $this->prophesize(DimensionContentInterface::class);

        $pageRepository = $this->prophesize(PageRepositoryInterface::class);
        $pageRepository->getOneBy(Argument::any(), Argument::any())->willReturn($page->reveal());

        $contentManager = $this->prophesize(ContentManagerInterface::class);
        $contentManager->resolve(Argument::any(), Argument::any())->willReturn($dimensionContent->reveal());
        $contentManager->normalize(Argument::any())->willReturn(['title' => 'Test Page']);

        $permissionChecker = $this->prophesize(ToolPermissionCheckerInterface::class);

        $tool = new PageGetTool($pageRepository->reveal(), $contentManager->reveal(), $permissionChecker->reveal());

        $result = $tool->getPage('example', 'en', 'page-uuid');

        $this->assertResultMatchesOutputSchema(PageGetTool::class, 'getPage', $result);
    }

    public function testNotFoundResultMatchesOutputSchema(): void
    {
        $pageRepository = $this->prophesize(PageRepositoryInterface::class);
        $pageRepository->getOneBy(Argument::any(), Argument::any())
            ->willThrow(new PageNotFoundException(['uuid' => 'missing-uuid']));

        $contentManager = $this->prophesize(ContentManagerInterface::class);
        $permissionChecker = $this->prophesize(ToolPermissionCheckerInterface::class);

        $tool = new PageGetTool($pageRepository->reveal(), $contentManager->reveal(), $permissionChecker->reveal());

        $result = $tool->getPage('example', 'en', 'missing-uuid');

        $this->assertResultMatchesOutputSchema(PageGetTool::class, 'getPage', $result);
    }
}
