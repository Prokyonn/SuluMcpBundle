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

namespace Sulu\Mcp\Tests\Unit\UserInterface\Controller\Website;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Sulu\Mcp\UserInterface\Controller\Website\WellKnownController;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[CoversClass(WellKnownController::class)]
final class WellKnownControllerTest extends TestCase
{
    use ProphecyTrait;

    public function testProtectedResourceMetadataUsesConfiguredScopesAndMcpPath(): void
    {
        $controller = new WellKnownController($this->urlGenerator(), 'https://sulu.example.com/', '/admin/custom-mcp', ['mcp:tools']);

        $response = $controller->protectedResourceMetadata();
        $body = $this->json($response->getContent());

        self::assertSame('https://sulu.example.com/admin/custom-mcp', $body['resource']);
        self::assertSame(['mcp:tools'], $body['scopes_supported']);
    }

    public function testAuthorizationServerMetadataUsesConfiguredScopes(): void
    {
        $controller = new WellKnownController($this->urlGenerator(), 'https://sulu.example.com', '/admin/mcp', ['mcp:tools']);

        $response = $controller->authorizationServerMetadata();
        $body = $this->json($response->getContent());

        self::assertSame('https://sulu.example.com/admin/mcp/authorize', $body['authorization_endpoint']);
        self::assertSame('https://sulu.example.com/admin/mcp/token', $body['token_endpoint']);
        self::assertSame('https://sulu.example.com/admin/mcp/register', $body['registration_endpoint']);
        self::assertSame(['mcp:tools'], $body['scopes_supported']);
        self::assertContains('none', $body['token_endpoint_auth_methods_supported']);
    }

    private function urlGenerator(): UrlGeneratorInterface
    {
        $paths = [
            'sulu_mcp_oauth_authorize' => '/admin/mcp/authorize',
            'sulu_mcp_oauth_token' => '/admin/mcp/token',
            'sulu_mcp_client_registration' => '/admin/mcp/register',
        ];

        $urlGenerator = $this->prophesize(UrlGeneratorInterface::class);
        $urlGenerator->generate(Argument::cetera())->will(
            static fn (array $args): string => $paths[$args[0]] ?? self::fail('Unexpected route "'.$args[0].'".'),
        );

        return $urlGenerator->reveal();
    }

    /**
     * @return array<string, mixed>
     */
    private function json(string|false $content): array
    {
        self::assertIsString($content);
        $data = json_decode($content, true);
        self::assertIsArray($data);

        return $data;
    }
}
