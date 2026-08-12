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
use Sulu\Mcp\UserInterface\Mcp\Tool\Page\PageUpdateTool;
use Sulu\Messenger\Infrastructure\Symfony\Messenger\FlushMiddleware\EnableFlushStamp;
use Sulu\Page\Application\Message\ModifyPageMessage;
use Sulu\Page\Domain\Model\Page;
use Sulu\Page\Domain\Repository\PageRepositoryInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\RouterInterface;

#[CoversClass(PageUpdateTool::class)]
final class PageUpdateToolTest extends TestCase
{
    use ProphecyTrait;

    /** @var ObjectProphecy<MessageBusInterface> */
    private ObjectProphecy $messageBus;

    /** @var ObjectProphecy<ContentManagerInterface> */
    private ObjectProphecy $contentManager;

    /** @var ObjectProphecy<PageRepositoryInterface> */
    private ObjectProphecy $pageRepository;

    /** @var ObjectProphecy<MetadataProviderInterface> */
    private ObjectProphecy $formMetadataProvider;

    /** @var ObjectProphecy<MetadataProviderInterface> */
    private ObjectProphecy $mapperMetadataProvider;

    private SequentialBlockIdGenerator $blockIdGenerator;

    private AdminLinkGenerator $adminLinkGenerator;

    /** @var ObjectProphecy<ToolPermissionCheckerInterface> */
    private ObjectProphecy $permissionChecker;

    private PageUpdateTool $tool;

    protected function setUp(): void
    {
        $this->messageBus = $this->prophesize(MessageBusInterface::class);
        $this->contentManager = $this->prophesize(ContentManagerInterface::class);
        $this->pageRepository = $this->prophesize(PageRepositoryInterface::class);
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
        $this->permissionChecker = $this->prophesize(ToolPermissionCheckerInterface::class);

        $this->tool = new PageUpdateTool(
            $this->messageBus->reveal(),
            $this->contentManager->reveal(),
            $this->pageRepository->reveal(),
            new BlockDataValidator($this->formMetadataProvider->reveal()),
            $this->blockIdGenerator,
            new ContentMetadataMapper($this->mapperMetadataProvider->reveal()),
            $this->adminLinkGenerator,
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

    /** @param array<string, mixed> $currentData */
    private function setUpReadModifyWrite(string $uuid, string $locale, array $currentData = []): Page
    {
        $existingPage = new Page($uuid);
        $existingPage->setWebspaceKey('example');

        $this->pageRepository->getOneBy(
            [
                'uuid' => $uuid,
                'locale' => $locale,
                'stage' => DimensionContentInterface::STAGE_DRAFT,
            ],
            [PageRepositoryInterface::GROUP_SELECT_PAGE_ADMIN => true],
        )->willReturn($existingPage);

        $currentDimensionContent = $existingPage->createDimensionContent();
        $this->contentManager->resolve(Argument::cetera())->willReturn($currentDimensionContent);
        $this->contentManager->normalize(Argument::cetera())->willReturn($currentData);

        return $existingPage;
    }

    public function testUpdatePageReadsCurrentStateBeforeModifying(): void
    {
        $this->setUpReadModifyWrite('uuid-1', 'en', ['template' => 'default', 'title' => 'Old Title']);

        $updatedPage = new Page('uuid-1');
        $updatedPage->setWebspaceKey('example');

        $this->messageBus->dispatch(Argument::cetera())
            ->shouldBeCalledOnce()
            ->will(static function(array $args) use ($updatedPage) {
                /** @var Envelope $envelope */
                $envelope = $args[0];
                $message = $envelope->getMessage();
                self::assertInstanceOf(ModifyPageMessage::class, $message);

                $stamps = $envelope->all();
                self::assertArrayHasKey(EnableFlushStamp::class, $stamps);

                return $envelope->with(new HandledStamp($updatedPage, 'handler'));
            });

        $result = $this->tool->updatePage('uuid-1', 'en', 'New Title');

        $this->assertTrue($result['success']);
    }

    public function testUpdatePageIncludesTemplateFromCurrentState(): void
    {
        $this->setUpReadModifyWrite('uuid-1', 'en', [
            'template' => 'default',
            'title' => 'Existing',
            'article' => '<p>Existing content</p>',
        ]);

        $updatedPage = new Page('uuid-1');
        $updatedPage->setWebspaceKey('example');

        $capturedData = null;
        $this->messageBus->dispatch(Argument::cetera())
            ->shouldBeCalledOnce()
            ->will(static function(array $args) use ($updatedPage, &$capturedData) {
                /** @var Envelope $envelope */
                $envelope = $args[0];
                $message = $envelope->getMessage();
                self::assertInstanceOf(ModifyPageMessage::class, $message);
                $capturedData = $message->getData();

                return $envelope->with(new HandledStamp($updatedPage, 'handler'));
            });

        $this->tool->updatePage('uuid-1', 'en', null, null, null, ['article' => '<p>Updated</p>']);

        $this->assertSame('default', $capturedData['template']);
        $this->assertSame('<p>Updated</p>', $capturedData['article']);
    }

    public function testUpdatePageMergesContentWithExistingData(): void
    {
        $this->setUpReadModifyWrite('uuid-1', 'en', [
            'template' => 'default',
            'title' => 'Old Title',
            'article' => '<p>Old content</p>',
        ]);

        $updatedPage = new Page('uuid-1');
        $updatedPage->setWebspaceKey('example');

        $this->messageBus->dispatch(Argument::cetera())
            ->shouldBeCalledOnce()
            ->will(fn (array $args) => $args[0]->with(new HandledStamp($updatedPage, 'handler')));

        $result = $this->tool->updatePage(
            'uuid-1',
            'en',
            null,
            null,
            null,
            ['article' => '<p>New content</p>'],
        );

        $this->assertTrue($result['success']);
    }

    public function testUpdatePageReturnsSuccessWithUuid(): void
    {
        $this->setUpReadModifyWrite('uuid-1', 'en', ['template' => 'default', 'title' => 'Title']);

        $updatedPage = new Page('uuid-1');
        $updatedPage->setWebspaceKey('example');

        $this->messageBus->dispatch(Argument::cetera())
            ->will(fn (array $args) => $args[0]->with(new HandledStamp($updatedPage, 'handler')));

        $result = $this->tool->updatePage('uuid-1', 'en', 'Updated Title');

        $this->assertTrue($result['success']);
        $this->assertSame('uuid-1', $result['uuid']);
        $this->assertSame(
            'https://example.com/admin/#/webspaces/example/pages/en/uuid-1',
            $result['admin_url'],
        );
    }

    public function testUpdatePageReturnsErrorOnException(): void
    {
        $this->pageRepository->getOneBy(Argument::cetera())
            ->willThrow(new \RuntimeException('Page not found'));

        $result = $this->tool->updatePage('non-existent', 'en', 'Title');

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('Page not found', $result['error']);
    }

    public function testUpdatePageMethodHasMcpToolAttribute(): void
    {
        $reflection = new \ReflectionMethod(PageUpdateTool::class, 'updatePage');
        $attributes = $reflection->getAttributes(McpTool::class);

        $this->assertCount(1, $attributes, 'updatePage() must have exactly one #[McpTool] attribute');

        $instance = $attributes[0]->newInstance();
        $this->assertSame('sulu_page_update', $instance->name);
    }

    public function testUpdatePageThrowsToolCallExceptionWhenPermissionDenied(): void
    {
        $this->setUpReadModifyWrite('uuid-1', 'en', ['template' => 'default']);

        $this->permissionChecker
            ->check(Argument::cetera())
            ->willThrow(new PermissionDeniedException('sulu.webspaces.example', PermissionTypes::EDIT, 'en'));

        $this->messageBus->dispatch(Argument::cetera())->shouldNotBeCalled();

        $this->expectException(ToolCallException::class);

        $this->tool->updatePage('uuid-1', 'en', 'New Title');
    }

    public function testUpdatePagePassesConcretePageClassAsObjectType(): void
    {
        // Regression guard: Sulu ACLs key off the concrete Page class (getSecuredClass()),
        // not PageInterface -- using the interface silently falls back to the webspace grant.
        $existingPage = new Page('uuid-1');
        $existingPage->setWebspaceKey('example');

        $this->pageRepository->getOneBy(Argument::cetera())->willReturn($existingPage);

        $currentDimensionContent = $existingPage->createDimensionContent();
        $this->contentManager->resolve(Argument::cetera())->willReturn($currentDimensionContent);
        $this->contentManager->normalize(Argument::cetera())->willReturn(['template' => 'default']);

        $this->permissionChecker
            ->check(
                'sulu.webspaces.example',
                PermissionTypes::EDIT,
                'en',
                Page::class,
                'uuid-1',
            )
            ->shouldBeCalledOnce()
            ->willThrow(new PermissionDeniedException('sulu.webspaces.example', PermissionTypes::EDIT, 'en'));

        $this->messageBus->dispatch(Argument::cetera())->shouldNotBeCalled();

        $this->expectException(ToolCallException::class);

        $this->tool->updatePage('uuid-1', 'en', 'New Title');
    }

    public function testNormalizeContentPassesThroughFlatMap(): void
    {
        $input = ['article' => '<p>Hello</p>', 'title' => 'Test'];
        $this->assertSame($input, PageUpdateTool::normalizeContent($input));
    }

    public function testNormalizeContentFlattensListOfObjects(): void
    {
        // AI sends: [{"article": "<p>Hello</p>"}]
        $input = [['article' => '<p>Hello</p>']];
        $this->assertSame(['article' => '<p>Hello</p>'], PageUpdateTool::normalizeContent($input));
    }

    public function testNormalizeContentHandlesNameValueFormat(): void
    {
        // AI sends: [{"name": "article", "value": "<p>Hello</p>"}]
        $input = [['name' => 'article', 'value' => '<p>Hello</p>']];
        $this->assertSame(['article' => '<p>Hello</p>'], PageUpdateTool::normalizeContent($input));
    }

    public function testNormalizeContentMergesMultipleListItems(): void
    {
        $input = [
            ['article' => '<p>Content</p>'],
            ['subtitle' => 'Sub'],
        ];
        $this->assertSame(
            ['article' => '<p>Content</p>', 'subtitle' => 'Sub'],
            PageUpdateTool::normalizeContent($input),
        );
    }

    public function testNormalizeContentHandlesEmptyArray(): void
    {
        $this->assertSame([], PageUpdateTool::normalizeContent([]));
    }

    public function testUpdatePageAssignsBlockIdsToNestedBlocks(): void
    {
        $this->setUpReadModifyWrite('uuid-1', 'en', ['template' => 'default', 'title' => 'Title']);

        $updatedPage = new Page('uuid-1');
        $updatedPage->setWebspaceKey('example');

        $capturedData = null;
        $this->messageBus->dispatch(Argument::cetera())
            ->shouldBeCalledOnce()
            ->will(static function(array $args) use ($updatedPage, &$capturedData) {
                /** @var Envelope $envelope */
                $envelope = $args[0];
                $message = $envelope->getMessage();
                self::assertInstanceOf(ModifyPageMessage::class, $message);
                $capturedData = $message->getData();

                return $envelope->with(new HandledStamp($updatedPage, 'handler'));
            });

        $this->tool->updatePage(
            'uuid-1',
            'en',
            null,
            null,
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

    public function testUpdatePageRejectsInvalidBlocksBeforeWrite(): void
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

        $this->tool = new PageUpdateTool(
            $this->messageBus->reveal(),
            $this->contentManager->reveal(),
            $this->pageRepository->reveal(),
            new BlockDataValidator($this->formMetadataProvider->reveal()),
            $this->blockIdGenerator,
            new ContentMetadataMapper($this->mapperMetadataProvider->reveal()),
            $this->adminLinkGenerator,
            $this->permissionChecker->reveal(),
        );

        // Set up the read side so we reach the content branch
        $existingPage = new Page('uuid-1');
        $existingPage->setWebspaceKey('example');
        $this->pageRepository->getOneBy(Argument::cetera())->willReturn($existingPage);
        $currentDimensionContent = $existingPage->createDimensionContent();
        $this->contentManager->resolve(Argument::cetera())->willReturn($currentDimensionContent);
        $this->contentManager->normalize(Argument::cetera())->willReturn(['template' => 'default', 'title' => 'Title']);

        $this->messageBus->dispatch(Argument::cetera())->shouldNotBeCalled();

        $result = $this->tool->updatePage(
            'uuid-1',
            'en',
            null,
            null,
            null,
            ['blocks' => [['type' => 'text', 'bogus' => 'x']]],
        );

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('bogus', $result['error']);
    }

    public function testUpdatePageReturnsCompactedData(): void
    {
        $this->setUpReadModifyWrite('uuid-1', 'en', [
            'title' => 'New Title',
            'id' => 99,
            'blocks' => [['_id' => 'b1', 'type' => 'text', 'content' => '<p>HTML</p>']],
        ]);

        $updatedPage = new Page('uuid-1');
        $updatedPage->setWebspaceKey('example');

        $this->messageBus->dispatch(Argument::cetera())
            ->will(fn (array $args) => $args[0]->with(new HandledStamp($updatedPage, 'handler')));

        $result = $this->tool->updatePage('uuid-1', 'en', 'New Title');

        $this->assertTrue($result['success']);
        $this->assertArrayNotHasKey('id', $result['data']);
        $this->assertSame('New Title', $result['data']['title']);
        // Blocks are summarized to index/type, not full content
        $this->assertSame('text', $result['data']['blocks'][0]['type']);
        $this->assertArrayNotHasKey('content', $result['data']['blocks'][0]);
    }

    public function testUpdatePageForcesAuthorizedLocaleOverContentSmuggling(): void
    {
        $this->setUpReadModifyWrite('uuid-1', 'en', ['template' => 'default', 'title' => 'Old Title']);

        $updatedPage = new Page('uuid-1');
        $updatedPage->setWebspaceKey('example');

        $capturedData = null;
        $this->messageBus->dispatch(Argument::cetera())
            ->shouldBeCalledOnce()
            ->will(function(array $args) use ($updatedPage, &$capturedData) {
                /** @var Envelope $envelope */
                $envelope = $args[0];
                $capturedData = $envelope->getMessage()->getData();

                return $envelope->with(new HandledStamp($updatedPage, 'handler'));
            });

        // Caller is authorized for locale 'en' only; content.locale attempts to smuggle 'de'.
        $result = $this->tool->updatePage('uuid-1', 'en', null, null, null, ['locale' => 'de', 'article' => '<p>New</p>']);

        $this->assertTrue($result['success']);
        $this->assertSame('en', $capturedData['locale']);
    }

    public function testUpdatePageAppliesExcerptAndSeoToDispatchedMessage(): void
    {
        $updatedPage = new Page('uuid-1');
        $updatedPage->setWebspaceKey('example');

        $existingPage = new Page('uuid-1');
        $existingPage->setWebspaceKey('example');
        $this->pageRepository->getOneBy(Argument::cetera())->willReturn($existingPage);

        $currentDimensionContent = $existingPage->createDimensionContent();
        $this->contentManager->resolve(Argument::cetera())->willReturn($currentDimensionContent);
        $this->contentManager->normalize(Argument::cetera())->willReturn(['template' => 'default']);

        $capturedData = null;
        $this->messageBus->dispatch(Argument::cetera())
            ->shouldBeCalledOnce()
            ->will(static function(array $args) use ($updatedPage, &$capturedData) {
                /** @var Envelope $envelope */
                $envelope = $args[0];
                $message = $envelope->getMessage();
                self::assertInstanceOf(ModifyPageMessage::class, $message);
                $capturedData = $message->getData();

                return $envelope->with(new HandledStamp($updatedPage, 'handler'));
            });

        $this->tool->updatePage(
            'uuid-1',
            'en',
            null,
            null,
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
}
