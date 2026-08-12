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

namespace Sulu\Mcp\Tests\Unit\Infrastructure\Sulu\AdminLink;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Sulu\Bundle\AdminBundle\Admin\View\View;
use Sulu\Bundle\AdminBundle\Admin\View\ViewRegistry;
use Sulu\Bundle\AdminBundle\Exception\ViewNotFoundException;
use Sulu\Bundle\CategoryBundle\Admin\CategoryAdmin;
use Sulu\Mcp\Infrastructure\Sulu\AdminLink\CategoryAdminLinkProvider;

#[CoversClass(CategoryAdminLinkProvider::class)]
final class CategoryAdminLinkProviderTest extends TestCase
{
    use ProphecyTrait;

    /**
     * @var ObjectProphecy<ViewRegistry>
     */
    private ObjectProphecy $viewRegistry;

    private CategoryAdminLinkProvider $provider;

    protected function setUp(): void
    {
        $this->viewRegistry = $this->prophesize(ViewRegistry::class);
        $this->viewRegistry->findViewByName(CategoryAdmin::EDIT_FORM_VIEW)->willReturn(
            new View(CategoryAdmin::EDIT_FORM_VIEW, '/categories/:locale/:id', 'form'),
        );
        $this->viewRegistry->findViewByName(Argument::any())->will(
            static function(array $args): View {
                throw new ViewNotFoundException($args[0]);
            }
        );

        $this->provider = new CategoryAdminLinkProvider($this->viewRegistry->reveal());
    }

    public function testGetTypeReturnsCategory(): void
    {
        $this->assertSame('category', $this->provider->getType());
    }

    public function testBuildPathWithIntegerId(): void
    {
        $result = $this->provider->buildPath(['locale' => 'en', 'id' => 3]);

        $this->assertSame('/categories/en/3', $result);
    }

    public function testBuildPathWithStringId(): void
    {
        $result = $this->provider->buildPath(['locale' => 'en', 'id' => '3']);

        $this->assertSame('/categories/en/3', $result);
    }

    /**
     * @return array<string, array<array<string, mixed>>>
     */
    public static function invalidContextProvider(): array
    {
        return [
            'missing locale' => [['id' => 3]],
            'missing id' => [['locale' => 'en']],
            'empty locale' => [['locale' => '', 'id' => 3]],
            'empty string id' => [['locale' => 'en', 'id' => '']],
            'zero int id' => [['locale' => 'en', 'id' => 0]],
            'negative int id' => [['locale' => 'en', 'id' => -2]],
            'empty context' => [[]],
        ];
    }

    /**
     * @param array<string, mixed> $context
     */
    #[DataProvider('invalidContextProvider')]
    public function testBuildPathReturnsNullForInvalidContext(array $context): void
    {
        $this->assertNull($this->provider->buildPath($context));
    }
}
