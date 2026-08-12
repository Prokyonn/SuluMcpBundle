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

namespace Sulu\Mcp\Tests\Unit\UserInterface\Mcp\Tool;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Sulu\Bundle\AdminBundle\Metadata\GroupProviderInterface;
use Sulu\Component\Security\Authentication\UserInterface;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Component\Security\Authorization\SecurityCheckerInterface;
use Sulu\Component\Webspace\Manager\WebspaceCollection;
use Sulu\Component\Webspace\Manager\WebspaceManagerInterface;
use Sulu\Mcp\Application\Metadata\ExtensionFieldsProvider;
use Sulu\Mcp\Application\Metadata\FieldValueExampleProvider;
use Sulu\Mcp\Application\Security\ToolPermissionChecker;
use Sulu\Mcp\Application\Security\ToolPermissionCheckerInterface;
use Sulu\Mcp\Application\Security\ToolVisibilityResolver;
use Sulu\Mcp\Application\Security\WebspacePermissionResolver;
use Sulu\Mcp\Infrastructure\Sulu\Security\ArticleSecurityContextResolver;
use Sulu\Mcp\UserInterface\Mcp\Resource\BlocksResource;
use Sulu\Mcp\UserInterface\Mcp\Resource\TemplatesResource;
use Sulu\Mcp\UserInterface\Mcp\Resource\WebspacesResource;
use Sulu\Mcp\UserInterface\Mcp\Tool\GetContextTool;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

/**
 * Asserts GetContextTool's real return value against its declared
 * outputSchema. getContext() has a single, unconditional return path, so one
 * representative call is enough.
 */
#[CoversClass(GetContextTool::class)]
final class GetContextToolOutputSchemaTest extends TestCase
{
    use OutputSchemaAssertionTrait;
    use ProphecyTrait;

    private function toolVisibilityResolver(): ToolVisibilityResolver
    {
        $checker = $this->prophesize(ToolPermissionCheckerInterface::class);
        $checker->has(Argument::cetera())->willReturn(false);

        $webspaceManager = $this->prophesize(WebspaceManagerInterface::class);
        $webspaceManager->getWebspaceCollection()->willReturn(new WebspaceCollection([]));

        $securityChecker = $this->prophesize(SecurityCheckerInterface::class);
        $tokenStorage = $this->prophesize(TokenStorageInterface::class);
        $token = $this->prophesize(TokenInterface::class);
        $token->getUser()->willReturn($this->prophesize(UserInterface::class)->reveal());
        $tokenStorage->getToken()->willReturn($token->reveal());

        $webspacePermissionResolver = new WebspacePermissionResolver(
            $webspaceManager->reveal(),
            new ToolPermissionChecker($securityChecker->reveal(), $tokenStorage->reveal()),
        );

        $groupProvider = $this->prophesize(GroupProviderInterface::class);
        $groupProvider->getGroups(Argument::any())->willReturn([]);

        return new ToolVisibilityResolver(
            [
                'sulu_tag_create' => [
                    'name' => 'sulu_tag_create',
                    'requirements' => [['context' => 'sulu.settings.tags', 'permission' => PermissionTypes::ADD]],
                    'contextArgument' => null, 'contextResolver' => null,
                    'objectResolved' => false, 'discoveryContexts' => [],
                ],
            ],
            $checker->reveal(),
            $webspacePermissionResolver,
            new ArticleSecurityContextResolver($groupProvider->reveal()),
            [],
            ['sulu_ping', 'sulu_get_context'],
        );
    }

    private function webspacePermissionResolver(): WebspacePermissionResolver
    {
        $webspaceManager = $this->prophesize(WebspaceManagerInterface::class);
        $webspaceManager->getWebspaceCollection()->willReturn(new WebspaceCollection([]));

        $securityChecker = $this->prophesize(SecurityCheckerInterface::class);
        $securityChecker->hasPermission(Argument::cetera())->willReturn(true);

        $tokenStorage = $this->prophesize(TokenStorageInterface::class);
        $token = $this->prophesize(TokenInterface::class);
        $token->getUser()->willReturn($this->prophesize(UserInterface::class)->reveal());
        $tokenStorage->getToken()->willReturn($token->reveal());

        return new WebspacePermissionResolver($webspaceManager->reveal(), new ToolPermissionChecker($securityChecker->reveal(), $tokenStorage->reveal()));
    }

    public function testSuccessResultMatchesOutputSchema(): void
    {
        $templates = $this->prophesize(TemplatesResource::class);
        $templates->getTemplates()->willReturn([
            'page' => [
                'default' => [
                    'key' => 'default',
                    'fields' => [
                        ['name' => 'title', 'type' => 'text_line'],
                        ['name' => 'blocks', 'type' => 'block', 'types' => [
                            'text' => ['key' => 'text', 'fields' => [
                                ['name' => 'content', 'type' => 'text_editor'],
                            ]],
                        ]],
                    ],
                ],
            ],
        ]);

        $blocks = $this->prophesize(BlocksResource::class);
        $blocks->getBlocks()->willReturn([
            ['key' => 'text', 'label' => 'Text', 'fields' => [], 'available_in_templates' => ['default']],
        ]);

        $webspaces = $this->prophesize(WebspacesResource::class);
        $webspaces->getWebspaces()->willReturn([
            ['key' => 'example', 'name' => 'Example', 'locales' => ['en'], 'url' => 'https://example.test'],
        ]);

        $extensionFields = $this->prophesize(ExtensionFieldsProvider::class);
        $extensionFields->getExtensionFields()->willReturn([
            'seo' => [['name' => 'title', 'type' => 'text_line', 'label' => 'SEO title', 'required' => false]],
            'excerpt' => [['name' => 'title', 'type' => 'text_line', 'label' => 'Excerpt title', 'required' => false]],
        ]);

        $tool = new GetContextTool(
            $templates->reveal(),
            $blocks->reveal(),
            $webspaces->reveal(),
            new FieldValueExampleProvider(),
            $extensionFields->reveal(),
            $this->toolVisibilityResolver(),
            $this->webspacePermissionResolver(),
        );

        $result = $tool->getContext('en');

        $this->assertResultMatchesOutputSchema(GetContextTool::class, 'getContext', $result);
    }
}
