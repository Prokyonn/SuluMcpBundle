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

use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Sulu\Bundle\SecurityBundle\Entity\User;
use Sulu\Component\Security\Authorization\SecurityCheckerInterface;
use Sulu\Component\Webspace\Manager\WebspaceCollection;
use Sulu\Component\Webspace\Manager\WebspaceManagerInterface;
use Sulu\Component\Webspace\Webspace;
use Sulu\Content\Application\ContentManager\ContentManagerInterface;
use Sulu\Mcp\Application\Security\AccessControlFilterFactory;
use Sulu\Mcp\Application\Security\ToolPermissionChecker;
use Sulu\Mcp\Application\Security\WebspacePermissionResolver;
use Sulu\Mcp\UserInterface\Mcp\Tool\Page\PageListTool;
use Sulu\Page\Domain\Model\Page;
use Sulu\Page\Domain\Repository\PageRepositoryInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

#[CoversClass(PageListTool::class)]
final class PageListToolTest extends TestCase
{
    use ProphecyTrait;

    /**
     * @var ObjectProphecy<PageRepositoryInterface>
     */
    private ObjectProphecy $pageRepository;

    /**
     * @var ObjectProphecy<ContentManagerInterface>
     */
    private ObjectProphecy $contentManager;

    private WebspacePermissionResolver $webspacePermissionResolver;

    private PageListTool $tool;

    protected function setUp(): void
    {
        $this->pageRepository = $this->prophesize(PageRepositoryInterface::class);
        $this->contentManager = $this->prophesize(ContentManagerInterface::class);
        // Default grants 'example', so existing happy-path tests are unaffected by
        // the webspace filter.
        $this->webspacePermissionResolver = $this->webspaceResolver(['example']);
        $this->tool = new PageListTool($this->pageRepository->reveal(), $this->contentManager->reveal(), $this->webspacePermissionResolver, new AccessControlFilterFactory(null, ['view' => 64, 'add' => 32, 'edit' => 16, 'delete' => 8, 'archive' => 4, 'live' => 2, 'security' => 1]));
    }

    /**
     * Builds a real WebspacePermissionResolver (it's final, can't be mocked) over
     * mocked dependencies.
     *
     * @param list<string> $grantedWebspaceKeys webspace keys on which EDIT is granted
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

        $tokenStorage = new TokenStorage();
        $tokenStorage->setToken(new UsernamePasswordToken(new User(), 'main'));

        return new WebspacePermissionResolver($webspaceManager->reveal(), new ToolPermissionChecker($securityChecker->reveal(), $tokenStorage));
    }

    public function testListPagesReturnsPaginatedResults(): void
    {
        $page1 = new Page('uuid-1');
        $page2 = new Page('uuid-2');

        $this->pageRepository->findIdentifiersBy(Argument::cetera())->willReturn(['uuid-1', 'uuid-2']);
        $this->pageRepository->findBy(Argument::cetera())->willReturn([$page1, $page2]);
        $this->pageRepository->countBy(Argument::cetera())->willReturn(5);

        $dimensionContent = $page1->createDimensionContent();
        $this->contentManager->resolve(Argument::cetera())->willReturn($dimensionContent);
        $this->contentManager->normalize(Argument::cetera())->willReturn(['title' => 'Test']);

        $result = $this->tool->listPages('example', 'en');

        $this->assertCount(2, $result['pages']);
        $this->assertSame(5, $result['total']);
        $this->assertSame(1, $result['page']);
        $this->assertSame(20, $result['limit']);
        $this->assertSame('uuid-1', $result['pages'][0]['uuid']);
        $this->assertSame('uuid-2', $result['pages'][1]['uuid']);
    }

    public function testListPagesAppliesTemplateFilter(): void
    {
        $this->pageRepository
            ->findIdentifiersBy(
                Argument::that(fn (array $filters): bool => isset($filters['templateKeys'])
                    && $filters['templateKeys'] === ['default']),
                Argument::any(),
            )
            ->shouldBeCalledOnce()
            ->willReturn([]);
        $this->pageRepository->countBy(Argument::cetera())->willReturn(0);

        $this->tool->listPages('example', 'en', 'default');
    }

    public function testListPagesAppliesParentIdFilter(): void
    {
        $this->pageRepository
            ->findIdentifiersBy(
                Argument::that(fn (array $filters): bool => isset($filters['parentId'])
                    && 'parent-uuid' === $filters['parentId']),
                Argument::any(),
            )
            ->shouldBeCalledOnce()
            ->willReturn([]);
        $this->pageRepository->countBy(Argument::cetera())->willReturn(0);

        $this->tool->listPages('example', 'en', null, 'parent-uuid');
    }

    public function testListPagesDefaultsPaginationToPage1Limit20(): void
    {
        $this->pageRepository
            ->findIdentifiersBy(
                Argument::that(fn (array $filters): bool => 1 === $filters['page'] && 20 === $filters['limit']),
                Argument::any(),
            )
            ->shouldBeCalledOnce()
            ->willReturn([]);
        $this->pageRepository->countBy(Argument::cetera())->willReturn(0);

        $this->tool->listPages('example', 'en');
    }

    public function testListPagesResolvesAndNormalizesEachPage(): void
    {
        $page1 = new Page('uuid-1');
        $page2 = new Page('uuid-2');
        $page3 = new Page('uuid-3');

        $this->pageRepository->findIdentifiersBy(Argument::cetera())->willReturn(['uuid-1', 'uuid-2', 'uuid-3']);
        $this->pageRepository->findBy(Argument::cetera())->willReturn([$page1, $page2, $page3]);
        $this->pageRepository->countBy(Argument::cetera())->willReturn(3);

        $dimensionContent = $page1->createDimensionContent();
        $this->contentManager->resolve(Argument::cetera())->shouldBeCalledTimes(3)->willReturn($dimensionContent);
        $this->contentManager->normalize(Argument::cetera())->shouldBeCalledTimes(3)->willReturn(['title' => 'Test']);

        $this->tool->listPages('example', 'en');
    }

    public function testListPagesMethodHasMcpToolAttribute(): void
    {
        $reflection = new \ReflectionMethod(PageListTool::class, 'listPages');
        $attributes = $reflection->getAttributes(McpTool::class);

        $this->assertCount(1, $attributes, 'listPages() method must have exactly one #[McpTool] attribute');

        $instance = $attributes[0]->newInstance();
        $this->assertSame('sulu_page_list', $instance->name);
    }

    public function testParentIdParameterHasSchemaAttribute(): void
    {
        $reflection = new \ReflectionMethod(PageListTool::class, 'listPages');
        $parameter = $reflection->getParameters()[3];
        $this->assertSame('parentId', $parameter->getName());

        $attributes = $parameter->getAttributes(Schema::class);
        $this->assertCount(1, $attributes);

        $schema = $attributes[0]->newInstance();
        $this->assertStringContainsString('UUID', $schema->description);
    }

    public function testListPagesReturnsEmptyListWhenNoWebspaceIsPermitted(): void
    {
        $tool = new PageListTool($this->pageRepository->reveal(), $this->contentManager->reveal(), $this->webspaceResolver([]), new AccessControlFilterFactory(null, ['view' => 64, 'add' => 32, 'edit' => 16, 'delete' => 8, 'archive' => 4, 'live' => 2, 'security' => 1]));

        $this->pageRepository->findBy(Argument::cetera())->shouldNotBeCalled();

        $result = $tool->listPages('example', 'en');

        $this->assertSame([], $result['pages']);
        $this->assertSame(0, $result['total']);
        $this->assertArrayHasKey('hint', $result);
    }

    public function testListPagesReturnsEmptyListWhenRequestedWebspaceIsNotPermitted(): void
    {
        $tool = new PageListTool($this->pageRepository->reveal(), $this->contentManager->reveal(), $this->webspaceResolver(['other']), new AccessControlFilterFactory(null, ['view' => 64, 'add' => 32, 'edit' => 16, 'delete' => 8, 'archive' => 4, 'live' => 2, 'security' => 1]));

        $this->pageRepository->findBy(Argument::cetera())->shouldNotBeCalled();

        $result = $tool->listPages('example', 'en');

        $this->assertSame([], $result['pages']);
        $this->assertSame(0, $result['total']);
        $this->assertStringContainsString('example', (string) $result['hint']);
    }

    /**
     * Webspace scoping must happen in the query, not by discarding rows afterward,
     * or `total` counts pages the caller cannot see.
     */
    public function testListPagesScopesQueryToRequestedWebspace(): void
    {
        $isScoped = static fn (array $filters): bool => 'example' === ($filters['webspaceKey'] ?? null);

        $this->pageRepository
            ->findIdentifiersBy(Argument::that($isScoped), Argument::any())
            ->shouldBeCalledOnce()
            ->willReturn([]);

        $this->pageRepository
            ->countBy(Argument::that($isScoped))
            ->shouldBeCalledOnce()
            ->willReturn(0);

        $this->tool->listPages('example', 'en');
    }
}
