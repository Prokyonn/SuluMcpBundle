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
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Sulu\Article\Application\Message\ModifyArticleMessage;
use Sulu\Article\Domain\Model\Article;
use Sulu\Article\Domain\Model\ArticleDimensionContent;
use Sulu\Article\Domain\Repository\ArticleRepositoryInterface;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FieldMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormGroup;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\TypedFormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\MetadataProviderInterface;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Content\Application\ContentManager\ContentManagerInterface;
use Sulu\Mcp\Application\Article\ArticleGroupResolver;
use Sulu\Mcp\Application\Content\BlockDataValidator;
use Sulu\Mcp\Application\Content\ContentMetadataMapper;
use Sulu\Mcp\Application\Security\ToolPermissionCheckerInterface;
use Sulu\Mcp\Domain\Exception\PermissionDeniedException;
use Sulu\Mcp\Infrastructure\Sulu\AdminLink\ArticleAdminLinkProvider;
use Sulu\Mcp\Infrastructure\Sulu\Security\ArticleSecurityContextResolver;
use Sulu\Mcp\Infrastructure\Symfony\Routing\AdminLinkGenerator;
use Sulu\Mcp\Tests\Application\TestBundle\Admin\TestViewRegistry;
use Sulu\Mcp\Tests\Unit\Fakes\FakeGroupProvider;
use Sulu\Mcp\Tests\Unit\Fakes\SequentialBlockIdGenerator;
use Sulu\Mcp\UserInterface\Mcp\Tool\Article\ArticleUpdateTool;
use Sulu\Messenger\Infrastructure\Symfony\Messenger\FlushMiddleware\EnableFlushStamp;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\RouterInterface;

#[CoversClass(ArticleUpdateTool::class)]
final class ArticleUpdateToolTest extends TestCase
{
    use ProphecyTrait;

    /** @var ObjectProphecy<MessageBusInterface> */
    private ObjectProphecy $messageBus;

    /** @var ObjectProphecy<ContentManagerInterface> */
    private ObjectProphecy $contentManager;

    /** @var ObjectProphecy<ArticleRepositoryInterface> */
    private ObjectProphecy $articleRepository;

    private SequentialBlockIdGenerator $blockIdGenerator;

    /** @var ObjectProphecy<MetadataProviderInterface> */
    private ObjectProphecy $formMetadataProvider;

    /** @var ObjectProphecy<MetadataProviderInterface> */
    private ObjectProphecy $mapperMetadataProvider;

    private ArticleGroupResolver $articleGroupResolver;

    /** @var ObjectProphecy<ToolPermissionCheckerInterface> */
    private ObjectProphecy $permissionChecker;

    private ArticleSecurityContextResolver $articleContextResolver;
    private ArticleUpdateTool $tool;

    protected function setUp(): void
    {
        $this->messageBus = $this->prophesize(MessageBusInterface::class);
        $this->contentManager = $this->prophesize(ContentManagerInterface::class);
        $this->articleRepository = $this->prophesize(ArticleRepositoryInterface::class);
        $this->blockIdGenerator = new SequentialBlockIdGenerator(['gen-id']);
        $this->formMetadataProvider = $this->prophesize(MetadataProviderInterface::class);
        // Default: provider returns a non-typed metadata so the validator skips strict checks.
        $this->formMetadataProvider->getMetadata(Argument::cetera())->willReturn(new FormMetadata());
        $this->mapperMetadataProvider = $this->prophesize(MetadataProviderInterface::class);
        // Provide Sulu's native SEO/excerpt field names so the mapper places them correctly.
        $this->mapperMetadataProvider->getMetadata('content_seo_metadata', Argument::cetera())->willReturn(
            $this->makeFormMeta(['seo/title', 'seo/description', 'seo/keywords', 'seo/canonicalUrl', 'seoNoIndex', 'seoNoFollow', 'seoHideInSitemap']),
        );
        $this->mapperMetadataProvider->getMetadata('content_excerpt_metadata', Argument::cetera())->willReturn(
            $this->makeFormMeta(['excerpt/title', 'excerpt/more', 'excerpt/description', 'excerpt/icon', 'excerpt/image']),
        );
        $this->mapperMetadataProvider->getMetadata('content_excerpt_taxonomies', Argument::cetera())->willReturn(
            $this->makeFormMeta(['excerptCategories', 'excerptTags']),
        );
        $this->mapperMetadataProvider->getMetadata(Argument::cetera())->willReturn($this->makeFormMeta([]));
        $router = $this->prophesize(RouterInterface::class);
        $router->generate(Argument::cetera())->willReturn('https://example.com/admin/');
        $adminLinkGenerator = new AdminLinkGenerator($router->reveal(), [new ArticleAdminLinkProvider(new TestViewRegistry())]);
        $this->articleGroupResolver = new ArticleGroupResolver(new FakeGroupProvider(), $this->contentManager->reveal());
        $this->permissionChecker = $this->prophesize(ToolPermissionCheckerInterface::class);
        $this->articleContextResolver = new ArticleSecurityContextResolver(new FakeGroupProvider());
        $this->tool = new ArticleUpdateTool(
            $this->messageBus->reveal(),
            $this->contentManager->reveal(),
            $this->articleRepository->reveal(),
            new BlockDataValidator($this->formMetadataProvider->reveal()),
            $this->blockIdGenerator,
            new ContentMetadataMapper($this->mapperMetadataProvider->reveal()),
            $adminLinkGenerator,
            $this->articleGroupResolver,
            $this->permissionChecker->reveal(),
            $this->articleContextResolver,
        );
    }

    /** @param list<string> $names */
    private function makeFormMeta(array $names): FormMetadata
    {
        $form = new FormMetadata();
        foreach ($names as $name) {
            $form->addItem(new FieldMetadata($name));
        }

        return $form;
    }

    private function dispatchHandled(Article $article): \Closure
    {
        return static fn (array $args): Envelope => $args[0]->with(new HandledStamp($article, 'handler'));
    }

    private function dimensionContentWithTemplate(Article $article, string $templateKey): ArticleDimensionContent
    {
        $dimensionContent = $article->createDimensionContent();
        $dimensionContent->setTemplateKey($templateKey);

        return $dimensionContent;
    }

    public function testUpdateArticleReadsCurrentStateMergesAndDispatches(): void
    {
        $currentArticle = new Article('uuid-1');
        $updatedArticle = new Article('uuid-1');

        $this->articleRepository->getOneBy(Argument::cetera())->willReturn($currentArticle);

        $this->contentManager->resolve(Argument::cetera())->willReturn($this->dimensionContentWithTemplate($currentArticle, 'blog'));
        $this->contentManager->normalize(Argument::cetera())->willReturn(['title' => 'Old Title', 'template' => 'blog']);

        $this->messageBus->dispatch(Argument::cetera())->will(static function (array $args) use ($updatedArticle): Envelope {
            /** @var Envelope $envelope */
            $envelope = $args[0];
            $stamps = $envelope->all();
            self::assertArrayHasKey(EnableFlushStamp::class, $stamps);

            return $envelope->with(new HandledStamp($updatedArticle, 'handler'));
        })->shouldBeCalledOnce();

        $result = $this->tool->updateArticle('uuid-1', 'en', 'New Title');

        $this->assertTrue($result['success']);
        $this->assertSame('uuid-1', $result['uuid']);
        $this->assertSame('https://example.com/admin/#/en/default/uuid-1', $result['admin_url']);
    }

    public function testUpdateArticleMergesContentOverCurrentData(): void
    {
        $currentArticle = new Article();
        $updatedArticle = new Article('uuid-1');

        $this->articleRepository->getOneBy(Argument::cetera())->willReturn($currentArticle);

        $this->contentManager->resolve(Argument::cetera())->willReturn($this->dimensionContentWithTemplate($currentArticle, 'article'));
        $this->contentManager->normalize(Argument::cetera())->willReturn(['title' => 'Old', 'article' => '<p>Old</p>']);

        $this->messageBus->dispatch(Argument::cetera())->will($this->dispatchHandled($updatedArticle))->shouldBeCalledOnce();

        $result = $this->tool->updateArticle('uuid-1', 'en', null, null, ['article' => '<p>New</p>']);

        $this->assertTrue($result['success']);
    }

    public function testUpdateArticleReturnsErrorOnException(): void
    {
        $this->articleRepository->getOneBy(Argument::cetera())
            ->willThrow(new \RuntimeException('Article not found'));

        $result = $this->tool->updateArticle('uuid-1', 'en', 'Title');

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('Article not found', $result['error']);
        $this->assertArrayHasKey('hint', $result);
        $this->assertArrayNotHasKey('success', $result);
    }

    public function testUpdateArticleMethodHasMcpToolAttribute(): void
    {
        $reflection = new \ReflectionMethod(ArticleUpdateTool::class, 'updateArticle');
        $attributes = $reflection->getAttributes(McpTool::class);

        $this->assertCount(1, $attributes, 'updateArticle() must have exactly one #[McpTool] attribute');

        $instance = $attributes[0]->newInstance();
        $this->assertSame('sulu_article_update', $instance->name);
    }

    public function testUpdateArticleThrowsToolCallExceptionWhenPermissionDenied(): void
    {
        $currentArticle = new Article();
        $this->articleRepository->getOneBy(Argument::cetera())->willReturn($currentArticle);

        $this->contentManager->resolve(Argument::cetera())->willReturn($this->dimensionContentWithTemplate($currentArticle, 'article'));

        $this->permissionChecker->check(Argument::cetera())
            ->willThrow(new PermissionDeniedException('sulu.article.articles', PermissionTypes::EDIT, 'en'));

        $this->messageBus->dispatch(Argument::cetera())->shouldNotBeCalled();

        $this->expectException(ToolCallException::class);

        $this->tool->updateArticle('uuid-1', 'en', 'New Title');
    }

    public function testUpdateArticleDeniesTemplateChangeIntoUnpermittedGroup(): void
    {
        $groupProvider = new FakeGroupProvider([
            (new FormGroup('default', 'Default'))->withTemplate('article'),
            (new FormGroup('blog', 'Blog'))->withTemplate('blog_article'),
        ]);
        $contextResolver = new ArticleSecurityContextResolver($groupProvider);

        $router = $this->prophesize(RouterInterface::class);
        $router->generate(Argument::cetera())->willReturn('https://example.com/admin/');
        $tool = new ArticleUpdateTool(
            $this->messageBus->reveal(),
            $this->contentManager->reveal(),
            $this->articleRepository->reveal(),
            new BlockDataValidator($this->formMetadataProvider->reveal()),
            $this->blockIdGenerator,
            new ContentMetadataMapper($this->mapperMetadataProvider->reveal()),
            new AdminLinkGenerator($router->reveal(), [new ArticleAdminLinkProvider(new TestViewRegistry())]),
            $this->articleGroupResolver,
            $this->permissionChecker->reveal(),
            $contextResolver,
        );

        $currentArticle = new Article();
        $this->articleRepository->getOneBy(Argument::cetera())->willReturn($currentArticle);

        $this->contentManager->resolve(Argument::cetera())->willReturn($this->dimensionContentWithTemplate($currentArticle, 'article'));

        // User has EDIT on the base group (source context) but not on the blog group (target context).
        $this->permissionChecker->check(Argument::cetera())->will(static function (array $args): void {
            [$context, $permission, $locale] = [$args[0], $args[1], $args[2] ?? null];
            if ('sulu.article.articles_blog' === $context) {
                throw new PermissionDeniedException($context, $permission, $locale);
            }
        });

        $this->messageBus->dispatch(Argument::cetera())->shouldNotBeCalled();

        $this->expectException(ToolCallException::class);

        $tool->updateArticle('uuid-1', 'en', null, 'blog_article');
    }

    public function testUpdateArticleAllowsTemplateChangeIntoPermittedGroup(): void
    {
        $groupProvider = new FakeGroupProvider([
            (new FormGroup('default', 'Default'))->withTemplate('article'),
            (new FormGroup('blog', 'Blog'))->withTemplate('blog_article'),
        ]);
        $contextResolver = new ArticleSecurityContextResolver($groupProvider);

        $router = $this->prophesize(RouterInterface::class);
        $router->generate(Argument::cetera())->willReturn('https://example.com/admin/');
        $tool = new ArticleUpdateTool(
            $this->messageBus->reveal(),
            $this->contentManager->reveal(),
            $this->articleRepository->reveal(),
            new BlockDataValidator($this->formMetadataProvider->reveal()),
            $this->blockIdGenerator,
            new ContentMetadataMapper($this->mapperMetadataProvider->reveal()),
            new AdminLinkGenerator($router->reveal(), [new ArticleAdminLinkProvider(new TestViewRegistry())]),
            $this->articleGroupResolver,
            $this->permissionChecker->reveal(),
            $contextResolver,
        );

        $currentArticle = new Article();
        $updatedArticle = new Article('uuid-1');
        $this->articleRepository->getOneBy(Argument::cetera())->willReturn($currentArticle);

        $this->contentManager->resolve(Argument::cetera())->willReturn($this->dimensionContentWithTemplate($currentArticle, 'article'));
        $this->contentManager->normalize(Argument::cetera())->willReturn(['title' => 'Old', 'template' => 'article']);

        // User has EDIT on both the base group (source) and the blog group (target).
        $checkedContexts = [];
        $this->permissionChecker->check(Argument::cetera())->will(static function (array $args) use (&$checkedContexts): void {
            $checkedContexts[] = $args[0];
        });

        $this->messageBus->dispatch(Argument::cetera())->will($this->dispatchHandled($updatedArticle))->shouldBeCalledOnce();

        $result = $tool->updateArticle('uuid-1', 'en', null, 'blog_article');

        $this->assertTrue($result['success']);
        $this->assertSame(['sulu.article.articles', 'sulu.article.articles_blog'], $checkedContexts);
    }

    public function testUpdateArticleIgnoresContentTemplateSmuggling(): void
    {
        // Regression guard: only the top-level `template` arg may request a group change;
        // content.template must have zero effect on the written template.
        $groupProvider = new FakeGroupProvider([
            (new FormGroup('default', 'Default'))->withTemplate('article'),
            (new FormGroup('blog', 'Blog'))->withTemplate('blog_article'),
        ]);
        $contextResolver = new ArticleSecurityContextResolver($groupProvider);

        $router = $this->prophesize(RouterInterface::class);
        $router->generate(Argument::cetera())->willReturn('https://example.com/admin/');
        $tool = new ArticleUpdateTool(
            $this->messageBus->reveal(),
            $this->contentManager->reveal(),
            $this->articleRepository->reveal(),
            new BlockDataValidator($this->formMetadataProvider->reveal()),
            $this->blockIdGenerator,
            new ContentMetadataMapper($this->mapperMetadataProvider->reveal()),
            new AdminLinkGenerator($router->reveal(), [new ArticleAdminLinkProvider(new TestViewRegistry())]),
            $this->articleGroupResolver,
            $this->permissionChecker->reveal(),
            $contextResolver,
        );

        $currentArticle = new Article();
        $updatedArticle = new Article('uuid-1');
        $this->articleRepository->getOneBy(Argument::cetera())->willReturn($currentArticle);

        $this->contentManager->resolve(Argument::cetera())->willReturn($this->dimensionContentWithTemplate($currentArticle, 'article'));
        $this->contentManager->normalize(Argument::cetera())->willReturn(['title' => 'Old', 'template' => 'article']);

        // User has EDIT only on the base group; content can no longer influence the written
        // template, so the (denied) blog-group target check must never fire.
        $checkedContexts = [];
        $this->permissionChecker->check(Argument::cetera())->will(static function (array $args) use (&$checkedContexts): void {
            $checkedContexts[] = $args[0];
            if ('sulu.article.articles_blog' === $args[0]) {
                throw new PermissionDeniedException($args[0], $args[1], $args[2] ?? null);
            }
        });

        $capturedData = null;
        $this->messageBus->dispatch(Argument::cetera())->will(static function (array $args) use ($updatedArticle, &$capturedData): Envelope {
            /** @var Envelope $envelope */
            $envelope = $args[0];
            $capturedData = $envelope->getMessage()->getData();

            return $envelope->with(new HandledStamp($updatedArticle, 'handler'));
        })->shouldBeCalledOnce();

        // Bypass attempt: no top-level `template` arg -- the template is smuggled via content.template.
        $result = $tool->updateArticle('uuid-1', 'en', null, null, ['template' => 'blog_article']);

        $this->assertTrue($result['success']);
        $this->assertSame(['sulu.article.articles'], $checkedContexts);
        $this->assertSame('article', $capturedData['template']);
    }

    public function testUpdateArticleIgnoresNullContentTemplateSmuggling(): void
    {
        // Regression guard: content.template=null used to null out $data['template'], skip the
        // target-group check, and let Sulu default the template — silently moving the group.
        $groupProvider = new FakeGroupProvider([
            (new FormGroup('default', 'Default'))->withTemplate('article'),
            (new FormGroup('blog', 'Blog'))->withTemplate('blog_article'),
        ]);
        $contextResolver = new ArticleSecurityContextResolver($groupProvider);

        $router = $this->prophesize(RouterInterface::class);
        $router->generate(Argument::cetera())->willReturn('https://example.com/admin/');
        $tool = new ArticleUpdateTool(
            $this->messageBus->reveal(),
            $this->contentManager->reveal(),
            $this->articleRepository->reveal(),
            new BlockDataValidator($this->formMetadataProvider->reveal()),
            $this->blockIdGenerator,
            new ContentMetadataMapper($this->mapperMetadataProvider->reveal()),
            new AdminLinkGenerator($router->reveal(), [new ArticleAdminLinkProvider(new TestViewRegistry())]),
            $this->articleGroupResolver,
            $this->permissionChecker->reveal(),
            $contextResolver,
        );

        $currentArticle = new Article();
        $updatedArticle = new Article('uuid-1');
        $this->articleRepository->getOneBy(Argument::cetera())->willReturn($currentArticle);

        $this->contentManager->resolve(Argument::cetera())->willReturn($this->dimensionContentWithTemplate($currentArticle, 'article'));
        $this->contentManager->normalize(Argument::cetera())->willReturn(['title' => 'Old', 'template' => 'article']);

        $checkedContexts = [];
        $this->permissionChecker->check(Argument::cetera())->will(static function (array $args) use (&$checkedContexts): void {
            $checkedContexts[] = $args[0];
            if ('sulu.article.articles_blog' === $args[0]) {
                throw new PermissionDeniedException($args[0], $args[1], $args[2] ?? null);
            }
        });

        $capturedData = null;
        $this->messageBus->dispatch(Argument::cetera())->will(static function (array $args) use ($updatedArticle, &$capturedData): Envelope {
            /** @var Envelope $envelope */
            $envelope = $args[0];
            $capturedData = $envelope->getMessage()->getData();

            return $envelope->with(new HandledStamp($updatedArticle, 'handler'));
        })->shouldBeCalledOnce();

        $result = $tool->updateArticle('uuid-1', 'en', null, null, ['template' => null, 'article' => '<p>New</p>']);

        $this->assertTrue($result['success']);
        $this->assertSame(['sulu.article.articles'], $checkedContexts);
        $this->assertSame('article', $capturedData['template']);
    }

    public function testUpdateArticleForcesAuthorizedLocaleOverContentSmuggling(): void
    {
        $currentArticle = new Article();
        $updatedArticle = new Article('uuid-1');

        $this->articleRepository->getOneBy(Argument::cetera())->willReturn($currentArticle);

        $this->contentManager->resolve(Argument::cetera())->willReturn($this->dimensionContentWithTemplate($currentArticle, 'article'));
        $this->contentManager->normalize(Argument::cetera())->willReturn(['title' => 'Old', 'template' => 'article']);

        $capturedData = null;
        $this->messageBus->dispatch(Argument::cetera())->will(static function (array $args) use ($updatedArticle, &$capturedData): Envelope {
            /** @var Envelope $envelope */
            $envelope = $args[0];
            $capturedData = $envelope->getMessage()->getData();

            return $envelope->with(new HandledStamp($updatedArticle, 'handler'));
        })->shouldBeCalledOnce();

        // Caller is authorized for locale 'en' only; content.locale attempts to smuggle 'de'.
        $result = $this->tool->updateArticle('uuid-1', 'en', null, null, ['locale' => 'de', 'article' => '<p>New</p>']);

        $this->assertTrue($result['success']);
        $this->assertSame('en', $capturedData['locale']);
    }

    public function testUpdateArticleAllowsSameGroupContentEditWithoutTemplateChange(): void
    {
        $groupProvider = new FakeGroupProvider([
            (new FormGroup('default', 'Default'))->withTemplate('article'),
            (new FormGroup('blog', 'Blog'))->withTemplate('blog_article'),
        ]);
        $contextResolver = new ArticleSecurityContextResolver($groupProvider);

        $router = $this->prophesize(RouterInterface::class);
        $router->generate(Argument::cetera())->willReturn('https://example.com/admin/');
        $tool = new ArticleUpdateTool(
            $this->messageBus->reveal(),
            $this->contentManager->reveal(),
            $this->articleRepository->reveal(),
            new BlockDataValidator($this->formMetadataProvider->reveal()),
            $this->blockIdGenerator,
            new ContentMetadataMapper($this->mapperMetadataProvider->reveal()),
            new AdminLinkGenerator($router->reveal(), [new ArticleAdminLinkProvider(new TestViewRegistry())]),
            $this->articleGroupResolver,
            $this->permissionChecker->reveal(),
            $contextResolver,
        );

        $currentArticle = new Article();
        $updatedArticle = new Article('uuid-1');
        $this->articleRepository->getOneBy(Argument::cetera())->willReturn($currentArticle);

        $this->contentManager->resolve(Argument::cetera())->willReturn($this->dimensionContentWithTemplate($currentArticle, 'article'));
        $this->contentManager->normalize(Argument::cetera())->willReturn(['title' => 'Old', 'template' => 'article']);

        // User has EDIT only on the base group, but content.template repeats the current
        // template, so no group change happens and the target check must not fire.
        $checkedContexts = [];
        $this->permissionChecker->check(Argument::cetera())->will(static function (array $args) use (&$checkedContexts): void {
            $checkedContexts[] = $args[0];
            if ('sulu.article.articles_blog' === $args[0]) {
                throw new PermissionDeniedException($args[0], $args[1], $args[2] ?? null);
            }
        });

        $this->messageBus->dispatch(Argument::cetera())->will($this->dispatchHandled($updatedArticle))->shouldBeCalledOnce();

        $result = $tool->updateArticle('uuid-1', 'en', null, null, ['template' => 'article', 'article' => '<p>New</p>']);

        $this->assertTrue($result['success']);
        $this->assertSame(['sulu.article.articles'], $checkedContexts);
    }

    public function testUpdateArticleAcceptsValidUrlInContent(): void
    {
        $currentArticle = new Article();
        $updatedArticle = new Article('uuid-1');

        $this->articleRepository->getOneBy(Argument::cetera())->willReturn($currentArticle);

        $this->contentManager->resolve(Argument::cetera())->willReturn($this->dimensionContentWithTemplate($currentArticle, 'article'));
        $this->contentManager->normalize(Argument::cetera())->willReturn([]);

        $this->messageBus->dispatch(Argument::cetera())->will($this->dispatchHandled($updatedArticle))->shouldBeCalledOnce();

        $result = $this->tool->updateArticle('uuid-1', 'en', null, null, ['url' => '/renamed']);

        $this->assertTrue($result['success']);
    }

    public function testUpdateArticleNormalizesPageTreeRouteAlias(): void
    {
        $currentArticle = new Article();
        $updatedArticle = new Article('uuid-1');

        $this->articleRepository->getOneBy(Argument::cetera())->willReturn($currentArticle);

        $this->contentManager->resolve(Argument::cetera())->willReturn($this->dimensionContentWithTemplate($currentArticle, 'article'));
        $this->contentManager->normalize(Argument::cetera())->willReturn([
            'title' => 'Old',
            'url' => [
                'page' => [
                    'path' => '/blog',
                    'uuid' => 'parent-page-uuid',
                ],
                'suffix' => '/old',
            ],
        ]);

        $this->messageBus->dispatch(Argument::cetera())->will(static function (array $args) use ($updatedArticle): Envelope {
            /** @var Envelope $envelope */
            $envelope = $args[0];
            $message = $envelope->getMessage();
            self::assertInstanceOf(ModifyArticleMessage::class, $message);
            self::assertSame([
                'page' => [
                    'path' => '/blog',
                    'uuid' => 'parent-page-uuid',
                ],
                'suffix' => 'new',
            ], $message->getData()['url']);
            self::assertArrayNotHasKey('page', $message->getData());

            return $envelope->with(new HandledStamp($updatedArticle, 'handler'));
        })->shouldBeCalledOnce();

        $result = $this->tool->updateArticle('uuid-1', 'en', null, null, [
            'page' => [
                'path' => '/blog',
                'uuid' => 'parent-page-uuid',
                'suffix' => 'new',
            ],
        ]);

        $this->assertTrue($result['success']);
    }

    public function testUpdateArticleRejectsInvalidRoutingInContent(): void
    {
        $currentArticle = new Article();
        $this->articleRepository->getOneBy(Argument::cetera())->willReturn($currentArticle);

        $this->contentManager->resolve(Argument::cetera())->willReturn($this->dimensionContentWithTemplate($currentArticle, 'article'));
        $this->contentManager->normalize(Argument::cetera())->willReturn([]);

        $this->messageBus->dispatch(Argument::cetera())->shouldNotBeCalled();

        $result = $this->tool->updateArticle('uuid-1', 'en', null, null, ['url' => 'no-leading-slash']);

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('start with', $result['error']);
    }

    public function testUpdateArticleAssignsBlockIdsToNestedBlocks(): void
    {
        $currentArticle = new Article();
        $updatedArticle = new Article('uuid-1');

        $this->articleRepository->getOneBy(Argument::cetera())->willReturn($currentArticle);

        $this->contentManager->resolve(Argument::cetera())->willReturn($this->dimensionContentWithTemplate($currentArticle, 'blog'));
        $this->contentManager->normalize(Argument::cetera())->willReturn(['title' => 'Old', 'template' => 'blog']);

        $capturedData = null;
        $this->messageBus->dispatch(Argument::cetera())->will(static function (array $args) use ($updatedArticle, &$capturedData): Envelope {
            /** @var Envelope $envelope */
            $envelope = $args[0];
            $message = $envelope->getMessage();
            self::assertInstanceOf(ModifyArticleMessage::class, $message);
            $capturedData = $message->getData();

            return $envelope->with(new HandledStamp($updatedArticle, 'handler'));
        })->shouldBeCalledOnce();

        $this->tool->updateArticle('uuid-1', 'en', null, null, [
            'url' => '/my-article',
            'blocks' => [
                [
                    'type' => 'section',
                    'title' => 'My Section',
                    'blocks' => [
                        ['type' => 'text', 'title' => 'Nested Text'],
                    ],
                ],
            ],
        ]);

        $this->assertNotNull($capturedData);
        $blocks = $capturedData['blocks'];
        $this->assertNotEmpty($blocks[0]['_id'], 'top-level block must have a non-empty _id');
        $this->assertNotEmpty($blocks[0]['blocks'][0]['_id'], 'nested block must have a non-empty _id');
    }

    public function testUpdateArticleRejectsInvalidBlocksBeforeWrite(): void
    {
        $titleField = new FieldMetadata('title');
        $titleField->setType('text_line');

        $textBlock = new FormMetadata();
        $textBlock->setKey('text');
        $textBlock->addItem($titleField);

        $blocksField = new FieldMetadata('blocks');
        $blocksField->setType('block');
        $blocksField->addType($textBlock);

        $template = new FormMetadata();
        $template->setKey('blog');
        $template->addItem($blocksField);

        $typed = new TypedFormMetadata();
        $typed->addForm('blog', $template);

        $this->formMetadataProvider = $this->prophesize(MetadataProviderInterface::class);
        $this->formMetadataProvider->getMetadata('article', Argument::cetera())->willReturn($typed);
        $this->formMetadataProvider->getMetadata(Argument::cetera())->willReturn(new FormMetadata());

        $router = $this->prophesize(RouterInterface::class);
        $router->generate(Argument::cetera())->willReturn('https://example.com/admin/');
        $this->tool = new ArticleUpdateTool(
            $this->messageBus->reveal(),
            $this->contentManager->reveal(),
            $this->articleRepository->reveal(),
            new BlockDataValidator($this->formMetadataProvider->reveal()),
            $this->blockIdGenerator,
            new ContentMetadataMapper($this->mapperMetadataProvider->reveal()),
            new AdminLinkGenerator($router->reveal(), [new ArticleAdminLinkProvider(new TestViewRegistry())]),
            $this->articleGroupResolver,
            $this->permissionChecker->reveal(),
            $this->articleContextResolver,
        );

        $currentArticle = new Article();
        $this->articleRepository->getOneBy(Argument::cetera())->willReturn($currentArticle);

        $this->contentManager->resolve(Argument::cetera())->willReturn($this->dimensionContentWithTemplate($currentArticle, 'blog'));
        $this->contentManager->normalize(Argument::cetera())->willReturn(['title' => 'Old', 'template' => 'blog']);

        $this->messageBus->dispatch(Argument::cetera())->shouldNotBeCalled();

        $result = $this->tool->updateArticle('uuid-1', 'en', null, null, [
            'url' => '/my-article',
            'blocks' => [
                ['type' => 'text', 'bogus' => 'invalid-key'],
            ],
        ]);

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('bogus', $result['error']);
    }

    public function testUpdateArticleReturnsCompactedData(): void
    {
        $currentArticle = new Article();
        $updatedArticle = new Article('uuid-1');

        $this->articleRepository->getOneBy(Argument::cetera())->willReturn($currentArticle);

        $this->contentManager->resolve(Argument::cetera())->willReturn($this->dimensionContentWithTemplate($currentArticle, 'article'));
        $this->contentManager->normalize(Argument::cetera())->willReturn([
            'title' => 'New Title',
            'id' => 42,
            'blocks' => [['_id' => 'b1', 'type' => 'text', 'content' => '<p>HTML</p>']],
        ]);

        $this->messageBus->dispatch(Argument::cetera())->will($this->dispatchHandled($updatedArticle));

        $result = $this->tool->updateArticle('uuid-1', 'en', 'New Title');

        $this->assertTrue($result['success']);
        $this->assertArrayNotHasKey('id', $result['data']);
        $this->assertSame('New Title', $result['data']['title']);
        // Blocks are summarized to index/type, not full content
        $this->assertSame('text', $result['data']['blocks'][0]['type']);
        $this->assertArrayNotHasKey('content', $result['data']['blocks'][0]);
    }

    public function testUpdateArticleSetsExcerptAndSeoInDispatchedData(): void
    {
        $currentArticle = new Article();
        $updatedArticle = new Article('uuid-1');

        $this->articleRepository->getOneBy(Argument::cetera())->willReturn($currentArticle);

        $this->contentManager->resolve(Argument::cetera())->willReturn($this->dimensionContentWithTemplate($currentArticle, 'blog'));
        $this->contentManager->normalize(Argument::cetera())->willReturn(['title' => 'Old', 'template' => 'blog']);

        $capturedMessage = null;
        $this->messageBus->dispatch(Argument::cetera())->will(static function (array $args) use ($updatedArticle, &$capturedMessage): Envelope {
            /** @var Envelope $envelope */
            $envelope = $args[0];
            $capturedMessage = $envelope->getMessage();

            return $envelope->with(new HandledStamp($updatedArticle, 'handler'));
        })->shouldBeCalledOnce();

        $this->tool->updateArticle(
            'uuid-1',
            'en',
            null,
            null,
            ['url' => '/my-article'],
            ['title' => 'T', 'image' => ['id' => 5]],
            ['title' => 'S', 'seoNoIndex' => true],
        );

        $this->assertInstanceOf(ModifyArticleMessage::class, $capturedMessage);
        $data = $capturedMessage->getData();
        $this->assertSame('T', $data['excerpt']['title']);
        $this->assertSame(['id' => 5], $data['excerpt']['image']);
        $this->assertSame('S', $data['seo']['title']);
        $this->assertTrue($data['seoNoIndex']);
    }
}
