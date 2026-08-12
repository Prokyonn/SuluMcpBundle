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

namespace Sulu\Mcp\Tests\Unit\UserInterface\Mcp\Tool\Navigation;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Sulu\Component\Security\Authentication\UserInterface;
use Sulu\Component\Security\Authorization\SecurityCheckerInterface;
use Sulu\Component\Webspace\Manager\WebspaceCollection;
use Sulu\Component\Webspace\Manager\WebspaceManagerInterface;
use Sulu\Component\Webspace\Webspace;
use Sulu\Mcp\Application\Security\ToolPermissionChecker;
use Sulu\Mcp\Application\Security\WebspacePermissionResolver;
use Sulu\Mcp\Tests\Unit\UserInterface\Mcp\Tool\OutputSchemaAssertionTrait;
use Sulu\Mcp\UserInterface\Mcp\Tool\Navigation\NavigationGetTool;
use Sulu\Page\Domain\Repository\NavigationRepositoryInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

/**
 * Asserts NavigationGetTool's real return value against its declared outputSchema.
 */
#[CoversClass(NavigationGetTool::class)]
final class NavigationGetToolOutputSchemaTest extends TestCase
{
    use OutputSchemaAssertionTrait;
    use ProphecyTrait;

    /**
     * @param list<string> $grantedWebspaceKeys
     */
    private function tool(NavigationRepositoryInterface $navigationRepository, array $grantedWebspaceKeys): NavigationGetTool
    {
        $webspaces = [];
        foreach (['website'] as $key) {
            $webspace = new Webspace();
            $webspace->setKey($key);
            $webspaces[$key] = $webspace;
        }

        $webspaceManager = $this->prophesize(WebspaceManagerInterface::class);
        $webspaceManager->getWebspaceCollection()->willReturn(new WebspaceCollection($webspaces));

        $securityChecker = $this->prophesize(SecurityCheckerInterface::class);
        $securityChecker->hasPermission(Argument::cetera())->willReturn([] !== $grantedWebspaceKeys);

        $tokenStorage = $this->prophesize(TokenStorageInterface::class);
        $token = $this->prophesize(TokenInterface::class);
        $token->getUser()->willReturn($this->prophesize(UserInterface::class)->reveal());
        $tokenStorage->getToken()->willReturn($token->reveal());

        $checker = new ToolPermissionChecker($securityChecker->reveal(), $tokenStorage->reveal());

        return new NavigationGetTool($navigationRepository, new WebspacePermissionResolver($webspaceManager->reveal(), $checker));
    }

    public function testSuccessResultMatchesOutputSchema(): void
    {
        $navigationRepository = $this->prophesize(NavigationRepositoryInterface::class);
        $navigationRepository->getNavigationTree(Argument::cetera())->willReturn([
            ['title' => 'Home', 'url' => '/', 'targetType' => 'page', 'children' => []],
        ]);

        $tool = $this->tool($navigationRepository->reveal(), ['website']);

        $result = $tool->getNavigation('website', 'en', 'main', 2);

        $this->assertResultMatchesOutputSchema(NavigationGetTool::class, 'getNavigation', $result);
    }

    public function testNotPermittedResultMatchesOutputSchema(): void
    {
        $navigationRepository = $this->prophesize(NavigationRepositoryInterface::class);

        $tool = $this->tool($navigationRepository->reveal(), []);

        $result = $tool->getNavigation('website', 'en');

        $this->assertResultMatchesOutputSchema(NavigationGetTool::class, 'getNavigation', $result);
    }

    public function testErrorResultMatchesOutputSchema(): void
    {
        $navigationRepository = $this->prophesize(NavigationRepositoryInterface::class);
        $navigationRepository->getNavigationTree(Argument::cetera())->willThrow(new \RuntimeException('Invalid webspace'));

        $tool = $this->tool($navigationRepository->reveal(), ['website']);

        $result = $tool->getNavigation('website', 'en');

        $this->assertResultMatchesOutputSchema(NavigationGetTool::class, 'getNavigation', $result);
    }
}
