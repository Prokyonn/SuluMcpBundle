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
use Sulu\Article\Application\Message\RemoveArticleMessage;
use Sulu\Article\Domain\Model\Article;
use Sulu\Article\Domain\Repository\ArticleRepositoryInterface;
use Sulu\Bundle\SecurityBundle\Entity\User;
use Sulu\Bundle\SecurityBundle\System\SystemStoreInterface;
use Sulu\Component\Security\Authorization\AccessControl\AccessControlRepositoryInterface;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Content\Application\ContentManager\ContentManagerInterface;
use Sulu\Mcp\Application\Content\ContentTypeResolver;
use Sulu\Mcp\Application\Security\ContentSecurityContextResolver;
use Sulu\Mcp\Application\Security\PageDescendantPermissionChecker;
use Sulu\Mcp\Application\Security\ToolPermissionCheckerInterface;
use Sulu\Mcp\Domain\Exception\PermissionDeniedException;
use Sulu\Mcp\Infrastructure\Sulu\Security\ArticleSecurityContextResolver;
use Sulu\Mcp\Tests\Unit\Fakes\FakeGroupProvider;
use Sulu\Mcp\UserInterface\Mcp\Tool\Content\ContentDeleteTool;
use Sulu\Messenger\Infrastructure\Symfony\Messenger\FlushMiddleware\EnableFlushStamp;
use Sulu\Page\Application\Message\RemovePageMessage;
use Sulu\Page\Domain\Model\Page;
use Sulu\Page\Domain\Repository\PageRepositoryInterface;
use Sulu\Snippet\Application\Message\RemoveSnippetMessage;
use Sulu\Snippet\Domain\Model\Snippet;
use Sulu\Snippet\Domain\Repository\SnippetRepositoryInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

#[CoversClass(ContentDeleteTool::class)]
final class ContentDeleteToolTest extends TestCase
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

    /**
     * @var ObjectProphecy<AccessControlRepositoryInterface>
     */
    private ObjectProphecy $accessControlRepository;

    /**
     * @var ObjectProphecy<Security>
     */
    private ObjectProphecy $security;

    private ContentDeleteTool $tool;

    protected function setUp(): void
    {
        $this->messageBus = $this->prophesize(MessageBusInterface::class);
        $this->pageRepository = $this->prophesize(PageRepositoryInterface::class);
        $this->articleRepository = $this->prophesize(ArticleRepositoryInterface::class);
        $this->snippetRepository = $this->prophesize(SnippetRepositoryInterface::class);
        $this->contentManager = $this->prophesize(ContentManagerInterface::class);
        $this->permissionChecker = $this->prophesize(ToolPermissionCheckerInterface::class);
        $this->accessControlRepository = $this->prophesize(AccessControlRepositoryInterface::class);
        $this->security = $this->prophesize(Security::class);
        $systemStore = $this->prophesize(SystemStoreInterface::class);
        $systemStore->getSystem()->willReturn('Sulu');

        $pageDescendantPermissionChecker = new PageDescendantPermissionChecker(
            $this->pageRepository->reveal(),
            $this->accessControlRepository->reveal(),
            $systemStore->reveal(),
            $this->security->reveal(),
            [PermissionTypes::DELETE => 8],
        );

        $this->tool = new ContentDeleteTool(
            $this->messageBus->reveal(),
            new ContentTypeResolver($this->pageRepository->reveal(), $this->articleRepository->reveal(), $this->snippetRepository->reveal()),
            $this->contentManager->reveal(),
            $this->permissionChecker->reveal(),
            new ContentSecurityContextResolver(new ArticleSecurityContextResolver(new FakeGroupProvider())),
            $pageDescendantPermissionChecker,
        );
    }

    public function testDeletePageDispatchesRemovePageMessageWithFlushStamp(): void
    {
        $this->setupEntity('page');

        $this->security->getUser()->willReturn(new User());
        $this->pageRepository->findDescendantIdsById(Argument::cetera())->willReturn([]);

        $this->messageBus->dispatch(Argument::cetera())
            ->will(static function (array $args) {
                /** @var Envelope $envelope */
                $envelope = $args[0];
                self::assertInstanceOf(RemovePageMessage::class, $envelope->getMessage());
                self::assertArrayHasKey(EnableFlushStamp::class, $envelope->all());

                return $envelope->with(new HandledStamp(null, 'handler'));
            })
            ->shouldBeCalledOnce();

        $result = $this->tool->deleteContent('page', 'uuid-1', 'en', true);

        $this->assertSame(['success' => true, 'type' => 'page', 'uuid' => 'uuid-1', 'deleted' => true], $result);
    }

    public function testDeleteSnippetDispatchesRemoveSnippetMessage(): void
    {
        $this->setupEntity('snippet');

        $this->messageBus->dispatch(Argument::cetera())
            ->will(static function (array $args) {
                /** @var Envelope $envelope */
                $envelope = $args[0];
                self::assertInstanceOf(RemoveSnippetMessage::class, $envelope->getMessage());

                return $envelope->with(new HandledStamp(null, 'handler'));
            })
            ->shouldBeCalledOnce();

        $result = $this->tool->deleteContent('snippet', 'uuid-2', 'en');

        $this->assertTrue($result['deleted']);
    }

    public function testDeleteArticleDispatchesRemoveArticleMessage(): void
    {
        $this->setupEntity('article');

        $this->messageBus->dispatch(Argument::cetera())
            ->will(static function (array $args) {
                /** @var Envelope $envelope */
                $envelope = $args[0];
                self::assertInstanceOf(RemoveArticleMessage::class, $envelope->getMessage());

                return $envelope->with(new HandledStamp(null, 'handler'));
            })
            ->shouldBeCalledOnce();

        $result = $this->tool->deleteContent('article', 'uuid-3', 'en');

        $this->assertTrue($result['deleted']);
    }

    public function testUnsupportedTypeReturnsErrorWithoutDispatch(): void
    {
        $this->messageBus->dispatch(Argument::cetera())->shouldNotBeCalled();

        $result = $this->tool->deleteContent('contact', 'uuid-1', 'en');

        $this->assertArrayHasKey('error', $result);
        $this->assertArrayNotHasKey('success', $result);
    }

    public function testEntityNotFoundReturnsErrorWithoutDispatch(): void
    {
        $this->articleRepository->getOneBy(Argument::cetera())->willThrow(new \RuntimeException('not found'));
        $this->messageBus->dispatch(Argument::cetera())->shouldNotBeCalled();

        $result = $this->tool->deleteContent('article', 'missing-uuid', 'en');

        $this->assertArrayHasKey('error', $result);
    }

    public function testErrorOnException(): void
    {
        $this->setupEntity('article');
        $this->messageBus->dispatch(Argument::cetera())->willThrow(new \RuntimeException('boom'));

        $result = $this->tool->deleteContent('article', 'uuid-1', 'en');

        $this->assertStringContainsString('boom', $result['error']);
    }

    public function testMethodHasMcpToolAttribute(): void
    {
        $attributes = (new \ReflectionMethod(ContentDeleteTool::class, 'deleteContent'))->getAttributes(McpTool::class);
        $this->assertCount(1, $attributes);
        $this->assertSame('sulu_content_delete', $attributes[0]->newInstance()->name);
    }

    public function testDeleteContentThrowsToolCallExceptionWhenPermissionDenied(): void
    {
        $this->setupEntity('page');

        $this->permissionChecker->check(Argument::cetera())
            ->willThrow(new PermissionDeniedException('sulu.webspaces.example', PermissionTypes::DELETE, 'en'));

        $this->messageBus->dispatch(Argument::cetera())->shouldNotBeCalled();

        $this->expectException(ToolCallException::class);

        $this->tool->deleteContent('page', 'uuid-1', 'en');
    }

    public function testDeletePageThrowsToolCallExceptionWhenDescendantPermissionDenied(): void
    {
        $this->setupEntity('page');

        $this->security->getUser()->willReturn(new User());
        $this->pageRepository->findDescendantIdsById(Argument::cetera())->willReturn(['child-1', 'child-2']);
        // Only child-1 is granted DELETE — child-2 is missing, so the gate must trip.
        $this->accessControlRepository->findIdsWithGrantedPermissions(Argument::cetera())->willReturn(['child-1']);

        $this->messageBus->dispatch(Argument::cetera())->shouldNotBeCalled();

        $this->expectException(ToolCallException::class);

        $this->tool->deleteContent('page', 'uuid-1', 'en', true);
    }

    public function testDeleteContentPassesConcretePageClassAsObjectTypeForBothChecks(): void
    {
        // Regression guard: Sulu stores per-page ACLs under the concrete Page class
        // (getSecuredClass()), not PageInterface — the interface matches no ACL row and
        // falls back to the webspace grant, for both the EDIT and DELETE check.
        $this->setupEntity('page');

        $this->security->getUser()->willReturn(new User());
        $this->pageRepository->findDescendantIdsById(Argument::cetera())->willReturn([]);
        $this->messageBus->dispatch(Argument::cetera())
            ->will(static fn (array $args) => $args[0]->with(new HandledStamp(null, 'handler')));

        $recordedCalls = [];
        $this->permissionChecker->check(Argument::cetera())
            ->will(static function (array $args) use (&$recordedCalls): void {
                [, $permissions, , $objectType, $objectId] = $args;
                $recordedCalls[] = [$permissions, $objectType, $objectId];
            })
            ->shouldBeCalledOnce();

        $result = $this->tool->deleteContent('page', 'uuid-1', 'en');

        $this->assertTrue($result['deleted']);
        $this->assertSame(
            [
                [[PermissionTypes::EDIT, PermissionTypes::DELETE], Page::class, 'uuid-1'],
            ],
            $recordedCalls,
        );
    }

    public function testDeletePageDispatchesWhenAllDescendantsGranted(): void
    {
        $this->setupEntity('page');

        $this->security->getUser()->willReturn(new User());
        $this->pageRepository->findDescendantIdsById(Argument::cetera())->willReturn(['child-1', 'child-2']);
        $this->accessControlRepository->findIdsWithGrantedPermissions(Argument::cetera())->willReturn(['child-1', 'child-2']);

        $this->messageBus->dispatch(Argument::cetera())
            ->will(static fn (array $args) => $args[0]->with(new HandledStamp(null, 'handler')))
            ->shouldBeCalledOnce();

        $result = $this->tool->deleteContent('page', 'uuid-1', 'en', true);

        $this->assertTrue($result['deleted']);
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
