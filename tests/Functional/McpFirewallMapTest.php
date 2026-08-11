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

namespace Sulu\Mcp\Tests\Functional;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;

/**
 * The `_mcp` namespace is split across three firewalls, and only their patterns
 * keep the paths apart. Getting one wrong fails silently rather than loudly: an
 * unanchored `^/admin/_mcp` pulls authorize and consent into the stateless MCP
 * firewall, where there is no session and no logged-in Sulu user, and the
 * consent flow dies with an unauthenticated redirect loop instead of an error.
 *
 * The configuration under test is tests/Application/config/config_admin.yml,
 * which mirrors the snippet in docs/configuration.md that consuming projects
 * copy into their own security.yaml.
 */
#[CoversNothing]
final class McpFirewallMapTest extends FunctionalTestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function pathProvider(): iterable
    {
        // Stateless OAuth bearer, anchored to the transport path alone.
        yield 'transport' => ['/admin/_mcp', 'mcp'];
        yield 'transport with trailing slash' => ['/admin/_mcp/', 'mcp'];

        // The client authenticates itself (client secret / PKCE).
        yield 'token' => ['/admin/_mcp/token', 'mcp_public'];
        yield 'registration' => ['/admin/_mcp/register', 'mcp_public'];

        // Need the logged-in Sulu user, so they must fall through to the admin firewall.
        yield 'authorize' => ['/admin/_mcp/authorize', 'admin'];
        yield 'consent' => ['/admin/_mcp/consent/6f1c0a', 'admin'];
    }

    #[DataProvider('pathProvider')]
    public function testPathIsHandledByExpectedFirewall(string $path, string $firewall): void
    {
        /** @var Security $security */
        $security = self::getContainer()->get('security.helper');

        $config = $security->getFirewallConfig(Request::create($path));

        self::assertNotNull($config, \sprintf('No firewall matches "%s".', $path));
        self::assertSame($firewall, $config->getName());
    }

    public function testTokenAndRegistrationRunWithoutSymfonySecurity(): void
    {
        /** @var Security $security */
        $security = self::getContainer()->get('security.helper');

        foreach (['/admin/_mcp/token', '/admin/_mcp/register'] as $path) {
            $config = $security->getFirewallConfig(Request::create($path));

            self::assertNotNull($config);
            self::assertFalse(
                $config->isSecurityEnabled(),
                \sprintf('"%s" must stay outside Symfony security; the client authenticates itself.', $path),
            );
        }
    }

    public function testTransportIsStatelessAndConsentIsNot(): void
    {
        /** @var Security $security */
        $security = self::getContainer()->get('security.helper');

        $transport = $security->getFirewallConfig(Request::create('/admin/_mcp'));
        $consent = $security->getFirewallConfig(Request::create('/admin/_mcp/consent/6f1c0a'));

        self::assertNotNull($transport);
        self::assertNotNull($consent);
        self::assertTrue($transport->isStateless(), 'The MCP transport must not issue session cookies.');
        self::assertFalse($consent->isStateless(), 'The consent screen needs the admin session.');
    }
}
