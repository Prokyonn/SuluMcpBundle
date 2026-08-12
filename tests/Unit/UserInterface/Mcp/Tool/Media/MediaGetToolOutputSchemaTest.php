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
use Sulu\Bundle\MediaBundle\Entity\Collection;
use Sulu\Bundle\MediaBundle\Entity\CollectionType;
use Sulu\Bundle\MediaBundle\Entity\Media as MediaEntity;
use Sulu\Bundle\MediaBundle\Media\Manager\MediaManagerInterface;
use Sulu\Mcp\Application\Security\ToolPermissionCheckerInterface;
use Sulu\Mcp\Tests\Unit\UserInterface\Mcp\Tool\OutputSchemaAssertionTrait;
use Sulu\Mcp\UserInterface\Mcp\Tool\Media\MediaGetTool;

#[CoversClass(MediaGetTool::class)]
final class MediaGetToolOutputSchemaTest extends TestCase
{
    use OutputSchemaAssertionTrait;
    use ProphecyTrait;

    public function testSuccessResultMatchesOutputSchema(): void
    {
        $collectionType = $this->prophesize(CollectionType::class);
        $collectionType->getKey()->willReturn('collection');

        $collection = $this->prophesize(Collection::class);
        $collection->getId()->willReturn(5);
        $collection->getType()->willReturn($collectionType->reveal());

        $mediaEntity = $this->prophesize(MediaEntity::class);
        $mediaEntity->getCollection()->willReturn($collection->reveal());

        $media = $this->prophesize(Media::class);
        $media->getEntity()->willReturn($mediaEntity->reveal());
        $media->getId()->willReturn(42);
        $media->getTitle()->willReturn('Hero Image');
        $media->getDescription()->willReturn('A beautiful hero image');
        $media->getCopyright()->willReturn('(c) 2026 Example');
        $media->getMimeType()->willReturn('image/png');
        $media->getSize()->willReturn(54321);
        $media->getUrl()->willReturn('/media/42/hero.png');
        $media->getFormats()->willReturn([
            'sulu-100x100' => '/media/42/hero.png?v=1-0&inline=1',
        ]);

        $mediaManager = $this->prophesize(MediaManagerInterface::class);
        $mediaManager->getById(Argument::any(), Argument::any())->willReturn($media->reveal());

        $permissionChecker = $this->prophesize(ToolPermissionCheckerInterface::class);

        $tool = new MediaGetTool($mediaManager->reveal(), $permissionChecker->reveal());

        $result = $tool->getMedia(42, 'en');

        $this->assertResultMatchesOutputSchema(MediaGetTool::class, 'getMedia', $result);
    }

    public function testNotFoundResultMatchesOutputSchema(): void
    {
        $mediaManager = $this->prophesize(MediaManagerInterface::class);
        $mediaManager->getById(Argument::any(), Argument::any())->willThrow(new \RuntimeException('Not found'));

        $permissionChecker = $this->prophesize(ToolPermissionCheckerInterface::class);

        $tool = new MediaGetTool($mediaManager->reveal(), $permissionChecker->reveal());

        $result = $tool->getMedia(999, 'en');

        $this->assertResultMatchesOutputSchema(MediaGetTool::class, 'getMedia', $result);
    }
}
