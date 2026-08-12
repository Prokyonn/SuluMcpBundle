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

namespace Sulu\Mcp\Tests\Unit\Infrastructure\Mcp;

use Mcp\Capability\Discovery\DiscoveryState;
use Mcp\Capability\Registry;
use Mcp\Capability\Registry\ToolReference;
use Mcp\Schema\Tool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Component\Security\Authorization\SecurityCheckerInterface;
use Sulu\Component\Webspace\Manager\WebspaceManagerInterface;
use Sulu\Mcp\Application\Security\ToolPermissionChecker;
use Sulu\Mcp\Application\Security\ToolPermissionCheckerInterface;
use Sulu\Mcp\Application\Security\ToolVisibilityResolver;
use Sulu\Mcp\Application\Security\WebspacePermissionResolver;
use Sulu\Mcp\Infrastructure\Mcp\FilteredRegistry;
use Sulu\Mcp\Infrastructure\Sulu\Security\ArticleSecurityContextResolver;
use Sulu\Mcp\Tests\Application\TestBundle\Metadata\TestGroupProvider;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

#[CoversClass(FilteredRegistry::class)]
final class FilteredRegistryTest extends TestCase
{
    use ProphecyTrait;

    /**
     * The real Mcp\Capability\Registry is cheap to construct and gives
     * behavioral assertions (what got registered) instead of interaction
     * assertions on a mock.
     */
    private Registry $inner;

    protected function setUp(): void
    {
        $this->inner = new Registry();
    }

    private function tool(string $name): Tool
    {
        return new Tool($name, ['type' => 'object', 'properties' => [], 'required' => null], null, null);
    }

    /**
     * @param array<string, array{name: string, requirements: list<array{context: string, permission: string}>, contextArgument: ?string, contextResolver: ?string, objectResolved: bool, discoveryContexts: list<string>}> $map
     * @param ObjectProphecy<ToolPermissionCheckerInterface> $checker
     */
    private function visibilityResolver(array $map, ObjectProphecy $checker): ToolVisibilityResolver
    {
        $webspaceManager = $this->prophesize(WebspaceManagerInterface::class);
        $innerChecker = new ToolPermissionChecker(
            $this->prophesize(SecurityCheckerInterface::class)->reveal(),
            $this->prophesize(TokenStorageInterface::class)->reveal(),
        );

        return new ToolVisibilityResolver(
            $map,
            $checker->reveal(),
            new WebspacePermissionResolver($webspaceManager->reveal(), $innerChecker),
            new ArticleSecurityContextResolver(TestGroupProvider::singleGroup()),
            [],
            ['sulu_ping', 'sulu_get_context'],
        );
    }

    public function testGetToolsExcludesHiddenAndIncludesPermittedAndAllowlisted(): void
    {
        $this->inner->registerTool($this->tool('sulu_ping'), static fn () => null);
        $this->inner->registerTool($this->tool('sulu_tag_create'), static fn () => null);
        $this->inner->registerTool($this->tool('sulu_tag_list'), static fn () => null);

        $checker = $this->prophesize(ToolPermissionCheckerInterface::class);
        $checker->has('sulu.settings.tags', PermissionTypes::VIEW, Argument::cetera())->willReturn(true);
        $checker->has(Argument::cetera())->willReturn(false);

        $map = [
            'sulu_tag_create' => [
                'name' => 'sulu_tag_create',
                'requirements' => [['context' => 'sulu.settings.tags', 'permission' => PermissionTypes::ADD]],
                'contextArgument' => null, 'contextResolver' => null,
                'objectResolved' => false, 'discoveryContexts' => [],
            ],
            'sulu_tag_list' => [
                'name' => 'sulu_tag_list',
                'requirements' => [['context' => 'sulu.settings.tags', 'permission' => PermissionTypes::VIEW]],
                'contextArgument' => null, 'contextResolver' => null,
                'objectResolved' => false, 'discoveryContexts' => [],
            ],
        ];

        $registry = new FilteredRegistry($this->inner, $this->visibilityResolver($map, $checker));

        $names = \array_keys((array) $registry->getTools(null, null)->getArrayCopy());

        self::assertContains('sulu_ping', $names);
        self::assertContains('sulu_tag_list', $names);
        self::assertNotContains('sulu_tag_create', $names);
    }

    public function testGetToolsPaginatesFilteredResults(): void
    {
        $this->inner->registerTool($this->tool('sulu_ping'), static fn () => null);
        $this->inner->registerTool($this->tool('sulu_get_context'), static fn () => null);
        $this->inner->registerTool($this->tool('sulu_tag_create'), static fn () => null);

        // No permission map entry for sulu_tag_create => hidden; only the two
        // allowlisted tools survive filtering.
        $checker = $this->prophesize(ToolPermissionCheckerInterface::class);
        $registry = new FilteredRegistry($this->inner, $this->visibilityResolver([], $checker));

        $firstPage = $registry->getTools(1, null);
        self::assertCount(1, $firstPage->references);
        self::assertNotNull($firstPage->nextCursor);

        $secondPage = $registry->getTools(1, $firstPage->nextCursor);
        self::assertCount(1, $secondPage->references);
        self::assertNull($secondPage->nextCursor);

        $collected = [...\array_values($firstPage->references), ...\array_values($secondPage->references)];
        $collectedNames = \array_map(static fn (Tool $tool): string => $tool->name, $collected);
        \sort($collectedNames);
        self::assertSame(['sulu_get_context', 'sulu_ping'], $collectedNames);
    }

    public function testGetToolIsNotFilteredByVisibility(): void
    {
        $tool = $this->tool('sulu_tag_create');
        $this->inner->registerTool($tool, static fn () => null);

        $checker = $this->prophesize(ToolPermissionCheckerInterface::class);
        $registry = new FilteredRegistry($this->inner, $this->visibilityResolver([], $checker));

        self::assertSame($tool, $registry->getTool('sulu_tag_create')->tool);
    }

    public function testGetToolIsNotFilteredByDisabledToolNames(): void
    {
        $tool = $this->tool('sulu_dangerous');
        $this->inner->registerTool($tool, static fn () => null);

        $checker = $this->prophesize(ToolPermissionCheckerInterface::class);
        $registry = new FilteredRegistry($this->inner, $this->visibilityResolver([], $checker), ['sulu_dangerous']);

        self::assertSame($tool, $registry->getTool('sulu_dangerous')->tool);
    }

    public function testRegisterToolSkipsDisabledToolNames(): void
    {
        $checker = $this->prophesize(ToolPermissionCheckerInterface::class);
        $registry = new FilteredRegistry($this->inner, $this->visibilityResolver([], $checker), ['sulu_dangerous']);

        $registry->registerTool($this->tool('sulu_dangerous'), static fn () => null);

        self::assertFalse($this->inner->hasTools());
    }

    public function testRegisterToolForwardsNonDisabledTool(): void
    {
        $tool = $this->tool('sulu_safe');

        $checker = $this->prophesize(ToolPermissionCheckerInterface::class);
        $registry = new FilteredRegistry($this->inner, $this->visibilityResolver([], $checker), ['sulu_dangerous']);

        $registry->registerTool($tool, static fn () => null);

        self::assertSame($tool, $this->inner->getTool('sulu_safe')->tool);
    }

    public function testSetDiscoveryStateStripsDisabledToolNames(): void
    {
        $dangerousRef = new ToolReference($this->tool('sulu_dangerous'), static fn () => null);
        $safeRef = new ToolReference($this->tool('sulu_safe'), static fn () => null);

        $state = new DiscoveryState(tools: ['sulu_dangerous' => $dangerousRef, 'sulu_safe' => $safeRef]);

        $checker = $this->prophesize(ToolPermissionCheckerInterface::class);
        $registry = new FilteredRegistry($this->inner, $this->visibilityResolver([], $checker), ['sulu_dangerous']);

        $registry->setDiscoveryState($state);

        $tools = $this->inner->getDiscoveryState()->getTools();
        self::assertArrayNotHasKey('sulu_dangerous', $tools);
        self::assertArrayHasKey('sulu_safe', $tools);
    }
}
