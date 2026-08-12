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

namespace Sulu\Mcp\Tests\Unit\Infrastructure\Sulu\Security\EntryPoint;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Sulu\Mcp\Infrastructure\Sulu\Security\EntryPoint\OAuthAuthorizeEntryPoint;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

#[CoversClass(OAuthAuthorizeEntryPoint::class)]
final class OAuthAuthorizeEntryPointTest extends TestCase
{
    use ProphecyTrait;

    public function testRedirectsToAdminLoginOnAuthorizePath(): void
    {
        $inner = $this->prophesize(AuthenticationEntryPointInterface::class);
        $inner->start(Argument::cetera())->shouldNotBeCalled();

        $entryPoint = new OAuthAuthorizeEntryPoint($inner->reveal(), $this->urlGenerator());
        $response = $entryPoint->start(Request::create('/admin/mcp/authorize'));

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('/admin/', $response->getTargetUrl());
    }

    public function testDelegatesToInnerEntryPointForOtherAdminPaths(): void
    {
        $innerResponse = new Response('inner');
        $inner = $this->prophesize(AuthenticationEntryPointInterface::class);
        $inner->start(Argument::cetera())->shouldBeCalledOnce()->willReturn($innerResponse);

        $entryPoint = new OAuthAuthorizeEntryPoint($inner->reveal(), $this->urlGenerator());
        $response = $entryPoint->start(Request::create('/admin'));

        $this->assertSame($innerResponse, $response);
    }

    public function testDelegatesToInnerEntryPointForPathMerelyContainingAuthorizeFragment(): void
    {
        $innerResponse = new Response('inner');
        $inner = $this->prophesize(AuthenticationEntryPointInterface::class);
        $inner->start(Argument::cetera())->shouldBeCalledOnce()->willReturn($innerResponse);

        $entryPoint = new OAuthAuthorizeEntryPoint($inner->reveal(), $this->urlGenerator());
        $response = $entryPoint->start(Request::create('/evil/mcp/authorize'));

        $this->assertSame($innerResponse, $response);
    }

    public function testDelegatesToInnerEntryPointForAdjacentPathSharingPrefix(): void
    {
        $innerResponse = new Response('inner');
        $inner = $this->prophesize(AuthenticationEntryPointInterface::class);
        $inner->start(Argument::cetera())->shouldBeCalledOnce()->willReturn($innerResponse);

        $entryPoint = new OAuthAuthorizeEntryPoint($inner->reveal(), $this->urlGenerator());
        $response = $entryPoint->start(Request::create('/admin/mcp/authorize-not-really'));

        $this->assertSame($innerResponse, $response);
    }

    private function urlGenerator(): UrlGeneratorInterface
    {
        $routes = new RouteCollection();
        $routes->add('sulu_mcp_oauth_authorize', new Route('/admin/mcp/authorize'));
        $routes->add('sulu_admin', new Route('/admin/'));

        return new UrlGenerator($routes, new RequestContext());
    }
}
