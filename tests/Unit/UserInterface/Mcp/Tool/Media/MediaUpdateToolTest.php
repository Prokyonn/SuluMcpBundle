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

namespace Sulu\Mcp\Tests\Unit\UserInterface\Mcp\Tool\Media;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Sulu\Bundle\MediaBundle\Api\Media;
use Sulu\Bundle\MediaBundle\Entity\Collection;
use Sulu\Bundle\MediaBundle\Entity\CollectionType;
use Sulu\Bundle\MediaBundle\Entity\Media as MediaEntity;
use Sulu\Bundle\MediaBundle\Media\Manager\MediaManagerInterface;
use Sulu\Bundle\SecurityBundle\Entity\User;
use Sulu\Component\Media\SystemCollections\SystemCollectionManagerInterface;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Mcp\Application\Security\ToolPermissionCheckerInterface;
use Sulu\Mcp\Domain\Exception\PermissionDeniedException;
use Sulu\Mcp\Infrastructure\Sulu\AdminLink\MediaAdminLinkProvider;
use Sulu\Mcp\Infrastructure\Symfony\Routing\AdminLinkGenerator;
use Sulu\Mcp\Tests\Application\TestBundle\Admin\TestViewRegistry;
use Sulu\Mcp\UserInterface\Mcp\Tool\Media\MediaUpdateTool;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

#[CoversClass(MediaUpdateTool::class)]
final class MediaUpdateToolTest extends TestCase
{
    use ProphecyTrait;

    /**
     * @var ObjectProphecy<MediaManagerInterface>
     */
    private ObjectProphecy $mediaManager;

    private TokenStorage $tokenStorage;

    /**
     * @var ObjectProphecy<ToolPermissionCheckerInterface>
     */
    private ObjectProphecy $permissionChecker;

    private MediaUpdateTool $tool;

    protected function setUp(): void
    {
        $this->mediaManager = $this->prophesize(MediaManagerInterface::class);
        $this->tokenStorage = new TokenStorage();
        $this->permissionChecker = $this->prophesize(ToolPermissionCheckerInterface::class);

        $router = $this->prophesize(RouterInterface::class);
        $router->generate(Argument::cetera())->willReturn('https://example.com/admin/');
        $adminLinkGenerator = new AdminLinkGenerator($router->reveal(), [new MediaAdminLinkProvider(new TestViewRegistry())]);

        $this->tool = new MediaUpdateTool($this->mediaManager->reveal(), $this->tokenStorage, $adminLinkGenerator, $this->permissionChecker->reveal());
    }

    /**
     * Subclassed only to make the id settable -- User's id has no setter.
     */
    private function authenticateAsUser(int $userId = 1): void
    {
        $user = new class($userId) extends User {
            public function __construct(private readonly int $fakeId)
            {
                parent::__construct();
            }

            public function getId(): int
            {
                return $this->fakeId;
            }
        };

        $this->tokenStorage->setToken(new UsernamePasswordToken($user, 'main'));
    }

    /**
     * @param non-empty-string|null $typeKey
     *
     * @return ObjectProphecy<Media>
     */
    private function loadedMedia(int $collectionId, ?string $typeKey = null): ObjectProphecy
    {
        $collectionType = new CollectionType();
        $collectionType->setKey($typeKey);

        $collection = $this->prophesize(Collection::class);
        $collection->getId()->willReturn($collectionId);
        $collection->getType()->willReturn($collectionType);

        $mediaEntity = $this->prophesize(MediaEntity::class);
        $mediaEntity->getCollection()->willReturn($collection->reveal());

        $media = $this->prophesize(Media::class);
        $media->getEntity()->willReturn($mediaEntity->reveal());

        return $media;
    }

    public function testUpdateMediaSuccessfully(): void
    {
        $this->authenticateAsUser();

        $this->mediaManager->getById(Argument::cetera())->willReturn($this->loadedMedia(5)->reveal());

        $media = $this->prophesize(Media::class);
        $media->getId()->willReturn(42);
        $media->getTitle()->willReturn('Updated Title');

        $this->mediaManager
            ->save(
                null,
                Argument::that(fn (array $data): bool => 42 === $data['id']
                    && 'en' === $data['locale']
                    && 'Updated Title' === $data['title']),
                1,
            )
            ->shouldBeCalledOnce()
            ->willReturn($media->reveal());

        $result = $this->tool->updateMedia(42, 'en', 'Updated Title');

        $this->assertTrue($result['success']);
        $this->assertSame(42, $result['id']);
        $this->assertSame('Updated Title', $result['title']);
        $this->assertSame('https://example.com/admin/#/media/en/42', $result['admin_url']);
    }

    public function testUpdateMediaReturnsErrorWhenNoUser(): void
    {
        $result = $this->tool->updateMedia(42, 'en', 'Title');

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('authenticated', $result['error']);
        $this->assertTrue(\array_key_exists('hint', $result));
        $this->assertIsString($result['hint']);
        $this->assertNotEmpty($result['hint']);
    }

    public function testUpdateMediaPassesOnlyProvidedFields(): void
    {
        $this->authenticateAsUser();

        $this->mediaManager->getById(Argument::cetera())->willReturn($this->loadedMedia(5)->reveal());

        $media = $this->prophesize(Media::class);
        $media->getId()->willReturn(42);
        $media->getTitle()->willReturn('Original');

        $this->mediaManager
            ->save(
                null,
                Argument::that(fn (array $data): bool => 42 === $data['id']
                    && isset($data['copyright'])
                    && !\array_key_exists('title', $data)
                    && !\array_key_exists('description', $data)),
                1,
            )
            ->shouldBeCalledOnce()
            ->willReturn($media->reveal());

        $this->tool->updateMedia(42, 'en', null, null, '(c) 2026');
    }

    public function testUpdateMediaReturnsHintOnSaveFailure(): void
    {
        $this->authenticateAsUser();

        $this->mediaManager->getById(Argument::cetera())->willReturn($this->loadedMedia(5)->reveal());
        $this->mediaManager->save(Argument::cetera())->willThrow(new \RuntimeException('Save failed'));

        $result = $this->tool->updateMedia(42, 'en', 'Title');

        $this->assertArrayHasKey('error', $result);
        $this->assertTrue(\array_key_exists('hint', $result));
        $this->assertIsString($result['hint']);
        $this->assertNotEmpty($result['hint']);
    }

    public function testUpdateMediaChecksCollectionPermission(): void
    {
        $this->authenticateAsUser();

        $this->mediaManager->getById(Argument::cetera())->willReturn($this->loadedMedia(9)->reveal());

        $media = $this->prophesize(Media::class);
        $media->getId()->willReturn(42);
        $media->getTitle()->willReturn('Title');
        $this->mediaManager->save(Argument::cetera())->willReturn($media->reveal());

        $this->permissionChecker
            ->check('sulu.media.collections', PermissionTypes::EDIT, 'en', Collection::class, 9)
            ->shouldBeCalledOnce();

        $this->tool->updateMedia(42, 'en', 'Title');
    }

    public function testUpdateMediaAlsoChecksSystemCollectionPermission(): void
    {
        $this->authenticateAsUser();

        $this->mediaManager->getById(Argument::cetera())->willReturn($this->loadedMedia(1, SystemCollectionManagerInterface::COLLECTION_TYPE)->reveal());

        $media = $this->prophesize(Media::class);
        $media->getId()->willReturn(42);
        $media->getTitle()->willReturn('Title');
        $this->mediaManager->save(Argument::cetera())->willReturn($media->reveal());

        $calls = [];
        $this->permissionChecker
            ->check(Argument::cetera())
            ->will(function (array $args) use (&$calls): void {
                $calls[] = [$args[0], $args[1]];
            })
            ->shouldBeCalledTimes(2);

        $this->tool->updateMedia(42, 'en', 'Title');

        $this->assertSame(
            [
                ['sulu.media.system_collections', PermissionTypes::VIEW],
                ['sulu.media.collections', PermissionTypes::EDIT],
            ],
            $calls,
        );
    }

    public function testUpdateMediaThrowsToolCallExceptionWhenSystemCollectionViewDenied(): void
    {
        $this->authenticateAsUser();

        $this->mediaManager->getById(Argument::cetera())->willReturn($this->loadedMedia(1, SystemCollectionManagerInterface::COLLECTION_TYPE)->reveal());

        $this->permissionChecker
            ->check(Argument::cetera())
            ->willThrow(new PermissionDeniedException('sulu.media.system_collections', PermissionTypes::VIEW, 'en'));

        $this->mediaManager->save(Argument::cetera())->shouldNotBeCalled();

        $this->expectException(ToolCallException::class);

        $this->tool->updateMedia(42, 'en', 'Title');
    }

    public function testUpdateMediaThrowsToolCallExceptionWhenPermissionDenied(): void
    {
        $this->authenticateAsUser();

        $this->mediaManager->getById(Argument::cetera())->willReturn($this->loadedMedia(9)->reveal());

        $this->permissionChecker
            ->check(Argument::cetera())
            ->willThrow(new PermissionDeniedException('sulu.media.collections', PermissionTypes::EDIT, 'en'));

        $this->mediaManager->save(Argument::cetera())->shouldNotBeCalled();

        $this->expectException(ToolCallException::class);

        $this->tool->updateMedia(42, 'en', 'Title');
    }

    public function testUpdateMediaMethodHasMcpToolAttribute(): void
    {
        $reflection = new \ReflectionMethod(MediaUpdateTool::class, 'updateMedia');
        $attributes = $reflection->getAttributes(McpTool::class);

        $this->assertCount(1, $attributes, 'updateMedia() method must have exactly one #[McpTool] attribute');

        $instance = $attributes[0]->newInstance();
        $this->assertSame('sulu_media_update', $instance->name);
    }
}
