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
use Sulu\Mcp\UserInterface\Mcp\Tool\Content\ContentPublishTool;
use Sulu\Messenger\Infrastructure\Symfony\Messenger\FlushMiddleware\EnableFlushStamp;
use Sulu\Page\Application\Message\ApplyWorkflowTransitionPageMessage;
use Sulu\Page\Domain\Model\Page;
use Sulu\Page\Domain\Repository\PageRepositoryInterface;
use Sulu\Snippet\Domain\Model\Snippet;
use Sulu\Snippet\Domain\Repository\SnippetRepositoryInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

#[CoversClass(ContentPublishTool::class)]
final class ContentPublishToolTest extends TestCase
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

    private ContentPublishTool $tool;

    protected function setUp(): void
    {
        $this->messageBus = $this->prophesize(MessageBusInterface::class);
        $this->pageRepository = $this->prophesize(PageRepositoryInterface::class);
        $this->articleRepository = $this->prophesize(ArticleRepositoryInterface::class);
        $this->snippetRepository = $this->prophesize(SnippetRepositoryInterface::class);
        $this->contentManager = $this->prophesize(ContentManagerInterface::class);
        $this->permissionChecker = $this->prophesize(ToolPermissionCheckerInterface::class);

        $this->tool = new ContentPublishTool(
            $this->messageBus->reveal(),
            new ContentTypeResolver($this->pageRepository->reveal(), $this->articleRepository->reveal(), $this->snippetRepository->reveal()),
            $this->contentManager->reveal(),
            $this->permissionChecker->reveal(),
            new ContentSecurityContextResolver(new ArticleSecurityContextResolver(new FakeGroupProvider())),
        );
    }

    public function testPublishPageDispatchesTransitionWithPublishName(): void
    {
        $this->setupEntity('page');

        $this->messageBus->dispatch(Argument::cetera())
            ->will(static function(array $args) {
                /** @var Envelope $envelope */
                $envelope = $args[0];
                self::assertInstanceOf(ApplyWorkflowTransitionPageMessage::class, $envelope->getMessage());
                self::assertArrayHasKey(EnableFlushStamp::class, $envelope->all());

                return $envelope->with(new HandledStamp(null, 'handler'));
            })
            ->shouldBeCalledOnce();

        $result = $this->tool->publishContent('page', 'uuid-1', 'en');

        $this->assertSame(['success' => true, 'type' => 'page', 'uuid' => 'uuid-1', 'action' => 'published', 'locale' => 'en'], $result);
    }

    public function testUnsupportedTypeReturnsError(): void
    {
        $this->messageBus->dispatch(Argument::cetera())->shouldNotBeCalled();
        $this->assertArrayHasKey('error', $this->tool->publishContent('media', 'uuid-1', 'en'));
    }

    public function testEntityNotFoundReturnsErrorWithoutDispatch(): void
    {
        $this->pageRepository->getOneBy(Argument::cetera())->willThrow(new \RuntimeException('not found'));
        $this->messageBus->dispatch(Argument::cetera())->shouldNotBeCalled();

        $result = $this->tool->publishContent('page', 'missing-uuid', 'en');

        $this->assertArrayHasKey('error', $result);
    }

    public function testErrorOnException(): void
    {
        $this->setupEntity('article');
        $this->messageBus->dispatch(Argument::cetera())->willThrow(new \RuntimeException('boom'));
        $this->assertStringContainsString('boom', $this->tool->publishContent('article', 'uuid-1', 'en')['error']);
    }

    public function testMethodHasMcpToolAttribute(): void
    {
        $attributes = (new \ReflectionMethod(ContentPublishTool::class, 'publishContent'))->getAttributes(McpTool::class);
        $this->assertSame('sulu_content_publish', $attributes[0]->newInstance()->name);
    }

    public function testPublishContentThrowsToolCallExceptionWhenPermissionDenied(): void
    {
        $this->setupEntity('page');

        $this->permissionChecker->check(Argument::cetera())
            ->willThrow(new PermissionDeniedException('sulu.webspaces.example', PermissionTypes::LIVE, 'en'));

        $this->messageBus->dispatch(Argument::cetera())->shouldNotBeCalled();

        $this->expectException(ToolCallException::class);

        $this->tool->publishContent('page', 'uuid-1', 'en');
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
