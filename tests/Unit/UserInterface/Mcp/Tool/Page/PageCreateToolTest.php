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

use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FieldMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\TypedFormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\MetadataProviderInterface;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Content\Application\ContentManager\ContentManagerInterface;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Mcp\Application\Content\BlockDataValidator;
use Sulu\Mcp\Application\Content\ContentMetadataMapper;
use Sulu\Mcp\Application\Security\ToolPermissionCheckerInterface;
use Sulu\Mcp\Domain\Exception\PermissionDeniedException;
use Sulu\Mcp\Infrastructure\Sulu\AdminLink\PageAdminLinkProvider;
use Sulu\Mcp\Infrastructure\Symfony\Routing\AdminLinkGenerator;
use Sulu\Mcp\Tests\Application\TestBundle\Admin\TestViewRegistry;
use Sulu\Mcp\Tests\Unit\Fakes\SequentialBlockIdGenerator;
use Sulu\Mcp\UserInterface\Mcp\Tool\Page\PageCreateTool;
use Sulu\Messenger\Infrastructure\Symfony\Messenger\FlushMiddleware\EnableFlushStamp;
use Sulu\Page\Application\Message\CreatePageMessage;
use Sulu\Page\Domain\Model\Page;
use Sulu\Page\Domain\Repository\PageRepositoryInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\RouterInterface;

#[CoversClass(PageCreateTool::class)]
final class PageCreateToolTest extends TestCase
{
    use ProphecyTrait;

    /** @var ObjectProphecy<MessageBusInterface> */
    private ObjectProphecy $messageBus;

    /** @var ObjectProphecy<ContentManagerInterface> */
    private ObjectProphecy $contentManager;

    /** @var ObjectProphecy<MetadataProviderInterface> */
    private ObjectProphecy $formMetadataProvider;

    /** @var ObjectProphecy<MetadataProviderInterface> */
    private ObjectProphecy $mapperMetadataProvider;

    private SequentialBlockIdGenerator $blockIdGenerator;

    /** @var ObjectProphecy<PageRepositoryInterface> */
    private ObjectProphecy $pageRepository;

    /** @var ObjectProphecy<ToolPermissionCheckerInterface> */
    private ObjectProphecy $permissionChecker;

    private AdminLinkGenerator $adminLinkGenerator;

    private PageCreateTool $tool;

    protected function setUp(): void
    {
        $this->messageBus = $this->prophesize(MessageBusInterface::class);
        $this->contentManager = $this->prophesize(ContentManagerInterface::class);
        $this->formMetadataProvider = $this->prophesize(MetadataProviderInterface::class);
        // Default: provider returns a non-typed metadata so the validator skips strict checks.
        $this->formMetadataProvider->getMetadata(Argument::cetera())->willReturn(new FormMetadata());
        $this->mapperMetadataProvider = $this->prophesize(MetadataProviderInterface::class);
        // Provide Sulu's native SEO/excerpt field names so the mapper places them correctly.
        $this->mapperMetadataProvider->getMetadata('content_seo_metadata', Argument::cetera())
            ->willReturn($this->makeFormMeta(['seo/title', 'seo/description', 'seo/keywords', 'seo/canonicalUrl', 'seoNoIndex', 'seoNoFollow', 'seoHideInSitemap']));
        $this->mapperMetadataProvider->getMetadata('content_excerpt_metadata', Argument::cetera())
            ->willReturn($this->makeFormMeta(['excerpt/title', 'excerpt/more', 'excerpt/description', 'excerpt/icon', 'excerpt/image']));
        $this->mapperMetadataProvider->getMetadata('content_excerpt_taxonomies', Argument::cetera())
            ->willReturn($this->makeFormMeta(['excerptCategories', 'excerptTags']));
        $this->mapperMetadataProvider->getMetadata(Argument::cetera())->willReturn($this->makeFormMeta([]));

        $this->blockIdGenerator = new SequentialBlockIdGenerator(['gen-id']);

        $router = $this->prophesize(RouterInterface::class);
        $router->generate(Argument::cetera())->willReturn('https://example.com/admin/');
        $this->adminLinkGenerator = new AdminLinkGenerator($router->reveal(), [new PageAdminLinkProvider(new TestViewRegistry())]);

        $this->pageRepository = $this->prophesize(PageRepositoryInterface::class);
        // Default: parent resolves into the same webspace used across the existing
        // tests below ('example'), so the new parent checks are transparent to them.
        $parentPage = new Page();
        $parentPage->setWebspaceKey('example');
        $this->pageRepository->getOneBy(Argument::cetera())->willReturn($parentPage);

        $this->permissionChecker = $this->prophesize(ToolPermissionCheckerInterface::class);

        $this->tool = new PageCreateTool(
            $this->messageBus->reveal(),
            $this->contentManager->reveal(),
            new BlockDataValidator($this->formMetadataProvider->reveal()),
            $this->blockIdGenerator,
            new ContentMetadataMapper($this->mapperMetadataProvider->reveal()),
            $this->adminLinkGenerator,
            $this->pageRepository->reveal(),
            $this->permissionChecker->reveal(),
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

    public function testCreatePageDispatchesCreatePageMessage(): void
    {
        $page = new Page('page-uuid-123');

        $this->messageBus->dispatch(Argument::cetera())
            ->shouldBeCalledOnce()
            ->will(static function (array $args) use ($page) {
                /** @var Envelope $envelope */
                $envelope = $args[0];
                $message = $envelope->getMessage();
                self::assertInstanceOf(CreatePageMessage::class, $message);

                $stamps = $envelope->all();
                self::assertArrayHasKey(EnableFlushStamp::class, $stamps);

                return $envelope->with(new HandledStamp($page, 'handler'));
            });

        $dimensionContent = $page->createDimensionContent();
        $this->contentManager->resolve(Argument::cetera())->willReturn($dimensionContent);
        $this->contentManager->normalize(Argument::cetera())->willReturn(['title' => 'Test Page']);

        $result = $this->tool->createPage('example', 'en', 'default', 'Test Page', 'parent-uuid');

        $this->assertTrue($result['success']);
        $this->assertSame('page-uuid-123', $result['uuid']);
    }

    public function testCreatePageIncludesLocaleInData(): void
    {
        $page = new Page('uuid-1');

        $this->messageBus->dispatch(Argument::cetera())
            ->shouldBeCalledOnce()
            ->will(static function (array $args) use ($page) {
                /** @var Envelope $envelope */
                $envelope = $args[0];
                $message = $envelope->getMessage();
                self::assertInstanceOf(CreatePageMessage::class, $message);

                return $envelope->with(new HandledStamp($page, 'handler'));
            });

        $dimensionContent = $page->createDimensionContent();
        $this->contentManager->resolve(Argument::cetera())->willReturn($dimensionContent);
        $this->contentManager->normalize(Argument::cetera())->willReturn([]);

        $this->tool->createPage('example', 'en', 'default', 'Test', 'parent-uuid');
    }

    public function testCreatePageGeneratesUrlFromTitleWhenUrlIsNull(): void
    {
        $page = new Page('uuid-1');

        $capturedMessage = null;
        $this->messageBus->dispatch(Argument::cetera())
            ->shouldBeCalledOnce()
            ->will(function (array $args) use ($page, &$capturedMessage) {
                /** @var Envelope $envelope */
                $envelope = $args[0];
                $capturedMessage = $envelope->getMessage();

                return $envelope->with(new HandledStamp($page, 'handler'));
            });

        $dimensionContent = $page->createDimensionContent();
        $this->contentManager->resolve(Argument::cetera())->willReturn($dimensionContent);
        $this->contentManager->normalize(Argument::cetera())->willReturn([]);

        $this->tool->createPage('example', 'en', 'default', 'My Test Page', 'parent-uuid');

        $this->assertInstanceOf(CreatePageMessage::class, $capturedMessage);
    }

    public function testCreatePageMergesContentIntoData(): void
    {
        $page = new Page('uuid-1');

        $this->messageBus->dispatch(Argument::cetera())
            ->shouldBeCalledOnce()
            ->will(fn (array $args) => $args[0]->with(new HandledStamp($page, 'handler')));

        $dimensionContent = $page->createDimensionContent();
        $this->contentManager->resolve(Argument::cetera())->willReturn($dimensionContent);
        $this->contentManager->normalize(Argument::cetera())->willReturn([]);

        $result = $this->tool->createPage(
            'example',
            'en',
            'default',
            'Test',
            'parent-uuid',
            null,
            ['excerpt' => 'Test excerpt'],
        );

        $this->assertTrue($result['success']);
    }

    public function testCreatePageResolvesAndNormalizesResult(): void
    {
        $page = new Page('uuid-1');

        $this->messageBus->dispatch(Argument::cetera())
            ->shouldBeCalledOnce()
            ->will(fn (array $args) => $args[0]->with(new HandledStamp($page, 'handler')));

        $dimensionContent = $page->createDimensionContent();
        $this->contentManager->resolve($page, [
            'locale' => 'en',
            'stage' => DimensionContentInterface::STAGE_DRAFT,
        ])->shouldBeCalledOnce()->willReturn($dimensionContent);

        $this->contentManager->normalize($dimensionContent)
            ->shouldBeCalledOnce()
            ->willReturn(['title' => 'Resolved Title']);

        $result = $this->tool->createPage('example', 'en', 'default', 'Test', 'parent-uuid');

        $this->assertSame(['title' => 'Resolved Title'], $result['data']);
    }

    public function testCreatePageReturnsSuccessWithUuid(): void
    {
        $page = new Page('new-page-uuid');

        $this->messageBus->dispatch(Argument::cetera())
            ->will(fn (array $args) => $args[0]->with(new HandledStamp($page, 'handler')));

        $dimensionContent = $page->createDimensionContent();
        $this->contentManager->resolve(Argument::cetera())->willReturn($dimensionContent);
        $this->contentManager->normalize(Argument::cetera())->willReturn([]);

        $result = $this->tool->createPage('example', 'en', 'default', 'Test', 'parent-uuid');

        $this->assertTrue($result['success']);
        $this->assertSame('new-page-uuid', $result['uuid']);
        $this->assertArrayHasKey('data', $result);
        $this->assertSame(
            'https://example.com/admin/#/webspaces/example/pages/en/new-page-uuid',
            $result['admin_url'],
        );
    }

    public function testCreatePageReturnsErrorOnException(): void
    {
        $this->messageBus->dispatch(Argument::cetera())
            ->willThrow(new \RuntimeException('Page creation failed'));

        $result = $this->tool->createPage('example', 'en', 'default', 'Test', 'parent-uuid');

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('Page creation failed', $result['error']);
        $this->assertArrayNotHasKey('success', $result);
    }

    public function testCreatePageMethodHasMcpToolAttribute(): void
    {
        $reflection = new \ReflectionMethod(PageCreateTool::class, 'createPage');
        $attributes = $reflection->getAttributes(McpTool::class);

        $this->assertCount(1, $attributes, 'createPage() must have exactly one #[McpTool] attribute');

        $instance = $attributes[0]->newInstance();
        $this->assertSame('sulu_page_create', $instance->name);
    }

    public function testCreatePageAssignsBlockIdsToNestedBlocks(): void
    {
        $page = new Page('uuid-1');

        $capturedData = null;
        $this->messageBus->dispatch(Argument::cetera())
            ->shouldBeCalledOnce()
            ->will(static function (array $args) use ($page, &$capturedData) {
                /** @var Envelope $envelope */
                $envelope = $args[0];
                $message = $envelope->getMessage();
                self::assertInstanceOf(CreatePageMessage::class, $message);
                $capturedData = (new \ReflectionProperty($message, 'data'))->getValue($message);

                return $envelope->with(new HandledStamp($page, 'handler'));
            });

        $dimensionContent = $page->createDimensionContent();
        $this->contentManager->resolve(Argument::cetera())->willReturn($dimensionContent);
        $this->contentManager->normalize(Argument::cetera())->willReturn([]);

        $this->tool->createPage(
            'example',
            'en',
            'default',
            'Test',
            'parent-uuid',
            null,
            [
                'blocks' => [
                    ['type' => 'text', 'title' => 'A'],
                    ['type' => 'section', 'title' => 'S', 'blocks' => [
                        ['type' => 'text', 'title' => 'N'],
                    ]],
                ],
            ],
        );

        $this->assertNotNull($capturedData);
        $blocks = $capturedData['blocks'];
        $this->assertNotEmpty($blocks[0]['_id']);
        $this->assertNotEmpty($blocks[1]['_id']);
        $this->assertNotEmpty($blocks[1]['blocks'][0]['_id']);
    }

    public function testCreatePageRejectsInvalidBlocksBeforeWrite(): void
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
        $template->setKey('default');
        $template->addItem($blocksField);

        $typed = new TypedFormMetadata();
        $typed->addForm('default', $template);

        $this->formMetadataProvider = $this->prophesize(MetadataProviderInterface::class);
        $this->formMetadataProvider->getMetadata('page', Argument::cetera())->willReturn($typed);
        $this->formMetadataProvider->getMetadata(Argument::cetera())->willReturn(new FormMetadata());

        $this->tool = new PageCreateTool(
            $this->messageBus->reveal(),
            $this->contentManager->reveal(),
            new BlockDataValidator($this->formMetadataProvider->reveal()),
            $this->blockIdGenerator,
            new ContentMetadataMapper($this->mapperMetadataProvider->reveal()),
            $this->adminLinkGenerator,
            $this->pageRepository->reveal(),
            $this->permissionChecker->reveal(),
        );

        $this->messageBus->dispatch(Argument::cetera())->shouldNotBeCalled();

        $result = $this->tool->createPage(
            'example',
            'en',
            'default',
            'Test',
            'parent-uuid',
            null,
            ['blocks' => [['type' => 'text', 'bogus' => 'x']]],
        );

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('bogus', $result['error']);
    }

    public function testCreatePageReturnsMapperErrorWithoutDispatchingWhenUnknownSeoField(): void
    {
        $this->messageBus->dispatch(Argument::cetera())->shouldNotBeCalled();

        $result = $this->tool->createPage(
            'example',
            'en',
            'default',
            'Test',
            'parent-uuid',
            null,
            null,
            null,
            ['bogusField' => 'x'],
        );

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('bogusField', $result['error']);
    }

    public function testCreatePageAppliesExcerptAndSeoToDispatchedMessage(): void
    {
        $page = new Page('uuid-1');

        $capturedData = null;
        $this->messageBus->dispatch(Argument::cetera())
            ->shouldBeCalledOnce()
            ->will(static function (array $args) use ($page, &$capturedData) {
                /** @var Envelope $envelope */
                $envelope = $args[0];
                $message = $envelope->getMessage();
                self::assertInstanceOf(CreatePageMessage::class, $message);
                $capturedData = (new \ReflectionProperty($message, 'data'))->getValue($message);

                return $envelope->with(new HandledStamp($page, 'handler'));
            });

        $dimensionContent = $page->createDimensionContent();
        $this->contentManager->resolve(Argument::cetera())->willReturn($dimensionContent);
        $this->contentManager->normalize(Argument::cetera())->willReturn([]);

        $this->tool->createPage(
            'example',
            'en',
            'default',
            'Test',
            'parent-uuid',
            null,
            null,
            ['title' => 'T', 'description' => '<p>D</p>', 'image' => ['id' => 5]],
            ['title' => 'S', 'description' => 'meta', 'seoNoIndex' => true],
        );

        $this->assertNotNull($capturedData);
        $this->assertSame('T', $capturedData['excerpt']['title']);
        $this->assertSame(['id' => 5], $capturedData['excerpt']['image']);
        $this->assertSame('S', $capturedData['seo']['title']);
        $this->assertTrue($capturedData['seoNoIndex']);
    }

    public function testCreatePageLoadsParentWithCorrectFilters(): void
    {
        $page = new Page('uuid-1');

        $this->pageRepository = $this->prophesize(PageRepositoryInterface::class);
        $parentPage = new Page();
        $parentPage->setWebspaceKey('example');

        $this->pageRepository
            ->getOneBy(
                [
                    'uuid' => 'parent-uuid',
                    'locale' => 'en',
                    'stage' => DimensionContentInterface::STAGE_DRAFT,
                ],
                [
                    PageRepositoryInterface::GROUP_SELECT_PAGE_ADMIN => true,
                ],
            )
            ->shouldBeCalledOnce()
            ->willReturn($parentPage);

        $this->tool = new PageCreateTool(
            $this->messageBus->reveal(),
            $this->contentManager->reveal(),
            new BlockDataValidator($this->formMetadataProvider->reveal()),
            $this->blockIdGenerator,
            new ContentMetadataMapper($this->mapperMetadataProvider->reveal()),
            $this->adminLinkGenerator,
            $this->pageRepository->reveal(),
            $this->permissionChecker->reveal(),
        );

        $this->messageBus->dispatch(Argument::cetera())
            ->will(fn (array $args) => $args[0]->with(new HandledStamp($page, 'handler')));

        $dimensionContent = $page->createDimensionContent();
        $this->contentManager->resolve(Argument::cetera())->willReturn($dimensionContent);
        $this->contentManager->normalize(Argument::cetera())->willReturn([]);

        $this->tool->createPage('example', 'en', 'default', 'Test', 'parent-uuid');
    }

    public function testCreatePageChecksObjectPermissionOnParent(): void
    {
        $page = new Page('uuid-1');

        $this->messageBus->dispatch(Argument::cetera())
            ->will(fn (array $args) => $args[0]->with(new HandledStamp($page, 'handler')));

        $dimensionContent = $page->createDimensionContent();
        $this->contentManager->resolve(Argument::cetera())->willReturn($dimensionContent);
        $this->contentManager->normalize(Argument::cetera())->willReturn([]);

        $checked = [];
        $this->permissionChecker
            ->check(Argument::cetera())
            ->shouldBeCalledOnce()
            ->will(static function (array $args) use (&$checked): void {
                self::assertSame('sulu.webspaces.example', $args[0]);
                self::assertSame('en', $args[2]);
                self::assertSame(Page::class, $args[3]);
                self::assertSame('parent-uuid', $args[4]);
                $checked = (array) $args[1];
            });

        $this->tool->createPage('example', 'en', 'default', 'Test', 'parent-uuid');

        self::assertSame([PermissionTypes::EDIT, PermissionTypes::ADD], $checked);
    }

    public function testCreatePageDeniesWhenParentInDifferentWebspace(): void
    {
        $parentPage = new Page();
        $parentPage->setWebspaceKey('other-webspace');
        $this->pageRepository = $this->prophesize(PageRepositoryInterface::class);
        $this->pageRepository->getOneBy(Argument::cetera())->willReturn($parentPage);

        $this->tool = new PageCreateTool(
            $this->messageBus->reveal(),
            $this->contentManager->reveal(),
            new BlockDataValidator($this->formMetadataProvider->reveal()),
            $this->blockIdGenerator,
            new ContentMetadataMapper($this->mapperMetadataProvider->reveal()),
            $this->adminLinkGenerator,
            $this->pageRepository->reveal(),
            $this->permissionChecker->reveal(),
        );

        $this->messageBus->dispatch(Argument::cetera())->shouldNotBeCalled();
        $this->permissionChecker->check(Argument::cetera())->shouldNotBeCalled();

        $this->expectException(ToolCallException::class);

        $this->tool->createPage('example', 'en', 'default', 'Test', 'parent-uuid');
    }

    public function testCreatePageForcesTrustedLocaleAndTemplateOverMetadataClobbering(): void
    {
        // Regression guard: excerpt/seo fields literally named "locale"/"template" let
        // ContentMetadataMapper::place() clobber the trusted args that passed the EDIT preflight.
        $page = new Page('uuid-1');

        $mapperMetadataProvider = $this->prophesize(MetadataProviderInterface::class);
        $mapperMetadataProvider->getMetadata('content_excerpt_metadata', Argument::cetera())
            ->willReturn($this->makeFormMeta(['locale']));
        $mapperMetadataProvider->getMetadata('content_seo_metadata', Argument::cetera())
            ->willReturn($this->makeFormMeta(['template']));
        $mapperMetadataProvider->getMetadata(Argument::cetera())->willReturn($this->makeFormMeta([]));

        $tool = new PageCreateTool(
            $this->messageBus->reveal(),
            $this->contentManager->reveal(),
            new BlockDataValidator($this->formMetadataProvider->reveal()),
            $this->blockIdGenerator,
            new ContentMetadataMapper($mapperMetadataProvider->reveal()),
            $this->adminLinkGenerator,
            $this->pageRepository->reveal(),
            $this->permissionChecker->reveal(),
        );

        $capturedData = null;
        $this->messageBus->dispatch(Argument::cetera())
            ->shouldBeCalledOnce()
            ->will(static function (array $args) use ($page, &$capturedData) {
                /** @var Envelope $envelope */
                $envelope = $args[0];
                $message = $envelope->getMessage();
                self::assertInstanceOf(CreatePageMessage::class, $message);
                $capturedData = (new \ReflectionProperty($message, 'data'))->getValue($message);

                return $envelope->with(new HandledStamp($page, 'handler'));
            });

        $dimensionContent = $page->createDimensionContent();
        $this->contentManager->resolve(Argument::cetera())->willReturn($dimensionContent);
        $this->contentManager->normalize(Argument::cetera())->willReturn([]);

        $result = $tool->createPage(
            'example',
            'en',
            'default',
            'Test',
            'parent-uuid',
            null,
            null,
            ['locale' => 'de'],
            ['template' => 'smuggled'],
        );

        $this->assertTrue($result['success']);
        $this->assertSame('en', $capturedData['locale']);
        $this->assertSame('default', $capturedData['template']);
    }

    public function testCreatePageDeniesWhenParentAclDenied(): void
    {
        $this->messageBus->dispatch(Argument::cetera())->shouldNotBeCalled();

        $this->permissionChecker
            ->check(Argument::cetera())
            ->willThrow(new PermissionDeniedException('sulu.webspaces.example', PermissionTypes::EDIT, 'en'));

        $this->expectException(ToolCallException::class);

        $this->tool->createPage('example', 'en', 'default', 'Test', 'parent-uuid');
    }
}
