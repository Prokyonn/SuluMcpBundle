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

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Sulu\Bundle\MediaBundle\Api\Media;
use Sulu\Bundle\MediaBundle\Media\Manager\MediaManagerInterface;
use Sulu\Component\Media\SystemCollections\SystemCollectionManagerInterface;
use Sulu\Mcp\Application\Security\ToolPermissionCheckerInterface;
use Sulu\Mcp\Tests\Unit\UserInterface\Mcp\Tool\OutputSchemaAssertionTrait;
use Sulu\Mcp\UserInterface\Mcp\Tool\Media\MediaListTool;

#[CoversClass(MediaListTool::class)]
final class MediaListToolOutputSchemaTest extends TestCase
{
    use OutputSchemaAssertionTrait;
    use ProphecyTrait;

    public function testSuccessResultMatchesOutputSchema(): void
    {
        $media = $this->prophesize(Media::class);
        $media->getId()->willReturn(1);
        $media->getTitle()->willReturn('Photo 1');
        $media->getMimeType()->willReturn('image/jpeg');
        $media->getSize()->willReturn(12345);
        $media->getUrl()->willReturn('/media/1/photo1.jpg');
        $media->getCollection()->willReturn(3);

        $mediaManager = $this->prophesize(MediaManagerInterface::class);
        $mediaManager->get(Argument::cetera())->willReturn([$media->reveal()]);
        $mediaManager->getCount()->willReturn(10);

        $permissionChecker = $this->prophesize(ToolPermissionCheckerInterface::class);
        $permissionChecker->has(Argument::cetera())->willReturn(true);

        $systemCollectionManager = $this->prophesize(SystemCollectionManagerInterface::class);

        $tool = new MediaListTool($mediaManager->reveal(), $permissionChecker->reveal(), $systemCollectionManager->reveal());

        $result = $tool->listMedia('en');

        $this->assertResultMatchesOutputSchema(MediaListTool::class, 'listMedia', $result);
    }

    public function testHintResultMatchesOutputSchema(): void
    {
        $allowed = $this->prophesize(Media::class);
        $allowed->getId()->willReturn(1);
        $allowed->getTitle()->willReturn('Allowed');
        $allowed->getMimeType()->willReturn('image/jpeg');
        $allowed->getSize()->willReturn(111);
        $allowed->getUrl()->willReturn('/media/1/allowed.jpg');
        $allowed->getCollection()->willReturn(3);

        $denied = $this->prophesize(Media::class);
        $denied->getId()->willReturn(2);
        $denied->getTitle()->willReturn('Denied');
        $denied->getMimeType()->willReturn('application/pdf');
        $denied->getSize()->willReturn(222);
        $denied->getUrl()->willReturn('/media/2/denied.pdf');
        $denied->getCollection()->willReturn(7);

        $mediaManager = $this->prophesize(MediaManagerInterface::class);
        $mediaManager->get(Argument::cetera())->willReturn([$allowed->reveal(), $denied->reveal()]);
        $mediaManager->getCount()->willReturn(2);

        $permissionChecker = $this->prophesize(ToolPermissionCheckerInterface::class);
        $permissionChecker->has(Argument::cetera())
            ->will(function (array $args): bool {
                if ('sulu.media.system_collections' === $args[0]) {
                    return true;
                }

                return 3 === ($args[4] ?? null);
            });

        $systemCollectionManager = $this->prophesize(SystemCollectionManagerInterface::class);

        $tool = new MediaListTool($mediaManager->reveal(), $permissionChecker->reveal(), $systemCollectionManager->reveal());

        $result = $tool->listMedia('en');

        $this->assertResultMatchesOutputSchema(MediaListTool::class, 'listMedia', $result);
        self::assertArrayHasKey('hint', $result);
    }
}
