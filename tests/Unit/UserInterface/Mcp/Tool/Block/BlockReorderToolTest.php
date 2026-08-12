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

use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Sulu\Article\Application\Message\ModifyArticleMessage;
use Sulu\Article\Domain\Model\Article;
use Sulu\Article\Domain\Repository\ArticleRepositoryInterface;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Content\Application\ContentManager\ContentManagerInterface;
use Sulu\Mcp\Application\Content\ContentTypeResolver;
use Sulu\Mcp\Application\Security\ContentSecurityContextResolver;
use Sulu\Mcp\Application\Security\ToolPermissionCheckerInterface;
use Sulu\Mcp\Domain\Exception\PermissionDeniedException;
use Sulu\Mcp\Infrastructure\Sulu\Security\ArticleSecurityContextResolver;
use Sulu\Mcp\Tests\Unit\Fakes\FakeGroupProvider;
use Sulu\Mcp\UserInterface\Mcp\Tool\Block\BlockReorderTool;
use Sulu\Page\Application\Message\ModifyPageMessage;
use Sulu\Page\Domain\Model\Page;
use Sulu\Page\Domain\Repository\PageRepositoryInterface;
use Sulu\Snippet\Application\Message\ModifySnippetMessage;
use Sulu\Snippet\Domain\Model\Snippet;
use Sulu\Snippet\Domain\Repository\SnippetRepositoryInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

#[CoversClass(BlockReorderTool::class)]
#[CoversClass(ContentTypeResolver::class)]
final class BlockReorderToolTest extends TestCase
{
    use ProphecyTrait;

    /**
     * @var ObjectProphecy<MessageBusInterface>
     */
    private ObjectProphecy $messageBus;

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
     * @var ObjectProphecy<ContentManagerInterface>
     */
    private ObjectProphecy $contentManager;

    /**
     * @var ObjectProphecy<ToolPermissionCheckerInterface>
     */
    private ObjectProphecy $permissionChecker;

    private ContentSecurityContextResolver $contentSecurityContextResolver;

    private BlockReorderTool $tool;

    protected function setUp(): void
    {
        $this->messageBus = $this->prophesize(MessageBusInterface::class);
        $this->pageRepository = $this->prophesize(PageRepositoryInterface::class);
        $this->articleRepository = $this->prophesize(ArticleRepositoryInterface::class);
        $this->snippetRepository = $this->prophesize(SnippetRepositoryInterface::class);
        $this->contentManager = $this->prophesize(ContentManagerInterface::class);
        $this->permissionChecker = $this->prophesize(ToolPermissionCheckerInterface::class);
        $groupProvider = new FakeGroupProvider();
        $this->contentSecurityContextResolver = new ContentSecurityContextResolver(new ArticleSecurityContextResolver($groupProvider));
        $this->tool = new BlockReorderTool(
            $this->messageBus->reveal(),
            new ContentTypeResolver($this->pageRepository->reveal(), $this->articleRepository->reveal(), $this->snippetRepository->reveal()),
            $this->contentManager->reveal(),
            $this->permissionChecker->reveal(),
            $this->contentSecurityContextResolver,
        );
    }

    /**
     * @return iterable<string, array{string, class-string}>
     */
    public static function contentTypeProvider(): iterable
    {
        yield 'page' => ['page', ModifyPageMessage::class];
        yield 'article' => ['article', ModifyArticleMessage::class];
        yield 'snippet' => ['snippet', ModifySnippetMessage::class];
    }

    /**
     * @param class-string $expectedMessageClass
     */
    #[DataProvider('contentTypeProvider')]
    public function testReorderBlocksDispatchesCorrectMessagePerType(string $type, string $expectedMessageClass): void
    {
        $this->setupEntityWithBlocks($type, [
            ['type' => 'text', 'title' => 'First'],
            ['type' => 'image', 'src' => '/img.jpg'],
            ['type' => 'text', 'title' => 'Third'],
        ]);

        $this->messageBus->dispatch(Argument::that(
            static fn (Envelope $envelope): bool => $envelope->getMessage() instanceof $expectedMessageClass
        ), Argument::cetera())
            ->shouldBeCalledOnce()
            ->will(static fn (array $args): Envelope => $args[0]->with(new HandledStamp(null, 'handler')));

        $result = $this->tool->reorderBlocks($type, 'test-uuid', 'en', 'blocks', [2, 0, 1]);

        $this->assertTrue($result['success']);
        $this->assertSame(3, $result['blockCount']);
        $this->assertSame([2, 0, 1], $result['order']);
        $this->assertSame('test-uuid', $result['uuid']);
    }

    public function testReorderBlocksReturnsErrorForUnsupportedType(): void
    {
        $this->messageBus->dispatch(Argument::cetera())->shouldNotBeCalled();

        $result = $this->tool->reorderBlocks('media', 'test-uuid', 'en', 'blocks', [0]);

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('Unsupported content type', $result['error']);
    }

    public function testReorderBlocksReturnsErrorWhenEntityNotFound(): void
    {
        $this->pageRepository->getOneBy(Argument::cetera())->willThrow(new \RuntimeException('not found'));
        $this->messageBus->dispatch(Argument::cetera())->shouldNotBeCalled();

        $result = $this->tool->reorderBlocks('page', 'missing-uuid', 'en', 'blocks', [0]);

        $this->assertArrayHasKey('error', $result);
    }

    public function testReorderBlocksReturnsErrorForWrongLength(): void
    {
        $this->setupEntityWithBlocks('page', [
            ['type' => 'text', 'title' => 'First'],
            ['type' => 'text', 'title' => 'Second'],
            ['type' => 'text', 'title' => 'Third'],
        ]);

        $this->messageBus->dispatch(Argument::cetera())->shouldNotBeCalled();

        $result = $this->tool->reorderBlocks('page', 'test-uuid', 'en', 'blocks', [0, 1]);

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('does not match block count', $result['error']);
    }

    public function testReorderBlocksReturnsErrorForDuplicateIndices(): void
    {
        $this->setupEntityWithBlocks('page', [
            ['type' => 'text', 'title' => 'First'],
            ['type' => 'text', 'title' => 'Second'],
            ['type' => 'text', 'title' => 'Third'],
        ]);

        $this->messageBus->dispatch(Argument::cetera())->shouldNotBeCalled();

        $result = $this->tool->reorderBlocks('page', 'test-uuid', 'en', 'blocks', [0, 0, 1]);

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('exactly once', $result['error']);
    }

    public function testReorderBlocksReturnsErrorForOutOfRangeIndex(): void
    {
        $this->setupEntityWithBlocks('page', [
            ['type' => 'text', 'title' => 'First'],
            ['type' => 'text', 'title' => 'Second'],
            ['type' => 'text', 'title' => 'Third'],
        ]);

        $this->messageBus->dispatch(Argument::cetera())->shouldNotBeCalled();

        $result = $this->tool->reorderBlocks('page', 'test-uuid', 'en', 'blocks', [0, 1, 5]);

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('exactly once', $result['error']);
    }

    public function testReorderBlocksAcceptsNumericStringIndices(): void
    {
        $this->setupEntityWithBlocks('page', [
            ['type' => 'text', 'title' => 'First'],
            ['type' => 'text', 'title' => 'Second'],
        ]);

        $this->messageBus->dispatch(Argument::cetera())
            ->shouldBeCalledOnce()
            ->will(static fn (array $args): Envelope => $args[0]->with(new HandledStamp(null, 'handler')));

        $result = $this->tool->reorderBlocks('page', 'test-uuid', 'en', 'blocks', ['1', '0']);

        $this->assertTrue($result['success']);
        $this->assertSame([1, 0], $result['order']);
    }

    public function testReorderBlocksRejectsNonIntegerIndices(): void
    {
        $this->messageBus->dispatch(Argument::cetera())->shouldNotBeCalled();

        $result = $this->tool->reorderBlocks('page', 'test-uuid', 'en', 'blocks', ['first']);

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('integer indices', $result['error']);
    }

    public function testReorderBlocksMethodHasMcpToolAttribute(): void
    {
        $reflection = new \ReflectionMethod(BlockReorderTool::class, 'reorderBlocks');
        $attributes = $reflection->getAttributes(McpTool::class);

        $this->assertCount(1, $attributes, 'reorderBlocks() must have exactly one #[McpTool] attribute');

        $instance = $attributes[0]->newInstance();
        $this->assertSame('sulu_block_reorder', $instance->name);
    }

    public function testNewOrderParameterIsAdvertisedAsIntegerArraySchema(): void
    {
        $reflection = new \ReflectionMethod(BlockReorderTool::class, 'reorderBlocks');
        $parameter = $reflection->getParameters()[4];
        $attributes = $parameter->getAttributes(Schema::class);

        $this->assertCount(1, $attributes);

        $schema = $attributes[0]->newInstance();
        $this->assertSame('array', $schema->type);
        $this->assertSame(['type' => 'integer'], $schema->items);
    }

    public function testReorderByBlockIdsReordersByIdentity(): void
    {
        $this->setupEntityWithBlocks('page', [
            ['_id' => 'a', 'type' => 'text', 'title' => 'First'],
            ['_id' => 'b', 'type' => 'image', 'src' => '/img.jpg'],
            ['_id' => 'c', 'type' => 'text', 'title' => 'Third'],
        ]);

        $this->messageBus->dispatch(Argument::cetera())
            ->shouldBeCalledOnce()
            ->will(static fn (array $args): Envelope => $args[0]->with(new HandledStamp(null, 'handler')));

        $result = $this->tool->reorderBlocks('page', 'test-uuid', 'en', 'blocks', null, ['c', 'a', 'b']);

        $this->assertTrue($result['success']);
        $this->assertSame([2, 0, 1], $result['order']);
    }

    public function testReorderByBlockIdsRejectsUnknownId(): void
    {
        $this->setupEntityWithBlocks('page', [
            ['_id' => 'a', 'type' => 'text', 'title' => 'First'],
            ['_id' => 'b', 'type' => 'text', 'title' => 'Second'],
        ]);

        $this->messageBus->dispatch(Argument::cetera())->shouldNotBeCalled();

        $result = $this->tool->reorderBlocks('page', 'test-uuid', 'en', 'blocks', null, ['a', 'missing']);

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('missing', $result['error']);
    }

    public function testReorderRequiresNewOrderOrBlockIds(): void
    {
        $this->messageBus->dispatch(Argument::cetera())->shouldNotBeCalled();

        $result = $this->tool->reorderBlocks('page', 'test-uuid', 'en', 'blocks');

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('newOrder', $result['error']);
        $this->assertStringContainsString('blockIds', $result['error']);
    }

    public function testReorderRejectsBothNewOrderAndBlockIds(): void
    {
        $this->messageBus->dispatch(Argument::cetera())->shouldNotBeCalled();

        $result = $this->tool->reorderBlocks('page', 'test-uuid', 'en', 'blocks', [0, 1], ['a', 'b']);

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('not both', $result['error']);
    }

    public function testReorderBlocksThrowsToolCallExceptionWhenPermissionDenied(): void
    {
        $this->setupEntityWithBlocks('page', [
            ['type' => 'text', 'title' => 'First'],
        ]);

        $this->permissionChecker
            ->check(Argument::cetera())
            ->willThrow(new PermissionDeniedException('sulu.webspaces.example', PermissionTypes::EDIT, 'en'));

        $this->messageBus->dispatch(Argument::cetera())->shouldNotBeCalled();

        $this->expectException(ToolCallException::class);

        $this->tool->reorderBlocks('page', 'test-uuid', 'en', 'blocks', [0]);
    }

    /**
     * @param list<array<string, mixed>> $blocks
     */
    private function setupEntityWithBlocks(string $type, array $blocks): void
    {
        $entity = match ($type) {
            'article' => new Article(),
            'snippet' => new Snippet(),
            default => new Page(),
        };
        if ($entity instanceof Page) {
            $entity->setWebspaceKey('');
        }

        match ($type) {
            'article' => $this->articleRepository->getOneBy(Argument::cetera())->willReturn($entity),
            'snippet' => $this->snippetRepository->getOneBy(Argument::cetera())->willReturn($entity),
            default => $this->pageRepository->getOneBy(Argument::cetera())->willReturn($entity),
        };

        $dimensionContent = $entity->createDimensionContent();
        $this->contentManager->resolve(Argument::cetera())->willReturn($dimensionContent);
        $this->contentManager->normalize(Argument::cetera())->willReturn([
            'template' => 'default',
            'title' => 'Test',
            'blocks' => $blocks,
        ]);
    }
}
