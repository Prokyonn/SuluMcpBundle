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
use Prophecy\Prophecy\ObjectProphecy;
use Sulu\Bundle\SecurityBundle\Entity\User;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Component\Security\Authorization\SecurityCheckerInterface;
use Sulu\Mcp\Application\Security\ToolPermissionChecker;
use Sulu\Mcp\Domain\Exception\PermissionDeniedException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

#[CoversClass(ToolPermissionChecker::class)]
final class ToolPermissionCheckerTest extends TestCase
{
    use ProphecyTrait;

    /**
     * @var ObjectProphecy<SecurityCheckerInterface>
     */
    private ObjectProphecy $securityChecker;

    private TokenStorage $tokenStorage;

    private ToolPermissionChecker $checker;

    protected function setUp(): void
    {
        $this->securityChecker = $this->prophesize(SecurityCheckerInterface::class);
        $this->tokenStorage = new TokenStorage();
        $this->checker = new ToolPermissionChecker($this->securityChecker->reveal(), $this->tokenStorage);
        $this->authenticate();
    }

    private function authenticate(): void
    {
        $this->tokenStorage->setToken(new UsernamePasswordToken(new User(), 'main'));
    }

    public function testCheckPassesWhenGranted(): void
    {
        $this->securityChecker->hasPermission(Argument::cetera())->willReturn(true);
        $this->checker->check('sulu.settings.tags', PermissionTypes::ADD);
        $this->addToAssertionCount(1);
    }

    public function testCheckThrowsWhenDenied(): void
    {
        $this->securityChecker->hasPermission(Argument::cetera())->willReturn(false);
        $this->expectException(PermissionDeniedException::class);
        $this->checker->check('sulu.settings.tags', PermissionTypes::ADD, 'en');
    }

    public function testCheckPassesWhenAllListedPermissionsGranted(): void
    {
        $this->securityChecker->hasPermission(Argument::cetera())->willReturn(true);
        $this->checker->check('sulu.settings.tags', [PermissionTypes::EDIT, PermissionTypes::ADD]);
        $this->addToAssertionCount(1);
    }

    public function testCheckThrowsWhenOneListedPermissionIsDenied(): void
    {
        $this->securityChecker->hasPermission(Argument::any(), PermissionTypes::ADD)->willReturn(false);
        $this->securityChecker->hasPermission(Argument::any(), PermissionTypes::EDIT)->willReturn(true);

        try {
            $this->checker->check('sulu.settings.tags', [PermissionTypes::EDIT, PermissionTypes::ADD], 'en');
            self::fail('Expected PermissionDeniedException');
        } catch (PermissionDeniedException $e) {
            self::assertStringContainsString(PermissionTypes::ADD, $e->getMessage());
        }
    }

    public function testDeniesEmptyContextWithoutCallingSulu(): void
    {
        $this->securityChecker->hasPermission(Argument::cetera())->shouldNotBeCalled();
        self::assertFalse($this->checker->has('', PermissionTypes::VIEW));
    }

    public function testDeniesUnsubstitutedPlaceholderContext(): void
    {
        $this->securityChecker->hasPermission(Argument::cetera())->shouldNotBeCalled();
        self::assertFalse($this->checker->has('sulu.webspaces.#context#', PermissionTypes::EDIT));
    }

    public function testDeniesWhenNoAuthenticatedUser(): void
    {
        $tokenStorage = new TokenStorage();
        $checker = new ToolPermissionChecker($this->securityChecker->reveal(), $tokenStorage);
        $this->securityChecker->hasPermission(Argument::cetera())->shouldNotBeCalled();
        self::assertFalse($checker->has('sulu.settings.tags', PermissionTypes::VIEW));
    }
}
