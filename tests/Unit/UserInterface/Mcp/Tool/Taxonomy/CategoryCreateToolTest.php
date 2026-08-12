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

namespace Sulu\Mcp\Tests\Unit\UserInterface\Mcp\Tool\Taxonomy;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Sulu\Bundle\CategoryBundle\Category\CategoryManagerInterface;
use Sulu\Bundle\CategoryBundle\Entity\Category;
use Sulu\Mcp\Infrastructure\Sulu\AdminLink\CategoryAdminLinkProvider;
use Sulu\Mcp\Infrastructure\Symfony\Routing\AdminLinkGenerator;
use Sulu\Mcp\Tests\Application\TestBundle\Admin\TestViewRegistry;
use Sulu\Mcp\UserInterface\Mcp\Tool\Taxonomy\CategoryCreateTool;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\UserInterface;

#[CoversClass(CategoryCreateTool::class)]
final class CategoryCreateToolTest extends TestCase
{
    use ProphecyTrait;

    /**
     * @var ObjectProphecy<CategoryManagerInterface>
     */
    private ObjectProphecy $categoryManager;

    private TokenStorage $tokenStorage;

    private CategoryCreateTool $tool;

    protected function setUp(): void
    {
        $this->categoryManager = $this->prophesize(CategoryManagerInterface::class);
        $this->tokenStorage = new TokenStorage();

        $router = $this->prophesize(RouterInterface::class);
        $router->generate(Argument::cetera())->willReturn('https://example.com/admin/');
        $adminLinkGenerator = new AdminLinkGenerator($router->reveal(), [new CategoryAdminLinkProvider(new TestViewRegistry())]);

        $this->tool = new CategoryCreateTool($this->categoryManager->reveal(), $this->tokenStorage, $adminLinkGenerator);
    }

    public function testCreateCategoryReturnsSuccess(): void
    {
        $this->authenticateAsUser(1);

        $category = new Category();
        $category->setId(10);
        $category->setKey('technology');

        $this->categoryManager
            ->save(['name' => 'Technology', 'locale' => 'en', 'key' => 'technology'], 1, 'en')
            ->shouldBeCalledOnce()
            ->willReturn($category);

        $result = $this->tool->createCategory('en', 'Technology', 'technology');

        $this->assertTrue($result['success']);
        $this->assertSame(10, $result['id']);
        $this->assertSame('Technology', $result['name']);
        $this->assertSame('technology', $result['key']);
        $this->assertSame('https://example.com/admin/#/categories/en/10', $result['admin_url']);
    }

    public function testCreateCategoryWithParentId(): void
    {
        $this->authenticateAsUser(1);

        $category = new Category();
        $category->setId(11);
        $category->setKey('php');

        $this->categoryManager
            ->save(['name' => 'PHP', 'locale' => 'en', 'parent' => 10], 1, 'en')
            ->shouldBeCalledOnce()
            ->willReturn($category);

        $result = $this->tool->createCategory('en', 'PHP', null, 10);

        $this->assertTrue($result['success']);
    }

    public function testCreateCategoryReturnsErrorWhenNoUser(): void
    {
        $result = $this->tool->createCategory('en', 'Test');

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('No authenticated user', $result['error']);
        $this->assertTrue(\array_key_exists('hint', $result));
        $this->assertIsString($result['hint']);
        $this->assertNotEmpty($result['hint']);
    }

    public function testCreateCategoryReturnsHintOnSaveFailure(): void
    {
        $this->authenticateAsUser(1);

        $this->categoryManager->save(Argument::cetera())->willThrow(new \RuntimeException('Duplicate key'));

        $result = $this->tool->createCategory('en', 'Duplicate');

        $this->assertArrayHasKey('error', $result);
        $this->assertTrue(\array_key_exists('hint', $result));
        $this->assertIsString($result['hint']);
        $this->assertNotEmpty($result['hint']);
    }

    public function testMethodHasMcpToolAttribute(): void
    {
        $reflection = new \ReflectionMethod(CategoryCreateTool::class, 'createCategory');
        $attributes = $reflection->getAttributes(McpTool::class);

        $this->assertCount(1, $attributes);
        $this->assertSame('sulu_category_create', $attributes[0]->newInstance()->name);
    }

    public function testParentIdParameterHasSchemaAttribute(): void
    {
        $reflection = new \ReflectionMethod(CategoryCreateTool::class, 'createCategory');
        $parameter = $reflection->getParameters()[3];
        $this->assertSame('parentId', $parameter->getName());

        $attributes = $parameter->getAttributes(Schema::class);
        $this->assertCount(1, $attributes);

        $schema = $attributes[0]->newInstance();
        $this->assertStringContainsString('Integer', $schema->description);
        $this->assertStringContainsString('NOT a UUID', $schema->description);
    }

    private function authenticateAsUser(int $userId): void
    {
        $user = new class($userId) implements UserInterface {
            public function __construct(private readonly int $id)
            {
            }

            public function getId(): int
            {
                return $this->id;
            }

            public function getRoles(): array
            {
                return [];
            }

            public function eraseCredentials(): void
            {
            }

            public function getUserIdentifier(): string
            {
                return 'admin';
            }
        };

        $this->tokenStorage->setToken(new UsernamePasswordToken($user, 'main'));
    }
}
