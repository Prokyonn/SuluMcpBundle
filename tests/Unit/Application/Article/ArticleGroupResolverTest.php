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

namespace Sulu\Mcp\Tests\Unit\Application\Article;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Sulu\Article\Domain\Model\Article;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormGroup;
use Sulu\Bundle\AdminBundle\Metadata\GroupProviderInterface;
use Sulu\Content\Application\ContentManager\ContentManagerInterface;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Mcp\Application\Article\ArticleGroupResolver;

#[CoversClass(ArticleGroupResolver::class)]
final class ArticleGroupResolverTest extends TestCase
{
    use ProphecyTrait;

    /**
     * @var ObjectProphecy<GroupProviderInterface>
     */
    private ObjectProphecy $groupProvider;

    /**
     * @var ObjectProphecy<ContentManagerInterface>
     */
    private ObjectProphecy $contentManager;

    private ArticleGroupResolver $resolver;

    protected function setUp(): void
    {
        $this->groupProvider = $this->prophesize(GroupProviderInterface::class);
        $this->contentManager = $this->prophesize(ContentManagerInterface::class);
        $this->resolver = new ArticleGroupResolver($this->groupProvider->reveal(), $this->contentManager->reveal());
    }

    public function testResolveByTemplateReturnsDefaultWhenTemplateIsNull(): void
    {
        $this->groupProvider->getGroups(Argument::any())->shouldNotBeCalled();

        $this->assertSame('default', $this->resolver->resolveByTemplate(null));
    }

    public function testResolveByTemplateReturnsDefaultWhenTemplateIsEmpty(): void
    {
        $this->assertSame('default', $this->resolver->resolveByTemplate(''));
    }

    public function testResolveByTemplateReturnsGroupIdentifierForMatchingTemplate(): void
    {
        $this->groupProvider->getGroups(Article::TEMPLATE_TYPE)->willReturn([
            'default' => new FormGroup('default', 'Default', ['standard']),
            'blog-group' => new FormGroup('blog-group', 'Blog', ['blog', 'news']),
        ]);

        $this->assertSame('blog-group', $this->resolver->resolveByTemplate('blog'));
        $this->assertSame('blog-group', $this->resolver->resolveByTemplate('news'));
        $this->assertSame('default', $this->resolver->resolveByTemplate('standard'));
    }

    public function testResolveByTemplateFallsBackToDefaultForUnknownTemplate(): void
    {
        $this->groupProvider->getGroups(Article::TEMPLATE_TYPE)->willReturn([
            'blog-group' => new FormGroup('blog-group', 'Blog', ['blog']),
        ]);

        $this->assertSame('default', $this->resolver->resolveByTemplate('unknown'));
    }

    public function testResolveByArticleDerivesGroupFromDraftTemplate(): void
    {
        $article = new Article();
        $dimensionContent = $article->createDimensionContent();

        $this->contentManager->resolve($article, [
            'locale' => 'en',
            'stage' => DimensionContentInterface::STAGE_DRAFT,
        ])->shouldBeCalledOnce()->willReturn($dimensionContent);
        $this->contentManager->normalize($dimensionContent)->willReturn(['template' => 'blog']);

        $this->groupProvider->getGroups(Article::TEMPLATE_TYPE)->willReturn([
            'blog-group' => new FormGroup('blog-group', 'Blog', ['blog']),
        ]);

        $this->assertSame('blog-group', $this->resolver->resolveByArticle($article, 'en'));
    }

    public function testResolveByArticleReturnsDefaultWhenTemplateMissing(): void
    {
        $article = new Article();
        $dimensionContent = $article->createDimensionContent();

        $this->contentManager->resolve(Argument::cetera())->willReturn($dimensionContent);
        $this->contentManager->normalize($dimensionContent)->willReturn([]);

        $this->assertSame('default', $this->resolver->resolveByArticle($article, 'en'));
    }

    public function testResolveByArticleFallsBackToDefaultOnException(): void
    {
        $article = new Article();

        $this->contentManager->resolve(Argument::cetera())
            ->willThrow(new \RuntimeException('boom'));

        $this->assertSame('default', $this->resolver->resolveByArticle($article, 'en'));
    }
}
