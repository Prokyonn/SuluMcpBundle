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

use Mcp\Capability\Attribute\McpTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Sulu\Article\Domain\Model\Article;
use Sulu\Article\Domain\Repository\ArticleRepositoryInterface;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormGroup;
use Sulu\Content\Application\ContentManager\ContentManagerInterface;
use Sulu\Mcp\Application\Security\ToolPermissionCheckerInterface;
use Sulu\Mcp\Infrastructure\Sulu\Security\ArticleSecurityContextResolver;
use Sulu\Mcp\Tests\Application\TestBundle\Metadata\TestGroupProvider;
use Sulu\Mcp\UserInterface\Mcp\Tool\Article\ArticleListTool;

#[CoversClass(ArticleListTool::class)]
final class ArticleListToolTest extends TestCase
{
    use ProphecyTrait;

    /**
     * @var ObjectProphecy<ArticleRepositoryInterface>
     */
    private ObjectProphecy $articleRepository;

    /**
     * @var ObjectProphecy<ContentManagerInterface>
     */
    private ObjectProphecy $contentManager;

    /**
     * @var ObjectProphecy<ToolPermissionCheckerInterface>
     */
    private ObjectProphecy $permissionChecker;

    private ArticleSecurityContextResolver $articleContextResolver;
    private ArticleListTool $tool;

    protected function setUp(): void
    {
        $this->articleRepository = $this->prophesize(ArticleRepositoryInterface::class);
        $this->contentManager = $this->prophesize(ContentManagerInterface::class);
        $this->permissionChecker = $this->prophesize(ToolPermissionCheckerInterface::class);
        // Default: grant, so existing happy-path tests are unaffected by the new filter.
        $this->permissionChecker->has(Argument::cetera())->willReturn(true);
        // Single-group install owning both template keys used across these tests.
        $this->articleContextResolver = new ArticleSecurityContextResolver(
            new TestGroupProvider(['default' => new FormGroup('default', 'Default', ['article', 'blog'])]),
        );
        $this->tool = new ArticleListTool(
            $this->articleRepository->reveal(),
            $this->contentManager->reveal(),
            $this->permissionChecker->reveal(),
            $this->articleContextResolver,
        );
    }

    public function testListArticlesReturnsPaginatedResults(): void
    {
        $article1 = new Article('uuid-1');
        $article2 = new Article('uuid-2');

        $this->articleRepository->findIdentifiersBy(Argument::cetera())->willReturn(['uuid-1', 'uuid-2']);
        $this->articleRepository->findBy(Argument::cetera())->willReturn([$article1, $article2]);
        $this->articleRepository->countBy(Argument::cetera())->willReturn(5);

        $dimensionContent = $article1->createDimensionContent();
        $this->contentManager->resolve(Argument::cetera())->willReturn($dimensionContent);
        $this->contentManager->normalize(Argument::cetera())->willReturn(['title' => 'Test']);

        $result = $this->tool->listArticles('en');

        $this->assertCount(2, $result['articles']);
        $this->assertSame(5, $result['total']);
        $this->assertSame(1, $result['page']);
        $this->assertSame(20, $result['limit']);
        $this->assertSame('uuid-1', $result['articles'][0]['uuid']);
        $this->assertSame('uuid-2', $result['articles'][1]['uuid']);
    }

    public function testListArticlesAppliesTemplateFilter(): void
    {
        $this->articleRepository
            ->findIdentifiersBy(
                Argument::that(fn (array $filters): bool => isset($filters['templateKeys'])
                    && ['blog'] === $filters['templateKeys']),
                Argument::any(),
            )
            ->shouldBeCalledOnce()
            ->willReturn([]);
        $this->articleRepository->countBy(Argument::cetera())->willReturn(0);

        $this->tool->listArticles('en', 'blog');
    }

    public function testListArticlesDefaultsPaginationToPage1Limit20(): void
    {
        $this->articleRepository
            ->findIdentifiersBy(
                Argument::that(fn (array $filters): bool => 1 === $filters['page'] && 20 === $filters['limit']),
                Argument::any(),
            )
            ->shouldBeCalledOnce()
            ->willReturn([]);
        $this->articleRepository->countBy(Argument::cetera())->willReturn(0);

        $this->tool->listArticles('en');
    }

    public function testListArticlesResolvesAndNormalizesEachArticle(): void
    {
        $article1 = new Article('uuid-1');
        $article2 = new Article('uuid-2');
        $article3 = new Article('uuid-3');

        $this->articleRepository->findIdentifiersBy(Argument::cetera())->willReturn(['uuid-1', 'uuid-2', 'uuid-3']);
        $this->articleRepository->findBy(Argument::cetera())->willReturn([$article1, $article2, $article3]);
        $this->articleRepository->countBy(Argument::cetera())->willReturn(3);

        $dimensionContent = $article1->createDimensionContent();
        $this->contentManager->resolve(Argument::cetera())->shouldBeCalledTimes(3)->willReturn($dimensionContent);
        $this->contentManager->normalize(Argument::cetera())->shouldBeCalledTimes(3)->willReturn(['title' => 'Test']);

        $this->tool->listArticles('en');
    }

    public function testListArticlesMethodHasMcpToolAttribute(): void
    {
        $reflection = new \ReflectionMethod(ArticleListTool::class, 'listArticles');
        $attributes = $reflection->getAttributes(McpTool::class);

        $this->assertCount(1, $attributes, 'listArticles() method must have exactly one #[McpTool] attribute');

        $instance = $attributes[0]->newInstance();
        $this->assertSame('sulu_article_list', $instance->name);
    }

    /**
     * Group scoping must reach the query, not filter rows afterwards, or `total`
     * (COUNT DISTINCT) could count articles from a group the user can't read.
     */
    public function testListArticlesScopesQueryToPermittedGroupTemplates(): void
    {
        // Two groups so "default" and "blog" resolve to distinct security contexts.
        $contextResolver = new ArticleSecurityContextResolver(new TestGroupProvider([
            'default' => (new FormGroup('default', 'Default'))->withTemplate('default'),
            'blog' => (new FormGroup('blog', 'Blog'))->withTemplate('blog'),
        ]));

        $permissionChecker = $this->prophesize(ToolPermissionCheckerInterface::class);
        $permissionChecker->has('sulu.article.articles', Argument::cetera())->willReturn(true);
        $permissionChecker->has(Argument::cetera())->willReturn(false);

        $onlyDefaultTemplate = static fn (array $filters): bool => ['default'] === ($filters['templateKeys'] ?? null);

        $this->articleRepository
            ->findIdentifiersBy(Argument::that($onlyDefaultTemplate), Argument::any())
            ->shouldBeCalledOnce()
            ->willReturn([]);
        $this->articleRepository
            ->countBy(Argument::that($onlyDefaultTemplate))
            ->shouldBeCalledOnce()
            ->willReturn(0);

        $tool = new ArticleListTool(
            $this->articleRepository->reveal(),
            $this->contentManager->reveal(),
            $permissionChecker->reveal(),
            $contextResolver,
        );

        $tool->listArticles('en');
    }

    public function testListArticlesReturnsEmptyWhenNoArticleGroupIsPermitted(): void
    {
        $permissionChecker = $this->prophesize(ToolPermissionCheckerInterface::class);
        $permissionChecker->has(Argument::cetera())->willReturn(false);

        $this->articleRepository->findIdentifiersBy(Argument::cetera())->shouldNotBeCalled();

        $tool = new ArticleListTool(
            $this->articleRepository->reveal(),
            $this->contentManager->reveal(),
            $permissionChecker->reveal(),
            $this->articleContextResolver,
        );

        $result = $tool->listArticles('en');

        $this->assertSame([], $result['articles']);
        $this->assertSame(0, $result['total']);
    }
}
