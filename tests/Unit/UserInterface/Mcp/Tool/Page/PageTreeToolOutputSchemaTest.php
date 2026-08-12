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

namespace Sulu\Mcp\Tests\Unit\UserInterface\Mcp\Tool\Page;

use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Sulu\Component\Security\Authentication\UserInterface;
use Sulu\Component\Security\Authorization\SecurityCheckerInterface;
use Sulu\Component\Webspace\Manager\WebspaceCollection;
use Sulu\Component\Webspace\Manager\WebspaceManagerInterface;
use Sulu\Component\Webspace\Webspace;
use Sulu\Content\Application\ContentManager\ContentManagerInterface;
use Sulu\Mcp\Application\Security\AccessControlFilterFactory;
use Sulu\Mcp\Application\Security\ToolPermissionChecker;
use Sulu\Mcp\Application\Security\WebspacePermissionResolver;
use Sulu\Mcp\Tests\Unit\UserInterface\Mcp\Tool\OutputSchemaAssertionTrait;
use Sulu\Mcp\UserInterface\Mcp\Tool\Page\PageTreeTool;
use Sulu\Page\Domain\Model\PageDimensionContentInterface;
use Sulu\Page\Domain\Model\PageInterface;
use Sulu\Page\Domain\Repository\PageRepositoryInterface;
use Sulu\Route\Domain\Model\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

/**
 * Asserts PageTreeTool's real return value against its declared outputSchema,
 * including the recursive tree-node shape.
 */
#[CoversClass(PageTreeTool::class)]
final class PageTreeToolOutputSchemaTest extends TestCase
{
    use OutputSchemaAssertionTrait;
    use ProphecyTrait;

    private function accessControlFilterFactory(): AccessControlFilterFactory
    {
        return new AccessControlFilterFactory(null, ['view' => 64, 'add' => 32, 'edit' => 16, 'delete' => 8, 'archive' => 4, 'live' => 2, 'security' => 1]);
    }

    /**
     * @param list<string> $grantedWebspaceKeys
     */
    private function webspaceResolver(array $grantedWebspaceKeys): WebspacePermissionResolver
    {
        $webspaces = [];
        foreach ($grantedWebspaceKeys as $key) {
            $webspace = new Webspace();
            $webspace->setKey($key);
            $webspaces[$key] = $webspace;
        }

        $webspaceManager = $this->prophesize(WebspaceManagerInterface::class);
        $webspaceManager->getWebspaceCollection()->willReturn(new WebspaceCollection($webspaces));

        $securityChecker = $this->prophesize(SecurityCheckerInterface::class);
        $securityChecker->hasPermission(Argument::cetera())->willReturn(true);

        $tokenStorage = $this->prophesize(TokenStorageInterface::class);
        $token = $this->prophesize(TokenInterface::class);
        $token->getUser()->willReturn($this->prophesize(UserInterface::class)->reveal());
        $tokenStorage->getToken()->willReturn($token->reveal());

        return new WebspacePermissionResolver($webspaceManager->reveal(), new ToolPermissionChecker($securityChecker->reveal(), $tokenStorage->reveal()));
    }

    public function testSuccessResultWithNestedChildrenMatchesOutputSchema(): void
    {
        $child = $this->prophesize(PageInterface::class);
        $child->getUuid()->willReturn('uuid-child');
        $child->getChildren()->willReturn(new ArrayCollection([]));
        $child->getParent()->willReturn(null);

        $parent = $this->prophesize(PageInterface::class);
        $parent->getUuid()->willReturn('uuid-parent');
        $parent->getChildren()->willReturn(new ArrayCollection([$child->reveal()]));
        $parent->getParent()->willReturn(null);

        $pageRepository = $this->prophesize(PageRepositoryInterface::class);
        $pageRepository->findByAsTree(Argument::cetera())->willReturn([$parent->reveal()]);

        $route = $this->prophesize(Route::class);
        $route->getSlug()->willReturn('/child');

        $dimensionContent = $this->prophesize(PageDimensionContentInterface::class);
        $dimensionContent->getTitle()->willReturn(null);
        $dimensionContent->getTemplateKey()->willReturn('default');
        $dimensionContent->getWorkflowPlace()->willReturn('published');
        $dimensionContent->getAvailableLocales()->willReturn(['en']);
        $dimensionContent->getRoute()->willReturn($route->reveal());

        $contentManager = $this->prophesize(ContentManagerInterface::class);
        $contentManager->resolve(Argument::any(), Argument::any())->willReturn($dimensionContent->reveal());

        $tool = new PageTreeTool(
            $pageRepository->reveal(),
            $contentManager->reveal(),
            $this->webspaceResolver(['example']),
            $this->accessControlFilterFactory(),
        );

        $result = $tool->getPageTree('example', 'en');

        $this->assertResultMatchesOutputSchema(PageTreeTool::class, 'getPageTree', $result);
    }

    public function testEmptyTreeWhenWebspaceNotPermittedMatchesOutputSchema(): void
    {
        $pageRepository = $this->prophesize(PageRepositoryInterface::class);
        $contentManager = $this->prophesize(ContentManagerInterface::class);

        $tool = new PageTreeTool(
            $pageRepository->reveal(),
            $contentManager->reveal(),
            $this->webspaceResolver([]),
            $this->accessControlFilterFactory(),
        );

        $result = $tool->getPageTree('example', 'en');

        $this->assertResultMatchesOutputSchema(PageTreeTool::class, 'getPageTree', $result);
        self::assertArrayHasKey('hint', $result);
    }
}
