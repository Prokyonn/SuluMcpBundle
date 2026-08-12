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
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FieldMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\TypedFormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\MetadataProviderInterface;
use Sulu\Bundle\SecurityBundle\Entity\User;
use Sulu\Component\Localization\Localization;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Component\Security\Authorization\SecurityCheckerInterface;
use Sulu\Component\Webspace\Manager\WebspaceCollection;
use Sulu\Component\Webspace\Manager\WebspaceManagerInterface;
use Sulu\Component\Webspace\Webspace;
use Sulu\Mcp\Application\Metadata\ExtensionFieldsProvider;
use Sulu\Mcp\Application\Metadata\FieldValueExampleProvider;
use Sulu\Mcp\Application\Security\ToolPermissionChecker;
use Sulu\Mcp\Application\Security\ToolPermissionCheckerInterface;
use Sulu\Mcp\Application\Security\ToolVisibilityResolver;
use Sulu\Mcp\Application\Security\WebspacePermissionResolver;
use Sulu\Mcp\Infrastructure\Sulu\Security\ArticleSecurityContextResolver;
use Sulu\Mcp\Tests\Application\TestBundle\Metadata\TestGroupProvider;
use Sulu\Mcp\UserInterface\Mcp\Resource\BlocksResource;
use Sulu\Mcp\UserInterface\Mcp\Resource\TemplatesResource;
use Sulu\Mcp\UserInterface\Mcp\Resource\WebspacesResource;
use Sulu\Mcp\UserInterface\Mcp\Tool\GetContextTool;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

#[CoversClass(GetContextTool::class)]
final class GetContextToolTest extends TestCase
{
    use ProphecyTrait;

    /**
     * Real ToolVisibilityResolver (final) with a mocked checker that denies
     * everything, mirroring ToolVisibilityResolverTest's helper.
     */
    private function toolVisibilityResolver(): ToolVisibilityResolver
    {
        $checker = $this->prophesize(ToolPermissionCheckerInterface::class);
        $checker->has(Argument::cetera())->willReturn(false);

        $webspaceManager = $this->prophesize(WebspaceManagerInterface::class);
        $webspaceManager->getWebspaceCollection()->willReturn(new WebspaceCollection([]));

        $securityChecker = $this->prophesize(SecurityCheckerInterface::class);

        $tokenStorage = new TokenStorage();
        $tokenStorage->setToken(new UsernamePasswordToken(new User(), 'main'));

        $webspacePermissionResolver = new WebspacePermissionResolver(
            $webspaceManager->reveal(),
            new ToolPermissionChecker($securityChecker->reveal(), $tokenStorage),
        );

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
            new ArticleSecurityContextResolver(TestGroupProvider::singleGroup()),
            [],
            ['sulu_ping', 'sulu_get_context'],
        );
    }

    /**
     * A real WebspacePermissionResolver (it's final) granting EDIT only on
     * $permittedKeys, over a WebspaceManagerInterface stub returning $allKeys.
     *
     * @param list<string> $permittedKeys
     * @param list<string> $allKeys
     */
    private function webspacePermissionResolver(array $permittedKeys = [], array $allKeys = []): WebspacePermissionResolver
    {
        $webspaces = [];
        foreach ($allKeys as $key) {
            $webspace = new Webspace();
            $webspace->setKey($key);
            $webspaces[$key] = $webspace;
        }

        $webspaceManager = $this->prophesize(WebspaceManagerInterface::class);
        $webspaceManager->getWebspaceCollection()->willReturn(new WebspaceCollection($webspaces));

        $securityChecker = $this->prophesize(SecurityCheckerInterface::class);
        $securityChecker->hasPermission(Argument::cetera())->will(
            static fn (array $args): bool => \in_array(\str_replace('sulu.webspaces.', '', $args[0]->getSecurityContext()), $permittedKeys, true),
        );

        $tokenStorage = new TokenStorage();
        $tokenStorage->setToken(new UsernamePasswordToken(new User(), 'main'));

        return new WebspacePermissionResolver($webspaceManager->reveal(), new ToolPermissionChecker($securityChecker->reveal(), $tokenStorage));
    }

    /**
     * A real TemplatesResource over a MetadataProviderInterface stub returning
     * nothing typed, so getTemplates() resolves to [].
     */
    private function emptyTemplatesResource(): TemplatesResource
    {
        $formMetadataProvider = $this->prophesize(MetadataProviderInterface::class);
        $formMetadataProvider->getMetadata(Argument::cetera())->willReturn(new FormMetadata());

        return new TemplatesResource($formMetadataProvider->reveal());
    }

    /**
     * A real BlocksResource over a MetadataProviderInterface stub returning
     * nothing typed, so getBlocks() resolves to [].
     */
    private function emptyBlocksResource(): BlocksResource
    {
        $formMetadataProvider = $this->prophesize(MetadataProviderInterface::class);
        $formMetadataProvider->getMetadata(Argument::cetera())->willReturn(new FormMetadata());

        return new BlocksResource($formMetadataProvider->reveal());
    }

    /**
     * A real ExtensionFieldsProvider over a MetadataProviderInterface stub
     * returning empty forms, so getExtensionFields() resolves to seo/excerpt: [].
     */
    private function emptyExtensionFieldsProvider(): ExtensionFieldsProvider
    {
        $formMetadataProvider = $this->prophesize(MetadataProviderInterface::class);
        $formMetadataProvider->getMetadata(Argument::cetera())->willReturn(new FormMetadata());

        return new ExtensionFieldsProvider($formMetadataProvider->reveal());
    }

    /**
     * A real WebspacesResource over a WebspaceManagerInterface stub returning
     * real Webspace objects (no portals, so the primary URL is always null).
     *
     * @param list<array{key: string, name: string, locales: list<string>}> $webspaces
     */
    private function webspacesResource(array $webspaces = []): WebspacesResource
    {
        $collection = [];
        foreach ($webspaces as $entry) {
            $webspace = new Webspace();
            $webspace->setKey($entry['key']);
            $webspace->setName($entry['name']);
            $webspace->setLocalizations(\array_map(static fn (string $locale) => new Localization($locale), $entry['locales']));
            $collection[$entry['key']] = $webspace;
        }

        $webspaceManager = $this->prophesize(WebspaceManagerInterface::class);
        $webspaceManager->getWebspaceCollection()->willReturn(new WebspaceCollection($collection));

        return new WebspacesResource($webspaceManager->reveal());
    }

    public function testGetContextAddsDedupedFieldTypeLegend(): void
    {
        $titleField = new FieldMetadata('title');
        $titleField->setType('text_line');

        $urlField = new FieldMetadata('url');
        $urlField->setType('route');

        $textBlockForm = new FormMetadata();
        $textBlockForm->setKey('text');
        $contentField = new FieldMetadata('content');
        $contentField->setType('text_editor');
        $textBlockForm->addItem($contentField);

        $blocksField = new FieldMetadata('blocks');
        $blocksField->setType('block');
        $blocksField->addType($textBlockForm);

        $defaultForm = new FormMetadata();
        $defaultForm->setKey('default');
        $defaultForm->addItem($titleField);
        $defaultForm->addItem($urlField);
        $defaultForm->addItem($blocksField);

        $pageMetadata = new TypedFormMetadata();
        $pageMetadata->addForm('default', $defaultForm);

        $formMetadataProvider = $this->prophesize(MetadataProviderInterface::class);
        $formMetadataProvider->getMetadata('page', Argument::cetera())->willReturn($pageMetadata);
        $formMetadataProvider->getMetadata(Argument::cetera())->willReturn(new FormMetadata());

        $templates = new TemplatesResource($formMetadataProvider->reveal());

        $tool = new GetContextTool($templates, $this->emptyBlocksResource(), $this->webspacesResource(), new FieldValueExampleProvider(), $this->emptyExtensionFieldsProvider(), $this->toolVisibilityResolver(), $this->webspacePermissionResolver());

        $result = $tool->getContext();

        $this->assertArrayHasKey('fieldTypes', $result);
        $this->assertArrayHasKey('text_line', $result['fieldTypes']);
        $this->assertArrayHasKey('text_editor', $result['fieldTypes']);
        $this->assertSame('Example text', $result['fieldTypes']['text_line']['example']);
        $this->assertStringContainsString('<sulu-link', (string) $result['fieldTypes']['text_editor']['example']);
        $this->assertArrayHasKey('hint', $result['fieldTypes']['text_editor']);

        // Types without example data are omitted (route, block, …)
        $this->assertArrayNotHasKey('route', $result['fieldTypes']);
        $this->assertArrayNotHasKey('block', $result['fieldTypes']);

        // Fields no longer carry inline examples (deduped into the legend)
        $titleFieldResult = $result['templates']['page']['default']['schema']['properties']['title'];
        $this->assertArrayNotHasKey('valueExample', $titleFieldResult);
        $this->assertArrayNotHasKey('valueHint', $titleFieldResult);

        $this->assertArrayHasKey('seoFields', $result);
        $this->assertArrayHasKey('excerptFields', $result);
    }

    public function testGetContextOmitsLegendWhenNoKnownTypesPresent(): void
    {
        $imageField = new FieldMetadata('image');
        $imageField->setType('media_selection');

        $defaultForm = new FormMetadata();
        $defaultForm->setKey('default');
        $defaultForm->addItem($imageField);

        $pageMetadata = new TypedFormMetadata();
        $pageMetadata->addForm('default', $defaultForm);

        $formMetadataProvider = $this->prophesize(MetadataProviderInterface::class);
        $formMetadataProvider->getMetadata('page', Argument::cetera())->willReturn($pageMetadata);
        $formMetadataProvider->getMetadata(Argument::cetera())->willReturn(new FormMetadata());

        $templates = new TemplatesResource($formMetadataProvider->reveal());

        $tool = new GetContextTool($templates, $this->emptyBlocksResource(), $this->webspacesResource(), new FieldValueExampleProvider(), $this->emptyExtensionFieldsProvider(), $this->toolVisibilityResolver(), $this->webspacePermissionResolver());

        $result = $tool->getContext();

        $this->assertSame([], $result['fieldTypes']);
        $this->assertArrayHasKey('seoFields', $result);
        $this->assertArrayHasKey('excerptFields', $result);
    }

    public function testGetContextIncludesToolCatalogue(): void
    {
        $tool = new GetContextTool($this->emptyTemplatesResource(), $this->emptyBlocksResource(), $this->webspacesResource(), new FieldValueExampleProvider(), $this->emptyExtensionFieldsProvider(), $this->toolVisibilityResolver(), $this->webspacePermissionResolver());

        $result = $tool->getContext();

        $this->assertArrayHasKey('tools', $result);
        $this->assertNotEmpty($result['tools']);
        foreach ($result['tools'] as $row) {
            $this->assertArrayHasKey('name', $row);
            $this->assertArrayHasKey('available', $row);
        }

        $byName = \array_column($result['tools'], null, 'name');
        $this->assertFalse($byName['sulu_tag_create']['available']);
        $this->assertNotNull($byName['sulu_tag_create']['reason']);
        $this->assertTrue($byName['sulu_get_context']['available']);
    }

    /**
     * A null-locale permission check ignores locale-restricted roles
     * (AccessControlManager::getRolesForLocale), so the catalogue must be
     * evaluated for the caller's actual locale or it advertises tools that get denied.
     */
    public function testGetContextEvaluatesAvailabilityForTheRequestedLocale(): void
    {
        $seenLocales = [];
        $checker = $this->prophesize(ToolPermissionCheckerInterface::class);
        $checker->has(Argument::cetera())->will(function(array $args) use (&$seenLocales): bool {
            $seenLocales[] = $args[2] ?? null;

            return false;
        });

        $visibilityResolver = new ToolVisibilityResolver(
            [
                'sulu_tag_create' => [
                    'name' => 'sulu_tag_create',
                    'requirements' => [['context' => 'sulu.settings.tags', 'permission' => PermissionTypes::ADD]],
                    'contextArgument' => null, 'contextResolver' => null,
                    'objectResolved' => false, 'discoveryContexts' => [],
                ],
            ],
            $checker->reveal(),
            $this->webspacePermissionResolver(),
            new ArticleSecurityContextResolver(TestGroupProvider::singleGroup()),
            [],
            ['sulu_ping', 'sulu_get_context'],
        );

        $tool = new GetContextTool($this->emptyTemplatesResource(), $this->emptyBlocksResource(), $this->webspacesResource(), new FieldValueExampleProvider(), $this->emptyExtensionFieldsProvider(), $visibilityResolver, $this->webspacePermissionResolver());

        $tool->getContext('de');

        $this->assertNotEmpty($seenLocales, 'The catalogue must consult the permission checker.');
        $this->assertSame(['de'], \array_values(\array_unique($seenLocales)));
    }

    public function testGetContextFiltersWebspacesToPermittedOnly(): void
    {
        $webspaces = $this->webspacesResource([
            ['key' => 'example', 'name' => 'Example', 'locales' => ['en']],
            ['key' => 'blog', 'name' => 'Blog', 'locales' => ['en']],
        ]);

        $resolver = $this->webspacePermissionResolver(['example'], ['example', 'blog']);

        $tool = new GetContextTool($this->emptyTemplatesResource(), $this->emptyBlocksResource(), $webspaces, new FieldValueExampleProvider(), $this->emptyExtensionFieldsProvider(), $this->toolVisibilityResolver(), $resolver);

        $result = $tool->getContext();

        $this->assertCount(1, $result['webspaces']);
        $this->assertSame('example', $result['webspaces'][0]['key']);
    }
}
