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

namespace Sulu\Mcp\Tests\Unit\Application\Security;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Sulu\Bundle\SecurityBundle\Entity\User;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Component\Security\Authorization\SecurityCheckerInterface;
use Sulu\Component\Webspace\Manager\WebspaceCollection;
use Sulu\Component\Webspace\Manager\WebspaceManagerInterface;
use Sulu\Component\Webspace\Webspace;
use Sulu\Mcp\Application\Security\ToolPermissionChecker;
use Sulu\Mcp\Application\Security\WebspacePermissionResolver;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

#[CoversClass(WebspacePermissionResolver::class)]
final class WebspacePermissionResolverTest extends TestCase
{
    use ProphecyTrait;

    public function testReturnsOnlyPermittedWebspaceKeys(): void
    {
        $webspaceManager = $this->prophesize(WebspaceManagerInterface::class);
        $webspaceManager->getWebspaceCollection()->willReturn(
            new WebspaceCollection([
                'example' => $this->webspace('example'),
                'blog' => $this->webspace('blog'),
            ])
        );

        $securityChecker = $this->prophesize(SecurityCheckerInterface::class);
        $securityChecker->hasPermission(Argument::cetera())->will(
            static fn ($args): bool => 'sulu.webspaces.example' === $args[0]->getSecurityContext(),
        );

        $tokenStorage = new TokenStorage();
        $tokenStorage->setToken(new UsernamePasswordToken(new User(), 'main'));

        $checker = new ToolPermissionChecker($securityChecker->reveal(), $tokenStorage);
        $resolver = new WebspacePermissionResolver($webspaceManager->reveal(), $checker);

        self::assertSame(['example'], $resolver->permittedWebspaceKeys(PermissionTypes::EDIT));
    }

    private function webspace(string $key): Webspace
    {
        $webspace = new Webspace();
        $webspace->setKey($key);

        return $webspace;
    }
}
