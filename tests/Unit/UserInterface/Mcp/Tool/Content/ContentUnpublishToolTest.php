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

namespace Sulu\Mcp\Tests\Unit\UserInterface\Mcp\Tool\Content;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
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
use Sulu\Mcp\UserInterface\Mcp\Tool\Content\ContentUnpublishTool;
use Sulu\Messenger\Infrastructure\Symfony\Messenger\FlushMiddleware\EnableFlushStamp;
use Sulu\Page\Domain\Model\Page;
use Sulu\Page\Domain\Repository\PageRepositoryInterface;
use Sulu\Snippet\Application\Message\ApplyWorkflowTransitionSnippetMessage;
use Sulu\Snippet\Domain\Model\Snippet;
use Sulu\Snippet\Domain\Repository\SnippetRepositoryInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

#[CoversClass(ContentUnpublishTool::class)]
final class ContentUnpublishToolTest extends TestCase
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

    private ContentUnpublishTool $tool;

    protected function setUp(): void
    {
        $this->messageBus = $this->prophesize(MessageBusInterface::class);
        $this->pageRepository = $this->prophesize(PageRepositoryInterface::class);
        $this->articleRepository = $this->prophesize(ArticleRepositoryInterface::class);
        $this->snippetRepository = $this->prophesize(SnippetRepositoryInterface::class);
        $this->contentManager = $this->prophesize(ContentManagerInterface::class);
        $this->permissionChecker = $this->prophesize(ToolPermissionCheckerInterface::class);

        $this->tool = new ContentUnpublishTool(
            $this->messageBus->reveal(),
            new ContentTypeResolver($this->pageRepository->reveal(), $this->articleRepository->reveal(), $this->snippetRepository->reveal()),
            $this->contentManager->reveal(),
            $this->permissionChecker->reveal(),
            new ContentSecurityContextResolver(new ArticleSecurityContextResolver(new FakeGroupProvider())),
        );
    }

    public function testUnpublishSnippetDispatchesTransition(): void
    {
        $this->setupEntity('snippet');

        $this->messageBus->dispatch(Argument::cetera())
            ->will(static function(array $args) {
                /** @var Envelope $envelope */
                $envelope = $args[0];
                self::assertInstanceOf(ApplyWorkflowTransitionSnippetMessage::class, $envelope->getMessage());
                self::assertArrayHasKey(EnableFlushStamp::class, $envelope->all());

                return $envelope->with(new HandledStamp(null, 'handler'));
            })
            ->shouldBeCalledOnce();

        $result = $this->tool->unpublishContent('snippet', 'uuid-1', 'en');

        $this->assertSame('unpublished', $result['action']);
    }

    public function testUnsupportedTypeReturnsError(): void
    {
        $this->messageBus->dispatch(Argument::cetera())->shouldNotBeCalled();
        $this->assertArrayHasKey('error', $this->tool->unpublishContent('media', 'uuid-1', 'en'));
    }

    public function testEntityNotFoundReturnsErrorWithoutDispatch(): void
    {
        $this->snippetRepository->getOneBy(Argument::cetera())->willThrow(new \RuntimeException('not found'));
        $this->messageBus->dispatch(Argument::cetera())->shouldNotBeCalled();

        $result = $this->tool->unpublishContent('snippet', 'missing-uuid', 'en');

        $this->assertArrayHasKey('error', $result);
    }

    public function testMethodHasMcpToolAttribute(): void
    {
        $attributes = (new \ReflectionMethod(ContentUnpublishTool::class, 'unpublishContent'))->getAttributes(McpTool::class);
        $this->assertSame('sulu_content_unpublish', $attributes[0]->newInstance()->name);
    }

    public function testUnpublishContentThrowsToolCallExceptionWhenPermissionDenied(): void
    {
        $this->setupEntity('snippet');

        $this->permissionChecker->check(Argument::cetera())
            ->willThrow(new PermissionDeniedException('sulu.snippet.snippets', PermissionTypes::LIVE, 'en'));

        $this->messageBus->dispatch(Argument::cetera())->shouldNotBeCalled();

        $this->expectException(ToolCallException::class);

        $this->tool->unpublishContent('snippet', 'uuid-1', 'en');
    }

    private function setupEntity(string $type): void
    {
        $entity = match ($type) {
            'article' => new Article(),
            'snippet' => new Snippet(),
            default => (new Page())->setWebspaceKey('example'),
        };

        match ($type) {
            'article' => $this->articleRepository->getOneBy(Argument::cetera())->willReturn($entity),
            'snippet' => $this->snippetRepository->getOneBy(Argument::cetera())->willReturn($entity),
            default => $this->pageRepository->getOneBy(Argument::cetera())->willReturn($entity),
        };

        if ('article' === $type) {
            $dimensionContent = $entity->createDimensionContent();
            $dimensionContent->setTemplateKey('default');
            $this->contentManager->resolve(Argument::cetera())->willReturn($dimensionContent);
        }
    }
}
