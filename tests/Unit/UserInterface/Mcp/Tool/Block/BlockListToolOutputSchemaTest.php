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

namespace Sulu\Mcp\Tests\Unit\UserInterface\Mcp\Tool\Block;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Sulu\Article\Domain\Repository\ArticleRepositoryInterface;
use Sulu\Bundle\AdminBundle\Metadata\GroupProviderInterface;
use Sulu\Content\Application\ContentManager\ContentManagerInterface;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Mcp\Application\Content\ContentTypeResolver;
use Sulu\Mcp\Application\Security\ContentSecurityContextResolver;
use Sulu\Mcp\Application\Security\ToolPermissionCheckerInterface;
use Sulu\Mcp\Infrastructure\Sulu\Security\ArticleSecurityContextResolver;
use Sulu\Mcp\Tests\Unit\UserInterface\Mcp\Tool\OutputSchemaAssertionTrait;
use Sulu\Mcp\UserInterface\Mcp\Tool\Block\BlockListTool;
use Sulu\Page\Domain\Model\PageInterface;
use Sulu\Page\Domain\Repository\PageRepositoryInterface;
use Sulu\Snippet\Domain\Repository\SnippetRepositoryInterface;

#[CoversClass(BlockListTool::class)]
final class BlockListToolOutputSchemaTest extends TestCase
{
    use OutputSchemaAssertionTrait;
    use ProphecyTrait;

    private function tool(
        PageRepositoryInterface $pageRepository,
        ContentManagerInterface $contentManager,
        ToolPermissionCheckerInterface $permissionChecker,
    ): BlockListTool {
        $articleRepository = $this->prophesize(ArticleRepositoryInterface::class)->reveal();
        $snippetRepository = $this->prophesize(SnippetRepositoryInterface::class)->reveal();

        $groupProvider = $this->prophesize(GroupProviderInterface::class);
        $groupProvider->getGroups(Argument::any())->willReturn([]);

        return new BlockListTool(
            new ContentTypeResolver($pageRepository, $articleRepository, $snippetRepository),
            $contentManager,
            $permissionChecker,
            new ContentSecurityContextResolver(new ArticleSecurityContextResolver($groupProvider->reveal())),
        );
    }

    public function testSuccessResultMatchesOutputSchema(): void
    {
        $page = $this->prophesize(PageInterface::class);
        $page->getUuid()->willReturn('test-uuid');
        $page->getWebspaceKey()->willReturn('example');

        $pageRepository = $this->prophesize(PageRepositoryInterface::class);
        $pageRepository->getOneBy(Argument::any(), Argument::any())->willReturn($page->reveal());

        $dimensionContent = $this->prophesize(DimensionContentInterface::class);

        $contentManager = $this->prophesize(ContentManagerInterface::class);
        $contentManager->resolve(Argument::any(), Argument::any())->willReturn($dimensionContent->reveal());
        $contentManager->normalize(Argument::any())->willReturn([
            'blocks' => [
                ['_id' => 'a', 'type' => 'text', 'title' => 'Block 1', 'description' => '<p>Content 1</p>'],
                ['_id' => 'b', 'type' => 'image', 'title' => 'Block 2', 'src' => '/img.jpg'],
            ],
        ]);

        $permissionChecker = $this->prophesize(ToolPermissionCheckerInterface::class);

        $tool = $this->tool($pageRepository->reveal(), $contentManager->reveal(), $permissionChecker->reveal());

        $result = $tool->listBlocks('page', 'test-uuid', 'en', 'blocks', 1, 3);

        $this->assertResultMatchesOutputSchema(BlockListTool::class, 'listBlocks', $result);
    }

    public function testErrorResultForInvalidTypeMatchesOutputSchema(): void
    {
        $pageRepository = $this->prophesize(PageRepositoryInterface::class);
        $contentManager = $this->prophesize(ContentManagerInterface::class);
        $permissionChecker = $this->prophesize(ToolPermissionCheckerInterface::class);

        $tool = $this->tool($pageRepository->reveal(), $contentManager->reveal(), $permissionChecker->reveal());

        $result = $tool->listBlocks('invalid', 'test-uuid', 'en', 'blocks');

        $this->assertResultMatchesOutputSchema(BlockListTool::class, 'listBlocks', $result);
    }

    public function testErrorResultForUnknownBlockPropertyMatchesOutputSchema(): void
    {
        $page = $this->prophesize(PageInterface::class);
        $page->getUuid()->willReturn('test-uuid');
        $page->getWebspaceKey()->willReturn('example');

        $pageRepository = $this->prophesize(PageRepositoryInterface::class);
        $pageRepository->getOneBy(Argument::any(), Argument::any())->willReturn($page->reveal());

        $dimensionContent = $this->prophesize(DimensionContentInterface::class);

        $contentManager = $this->prophesize(ContentManagerInterface::class);
        $contentManager->resolve(Argument::any(), Argument::any())->willReturn($dimensionContent->reveal());
        $contentManager->normalize(Argument::any())->willReturn(['blocks' => []]);

        $permissionChecker = $this->prophesize(ToolPermissionCheckerInterface::class);

        $tool = $this->tool($pageRepository->reveal(), $contentManager->reveal(), $permissionChecker->reveal());

        $result = $tool->listBlocks('page', 'test-uuid', 'en', 'nonexistent');

        $this->assertResultMatchesOutputSchema(BlockListTool::class, 'listBlocks', $result);
    }
}
