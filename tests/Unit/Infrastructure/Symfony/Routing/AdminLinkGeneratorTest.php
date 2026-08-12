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

namespace Sulu\Mcp\Tests\Unit\Infrastructure\Symfony\Routing;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Sulu\Bundle\AdminBundle\Admin\View\View;
use Sulu\Bundle\AdminBundle\Admin\View\ViewRegistry;
use Sulu\Mcp\Infrastructure\Sulu\AdminLink\SnippetAdminLinkProvider;
use Sulu\Mcp\Infrastructure\Symfony\Routing\AdminLinkGenerator;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;

#[CoversClass(AdminLinkGenerator::class)]
final class AdminLinkGeneratorTest extends TestCase
{
    use ProphecyTrait;

    /**
     * @var ObjectProphecy<RouterInterface>
     */
    private ObjectProphecy $router;

    private SnippetAdminLinkProvider $snippetProvider;

    protected function setUp(): void
    {
        $this->router = $this->prophesize(RouterInterface::class);

        $viewRegistry = $this->prophesize(ViewRegistry::class);
        $viewRegistry->findViewByName(Argument::type('string'))
            ->willReturn(new View('view', '/snippets/:locale/:id', 'form'));

        $this->snippetProvider = new SnippetAdminLinkProvider($viewRegistry->reveal());
    }

    public function testGenerateReturnsAbsoluteDeeplink(): void
    {
        $this->router
            ->generate('sulu_admin', [], UrlGeneratorInterface::ABSOLUTE_URL)
            ->shouldBeCalledOnce()
            ->willReturn('https://example.com/admin/');

        $generator = new AdminLinkGenerator($this->router->reveal(), [$this->snippetProvider]);

        $result = $generator->generate('snippet', ['locale' => 'en', 'uuid' => 'abc']);

        $this->assertSame('https://example.com/admin/#/snippets/en/abc', $result);
    }

    public function testGenerateStripsTrailingSlashFromBase(): void
    {
        $this->router->generate(Argument::cetera())->willReturn('https://example.com/admin/');

        $generator = new AdminLinkGenerator($this->router->reveal(), [$this->snippetProvider]);

        $result = $generator->generate('snippet', ['locale' => 'en', 'uuid' => 'abc']);

        $this->assertStringNotContainsString('//#', (string) $result);
        $this->assertSame('https://example.com/admin/#/snippets/en/abc', $result);
    }

    public function testGenerateReturnsNullForUnknownType(): void
    {
        $this->router->generate(Argument::cetera())->shouldNotBeCalled();

        $generator = new AdminLinkGenerator($this->router->reveal(), [$this->snippetProvider]);

        $result = $generator->generate('unknown_type', ['locale' => 'en', 'uuid' => 'abc']);

        $this->assertNull($result);
    }

    public function testGenerateReturnsNullWhenProviderBuildPathReturnsNull(): void
    {
        $this->router->generate(Argument::cetera())->shouldNotBeCalled();

        $generator = new AdminLinkGenerator($this->router->reveal(), [$this->snippetProvider]);

        // Missing 'uuid' causes buildPath to return null
        $result = $generator->generate('snippet', ['locale' => 'en']);

        $this->assertNull($result);
    }

    public function testGenerateReturnsNullWhenRouterThrows(): void
    {
        $this->router->generate(Argument::cetera())->willThrow(new \RuntimeException('Route not found'));

        $generator = new AdminLinkGenerator($this->router->reveal(), [$this->snippetProvider]);

        $result = $generator->generate('snippet', ['locale' => 'en', 'uuid' => 'abc']);

        $this->assertNull($result);
    }

    public function testGenerateReturnsNullWhenProviderListIsEmpty(): void
    {
        $this->router->generate(Argument::cetera())->shouldNotBeCalled();

        $generator = new AdminLinkGenerator($this->router->reveal(), []);

        $result = $generator->generate('snippet', ['locale' => 'en', 'uuid' => 'abc']);

        $this->assertNull($result);
    }

    public function testGenerateSkipsNonMatchingProviders(): void
    {
        $this->router->generate(Argument::cetera())->willReturn('https://example.com/admin');

        $generator = new AdminLinkGenerator($this->router->reveal(), [$this->snippetProvider]);

        // 'media' type is not served by SnippetAdminLinkProvider
        $result = $generator->generate('media', ['locale' => 'en', 'id' => 42]);

        $this->assertNull($result);
    }
}
