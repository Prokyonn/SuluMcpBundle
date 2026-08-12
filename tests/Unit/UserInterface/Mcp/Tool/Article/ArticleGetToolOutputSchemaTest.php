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

namespace Sulu\Mcp\Tests\Unit\UserInterface\Mcp\Tool\Article;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Sulu\Article\Domain\Exception\ArticleNotFoundException;
use Sulu\Article\Domain\Model\ArticleInterface;
use Sulu\Article\Domain\Repository\ArticleRepositoryInterface;
use Sulu\Bundle\AdminBundle\Metadata\GroupProviderInterface;
use Sulu\Content\Application\ContentManager\ContentManagerInterface;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Content\Domain\Model\TemplateInterface;
use Sulu\Mcp\Application\Security\ToolPermissionCheckerInterface;
use Sulu\Mcp\Infrastructure\Sulu\Security\ArticleSecurityContextResolver;
use Sulu\Mcp\Tests\Unit\UserInterface\Mcp\Tool\OutputSchemaAssertionTrait;
use Sulu\Mcp\UserInterface\Mcp\Tool\Article\ArticleGetTool;

#[CoversClass(ArticleGetTool::class)]
final class ArticleGetToolOutputSchemaTest extends TestCase
{
    use OutputSchemaAssertionTrait;
    use ProphecyTrait;

    public function testSuccessResultMatchesOutputSchema(): void
    {
        $article = $this->prophesize(ArticleInterface::class);
        $article->getUuid()->willReturn('article-uuid');

        $dimensionContent = $this->prophesize(DimensionContentInterface::class);
        $dimensionContent->willImplement(TemplateInterface::class);
        $dimensionContent->getTemplateKey()->willReturn('article');

        $articleRepository = $this->prophesize(ArticleRepositoryInterface::class);
        $articleRepository->getOneBy(Argument::any(), Argument::any())->willReturn($article->reveal());

        $contentManager = $this->prophesize(ContentManagerInterface::class);
        $contentManager->resolve(Argument::any(), Argument::any())->willReturn($dimensionContent->reveal());
        $contentManager->normalize(Argument::any())->willReturn(['title' => 'Test Article', 'template' => 'article']);

        $permissionChecker = $this->prophesize(ToolPermissionCheckerInterface::class);

        $groupProvider = $this->prophesize(GroupProviderInterface::class);
        $groupProvider->getGroups(Argument::any())->willReturn([]);

        $tool = new ArticleGetTool(
            $articleRepository->reveal(),
            $contentManager->reveal(),
            $permissionChecker->reveal(),
            new ArticleSecurityContextResolver($groupProvider->reveal()),
        );

        $result = $tool->getArticle('en', 'article-uuid');

        $this->assertResultMatchesOutputSchema(ArticleGetTool::class, 'getArticle', $result);
    }

    public function testNotFoundResultMatchesOutputSchema(): void
    {
        $articleRepository = $this->prophesize(ArticleRepositoryInterface::class);
        $articleRepository->getOneBy(Argument::any(), Argument::any())
            ->willThrow(new ArticleNotFoundException(['uuid' => 'missing-uuid']));

        $contentManager = $this->prophesize(ContentManagerInterface::class);
        $permissionChecker = $this->prophesize(ToolPermissionCheckerInterface::class);
        $groupProvider = $this->prophesize(GroupProviderInterface::class);
        $groupProvider->getGroups(Argument::any())->willReturn([]);

        $tool = new ArticleGetTool(
            $articleRepository->reveal(),
            $contentManager->reveal(),
            $permissionChecker->reveal(),
            new ArticleSecurityContextResolver($groupProvider->reveal()),
        );

        $result = $tool->getArticle('en', 'missing-uuid');

        $this->assertResultMatchesOutputSchema(ArticleGetTool::class, 'getArticle', $result);
    }
}
