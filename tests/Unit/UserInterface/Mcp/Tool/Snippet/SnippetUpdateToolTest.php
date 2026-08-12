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

use Mcp\Capability\Attribute\McpTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Sulu\Article\Domain\Repository\ArticleRepositoryInterface;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FieldMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\TypedFormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\MetadataProviderInterface;
use Sulu\Content\Application\ContentManager\ContentManagerInterface;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Mcp\Application\Content\BlockDataValidator;
use Sulu\Mcp\Application\Content\ContentTypeResolver;
use Sulu\Mcp\Infrastructure\Sulu\AdminLink\SnippetAdminLinkProvider;
use Sulu\Mcp\Infrastructure\Symfony\Routing\AdminLinkGenerator;
use Sulu\Mcp\Tests\Application\TestBundle\Admin\TestViewRegistry;
use Sulu\Mcp\Tests\Unit\Fakes\SequentialBlockIdGenerator;
use Sulu\Mcp\UserInterface\Mcp\Tool\Snippet\SnippetUpdateTool;
use Sulu\Messenger\Infrastructure\Symfony\Messenger\FlushMiddleware\EnableFlushStamp;
use Sulu\Page\Domain\Repository\PageRepositoryInterface;
use Sulu\Snippet\Application\Message\ModifySnippetMessage;
use Sulu\Snippet\Domain\Model\Snippet;
use Sulu\Snippet\Domain\Repository\SnippetRepositoryInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\RouterInterface;

#[CoversClass(SnippetUpdateTool::class)]
final class SnippetUpdateToolTest extends TestCase
{
    use ProphecyTrait;

    /**
     * @var ObjectProphecy<MessageBusInterface>
     */
    private ObjectProphecy $messageBus;

    /**
     * @var ObjectProphecy<ContentManagerInterface>
     */
    private ObjectProphecy $contentManager;

    /**
     * @var ObjectProphecy<PageRepositoryInterface>
     */
    private ObjectProphecy $pageRepository;

    /**
     * @var ObjectProphecy<ArticleRepositoryInterface>
     */
    private ObjectProphecy $articleRepository;

    /**
     * @var ObjectProphecy<SnippetRepositoryInterface>
     */
    private ObjectProphecy $snippetRepository;

    /**
     * @var ObjectProphecy<MetadataProviderInterface>
     */
    private ObjectProphecy $formMetadataProvider;

    private SequentialBlockIdGenerator $blockIdGenerator;
    private SnippetUpdateTool $tool;

    protected function setUp(): void
    {
        $this->messageBus = $this->prophesize(MessageBusInterface::class);
        $this->contentManager = $this->prophesize(ContentManagerInterface::class);
        $this->pageRepository = $this->prophesize(PageRepositoryInterface::class);
        $this->articleRepository = $this->prophesize(ArticleRepositoryInterface::class);
        $this->snippetRepository = $this->prophesize(SnippetRepositoryInterface::class);
        $this->formMetadataProvider = $this->prophesize(MetadataProviderInterface::class);
        // Default: provider returns a non-typed metadata so the validator skips strict checks.
        $this->formMetadataProvider->getMetadata(Argument::cetera())->willReturn(new FormMetadata());
        $this->blockIdGenerator = new SequentialBlockIdGenerator();

        $router = $this->prophesize(RouterInterface::class);
        $router->generate(Argument::cetera())->willReturn('https://example.com/admin/');
        $adminLinkGenerator = new AdminLinkGenerator($router->reveal(), [new SnippetAdminLinkProvider(new TestViewRegistry())]);

        $this->tool = new SnippetUpdateTool(
            $this->messageBus->reveal(),
            $this->contentManager->reveal(),
            new ContentTypeResolver($this->pageRepository->reveal(), $this->articleRepository->reveal(), $this->snippetRepository->reveal()),
            new BlockDataValidator($this->formMetadataProvider->reveal()),
            $this->blockIdGenerator,
            $adminLinkGenerator,
        );
    }

    private function setUpReadModifyWrite(string $uuid, string $locale, array $currentData = []): Snippet
    {
        $existingSnippet = new Snippet($uuid);

        $this->snippetRepository->getOneBy(
            [
                'uuid' => $uuid,
                'locale' => $locale,
                'stage' => DimensionContentInterface::STAGE_DRAFT,
            ],
            [SnippetRepositoryInterface::GROUP_SELECT_SNIPPET_ADMIN => true],
        )->willReturn($existingSnippet);

        $currentDimensionContent = $existingSnippet->createDimensionContent();
        $this->contentManager->resolve(Argument::cetera())->willReturn($currentDimensionContent);
        $this->contentManager->normalize(Argument::cetera())->willReturn($currentData);

        return $existingSnippet;
    }

    public function testUpdateSnippetReadsCurrentStateBeforeModifying(): void
    {
        $this->setUpReadModifyWrite('uuid-1', 'en', ['template' => 'default', 'title' => 'Old Title']);

        $updatedSnippet = new Snippet('uuid-1');

        $capturedEnvelope = null;
        $this->messageBus->dispatch(Argument::cetera())
            ->shouldBeCalledOnce()
            ->will(function (array $args) use ($updatedSnippet, &$capturedEnvelope) {
                $capturedEnvelope = $args[0];

                return $args[0]->with(new HandledStamp($updatedSnippet, 'handler'));
            });

        $result = $this->tool->updateSnippet('uuid-1', 'en', 'New Title');

        $this->assertInstanceOf(ModifySnippetMessage::class, $capturedEnvelope->getMessage());
        $this->assertArrayHasKey(EnableFlushStamp::class, $capturedEnvelope->all());

        $this->assertTrue($result['success']);
    }

    public function testUpdateSnippetUsesUuidAsIdentifier(): void
    {
        $this->setUpReadModifyWrite('uuid-1', 'en', ['template' => 'default', 'title' => 'Old Title']);

        $snippet = new Snippet('uuid-1');

        $capturedMessage = null;
        $this->messageBus->dispatch(Argument::cetera())
            ->shouldBeCalledOnce()
            ->will(function (array $args) use ($snippet, &$capturedMessage) {
                $capturedMessage = $args[0]->getMessage();

                return $args[0]->with(new HandledStamp($snippet, 'handler'));
            });

        $this->tool->updateSnippet('uuid-1', 'en', 'New Title');

        $this->assertInstanceOf(ModifySnippetMessage::class, $capturedMessage);
        $this->assertSame(['uuid' => 'uuid-1'], $capturedMessage->getIdentifier());
    }

    public function testUpdateSnippetIncludesTemplateFromCurrentState(): void
    {
        $this->setUpReadModifyWrite('uuid-1', 'en', [
            'template' => 'default',
            'title' => 'Existing',
            'body' => '<p>Existing content</p>',
        ]);

        $snippet = new Snippet('uuid-1');

        $capturedEnvelope = null;
        $this->messageBus->dispatch(Argument::cetera())
            ->shouldBeCalledOnce()
            ->will(function (array $args) use ($snippet, &$capturedEnvelope) {
                $capturedEnvelope = $args[0];

                return $args[0]->with(new HandledStamp($snippet, 'handler'));
            });

        $this->tool->updateSnippet('uuid-1', 'en', null, null, ['body' => '<p>Updated</p>']);

        $this->assertInstanceOf(ModifySnippetMessage::class, $capturedEnvelope->getMessage());
        $capturedData = $capturedEnvelope->getMessage()->getData();

        $this->assertSame('default', $capturedData['template']);
        $this->assertSame('<p>Updated</p>', $capturedData['body']);
    }

    public function testUpdateSnippetMergesContentWithExistingData(): void
    {
        $this->setUpReadModifyWrite('uuid-1', 'en', [
            'template' => 'default',
            'title' => 'Old Title',
            'body' => '<p>Old content</p>',
        ]);

        $snippet = new Snippet('uuid-1');

        $this->messageBus->dispatch(Argument::cetera())
            ->shouldBeCalledOnce()
            ->will(fn (array $args) => $args[0]->with(new HandledStamp($snippet, 'handler')));

        $result = $this->tool->updateSnippet(
            'uuid-1',
            'en',
            null,
            null,
            ['body' => '<p>New content</p>'],
        );

        $this->assertTrue($result['success']);
    }

    public function testUpdateSnippetReturnsSuccessWithUuid(): void
    {
        $this->setUpReadModifyWrite('uuid-1', 'en', ['template' => 'default', 'title' => 'Title']);

        $snippet = new Snippet('uuid-1');

        $this->messageBus->dispatch(Argument::cetera())
            ->will(fn (array $args) => $args[0]->with(new HandledStamp($snippet, 'handler')));

        $result = $this->tool->updateSnippet('uuid-1', 'en', 'Updated Title');

        $this->assertTrue($result['success']);
        $this->assertSame('uuid-1', $result['uuid']);
        $this->assertSame('https://example.com/admin/#/snippets/en/uuid-1', $result['admin_url']);
    }

    public function testUpdateSnippetReturnsErrorWhenNotFound(): void
    {
        $this->snippetRepository->getOneBy(Argument::cetera())
            ->willThrow(new \RuntimeException('Snippet not found'));

        $result = $this->tool->updateSnippet('non-existent', 'en', 'Title');

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('not found', \strtolower((string) $result['error']));
    }

    public function testUpdateSnippetReturnsErrorOnException(): void
    {
        $this->snippetRepository->getOneBy(Argument::cetera())
            ->willThrow(new \RuntimeException('Snippet not found'));

        $result = $this->tool->updateSnippet('non-existent', 'en', 'Title');

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('Snippet not found', $result['error']);
    }

    public function testUpdateSnippetMethodHasMcpToolAttribute(): void
    {
        $reflection = new \ReflectionMethod(SnippetUpdateTool::class, 'updateSnippet');
        $attributes = $reflection->getAttributes(McpTool::class);

        $this->assertCount(1, $attributes, 'updateSnippet() must have exactly one #[McpTool] attribute');

        $instance = $attributes[0]->newInstance();
        $this->assertSame('sulu_snippet_update', $instance->name);
    }

    public function testUpdateSnippetAssignsBlockIdsToNestedBlocks(): void
    {
        $this->setUpReadModifyWrite('uuid-1', 'en', ['template' => 'default', 'title' => 'Title']);

        $snippet = new Snippet('uuid-1');

        $capturedEnvelope = null;
        $this->messageBus->dispatch(Argument::cetera())
            ->shouldBeCalledOnce()
            ->will(function (array $args) use ($snippet, &$capturedEnvelope) {
                $capturedEnvelope = $args[0];

                return $args[0]->with(new HandledStamp($snippet, 'handler'));
            });

        $this->tool->updateSnippet(
            'uuid-1',
            'en',
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

        $this->assertInstanceOf(ModifySnippetMessage::class, $capturedEnvelope->getMessage());
        $capturedData = $capturedEnvelope->getMessage()->getData();

        $blocks = $capturedData['blocks'];
        $this->assertNotEmpty($blocks[0]['_id']);
        $this->assertNotEmpty($blocks[1]['_id']);
        $this->assertNotEmpty($blocks[1]['blocks'][0]['_id']);
    }

    public function testUpdateSnippetRejectsInvalidBlocksBeforeWrite(): void
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
        $this->formMetadataProvider->getMetadata('snippet', Argument::cetera())->willReturn($typed);

        $router = $this->prophesize(RouterInterface::class);
        $router->generate(Argument::cetera())->willReturn('https://example.com/admin/');
        $this->tool = new SnippetUpdateTool(
            $this->messageBus->reveal(),
            $this->contentManager->reveal(),
            new ContentTypeResolver($this->pageRepository->reveal(), $this->articleRepository->reveal(), $this->snippetRepository->reveal()),
            new BlockDataValidator($this->formMetadataProvider->reveal()),
            $this->blockIdGenerator,
            new AdminLinkGenerator($router->reveal(), [new SnippetAdminLinkProvider(new TestViewRegistry())]),
        );

        $existingSnippet = new Snippet('uuid-1');
        $this->snippetRepository->getOneBy(Argument::cetera())->willReturn($existingSnippet);
        $currentDimensionContent = $existingSnippet->createDimensionContent();
        $this->contentManager->resolve(Argument::cetera())->willReturn($currentDimensionContent);
        $this->contentManager->normalize(Argument::cetera())->willReturn(['template' => 'default', 'title' => 'Title']);

        $this->messageBus->dispatch(Argument::cetera())->shouldNotBeCalled();

        $result = $this->tool->updateSnippet(
            'uuid-1',
            'en',
            null,
            null,
            ['blocks' => [['type' => 'text', 'bogus' => 'x']]],
        );

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('bogus', $result['error']);
    }

    public function testUpdateSnippetReturnsCompactedData(): void
    {
        $this->setUpReadModifyWrite('uuid-1', 'en', [
            'title' => 'Footer',
            'id' => 7,
            'blocks' => [['_id' => 'b1', 'type' => 'text', 'content' => '<p>HTML</p>']],
        ]);

        $snippet = new Snippet('uuid-1');

        $this->messageBus->dispatch(Argument::cetera())
            ->will(fn (array $args) => $args[0]->with(new HandledStamp($snippet, 'handler')));

        $result = $this->tool->updateSnippet('uuid-1', 'en', 'Footer');

        $this->assertTrue($result['success']);
        $this->assertArrayNotHasKey('id', $result['data']);
        $this->assertSame('Footer', $result['data']['title']);
        // Blocks are summarized to index/type, not full content
        $this->assertSame('text', $result['data']['blocks'][0]['type']);
        $this->assertArrayNotHasKey('content', $result['data']['blocks'][0]);
    }

    public function testUpdateSnippetForcesAuthorizedLocaleOverContentSmuggling(): void
    {
        $this->setUpReadModifyWrite('uuid-1', 'en', ['template' => 'default', 'title' => 'Old Title']);

        $snippet = new Snippet('uuid-1');

        $capturedEnvelope = null;
        $this->messageBus->dispatch(Argument::cetera())
            ->shouldBeCalledOnce()
            ->will(function (array $args) use ($snippet, &$capturedEnvelope) {
                $capturedEnvelope = $args[0];

                return $args[0]->with(new HandledStamp($snippet, 'handler'));
            });

        // Caller is authorized for locale 'en' only; content.locale attempts to smuggle 'de'.
        $result = $this->tool->updateSnippet('uuid-1', 'en', null, null, ['locale' => 'de', 'body' => '<p>New</p>']);

        $this->assertTrue($result['success']);
        $capturedData = $capturedEnvelope->getMessage()->getData();
        $this->assertSame('en', $capturedData['locale']);
    }

    public function testUpdateSnippetHasNoWebspaceOrUrlParameter(): void
    {
        $reflection = new \ReflectionMethod(SnippetUpdateTool::class, 'updateSnippet');
        $params = \array_map(fn ($p) => $p->getName(), $reflection->getParameters());

        $this->assertNotContains('webspace', $params);
        $this->assertNotContains('url', $params);
    }
}
