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

use CmsIg\Seal\Adapter\SearcherInterface;
use CmsIg\Seal\EngineInterface;
use CmsIg\Seal\Schema\Field\IdentifierField;
use CmsIg\Seal\Schema\Index;
use CmsIg\Seal\Schema\Schema;
use CmsIg\Seal\Search\Result;
use CmsIg\Seal\Search\SearchBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Sulu\Component\Security\Authentication\UserInterface;
use Sulu\Component\Security\Authorization\SecurityCheckerInterface;
use Sulu\Component\Webspace\Manager\WebspaceCollection;
use Sulu\Component\Webspace\Manager\WebspaceManagerInterface;
use Sulu\Component\Webspace\Webspace;
use Sulu\Mcp\Application\Security\ToolPermissionChecker;
use Sulu\Mcp\Application\Security\WebspacePermissionResolver;
use Sulu\Mcp\UserInterface\Mcp\Tool\ContentSearchTool;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

/**
 * Asserts ContentSearchTool's real return value against its declared
 * outputSchema — including the "items" vs "results" key inconsistency between
 * its early-return and success paths.
 */
#[CoversClass(ContentSearchTool::class)]
final class ContentSearchToolOutputSchemaTest extends TestCase
{
    use OutputSchemaAssertionTrait;
    use ProphecyTrait;

    /**
     * @param list<string> $grantedWebspaceKeys
     */
    private function webspaceResolver(array $grantedWebspaceKeys): WebspacePermissionResolver
    {
        $webspaces = [];
        foreach ($grantedWebspaceKeys as $key) {
            $webspace = new Webspace();
            $webspace->setKey($key);
            $webspaces[$key] = $webspace;
        }

        $webspaceManager = $this->prophesize(WebspaceManagerInterface::class);
        $webspaceManager->getWebspaceCollection()->willReturn(new WebspaceCollection($webspaces));

        $securityChecker = $this->prophesize(SecurityCheckerInterface::class);
        $securityChecker->hasPermission(Argument::cetera())->willReturn(true);

        $tokenStorage = $this->prophesize(TokenStorageInterface::class);
        $token = $this->prophesize(TokenInterface::class);
        $token->getUser()->willReturn($this->prophesize(UserInterface::class)->reveal());
        $tokenStorage->getToken()->willReturn($token->reveal());

        return new WebspacePermissionResolver($webspaceManager->reveal(), new ToolPermissionChecker($securityChecker->reveal(), $tokenStorage->reveal()));
    }

    private function searchBuilder(SearcherInterface $searcher): SearchBuilder
    {
        $index = new Index('website', ['id' => new IdentifierField('id')]);

        return (new SearchBuilder(new Schema(['website' => $index]), $searcher))->index('website');
    }

    public function testSuccessResultMatchesOutputSchema(): void
    {
        $searcher = $this->prophesize(SearcherInterface::class);
        $searcher->search(Argument::any())->willReturn(new Result((static function (): \Generator {
            yield [
                'resourceKey' => 'pages',
                'resourceId' => 'uuid-1',
                'locale' => 'en',
                'title' => 'Home',
                'url' => '/',
                'webspaces' => ['example'],
                'authoredAt' => '2026-01-01T00:00:00+00:00',
                'metadata' => ['excerpt' => 'Welcome'],
            ];
        })(), 1));

        $engine = $this->prophesize(EngineInterface::class);
        $engine->createSearchBuilder('website')->willReturn($this->searchBuilder($searcher->reveal()));

        $tool = new ContentSearchTool($engine->reveal(), $this->webspaceResolver(['example']));

        $result = $tool->search('hello', 'en');

        $this->assertResultMatchesOutputSchema(ContentSearchTool::class, 'search', $result);
        self::assertArrayHasKey('results', $result);
    }

    public function testNoPermittedWebspaceResultMatchesOutputSchema(): void
    {
        $engine = $this->prophesize(EngineInterface::class);

        $tool = new ContentSearchTool($engine->reveal(), $this->webspaceResolver([]));

        $result = $tool->search('hello', 'en');

        $this->assertResultMatchesOutputSchema(ContentSearchTool::class, 'search', $result);
        self::assertArrayHasKey('items', $result);
    }

    public function testErrorResultMatchesOutputSchema(): void
    {
        $engine = $this->prophesize(EngineInterface::class);
        $engine->createSearchBuilder(Argument::any())->willThrow(new \RuntimeException('Search engine unavailable'));

        $tool = new ContentSearchTool($engine->reveal(), $this->webspaceResolver(['example']));

        $result = $tool->search('hello', 'en');

        $this->assertResultMatchesOutputSchema(ContentSearchTool::class, 'search', $result);
    }
}
