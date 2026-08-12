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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Sulu\Bundle\TagBundle\Entity\Tag;
use Sulu\Bundle\TagBundle\Tag\TagRepositoryInterface;
use Sulu\Mcp\UserInterface\Mcp\Tool\Taxonomy\TagListTool;

#[CoversClass(TagListTool::class)]
final class TagListToolTest extends TestCase
{
    use ProphecyTrait;

    /**
     * @var ObjectProphecy<TagRepositoryInterface>
     */
    private ObjectProphecy $tagRepository;

    private TagListTool $tool;

    protected function setUp(): void
    {
        $this->tagRepository = $this->prophesize(TagRepositoryInterface::class);
        $this->tool = new TagListTool($this->tagRepository->reveal());
    }

    /**
     * @return list<Tag>
     */
    private function tags(int $count): array
    {
        $tags = [];
        for ($i = 1; $i <= $count; ++$i) {
            $tag = new Tag();
            $tag->setId($i);
            $tag->setName("tag-{$i}");
            $tags[] = $tag;
        }

        return $tags;
    }

    public function testListTagsReturnsPaginatedTagsAndTotal(): void
    {
        $this->tagRepository->findAll()->willReturn($this->tags(25));

        $result = $this->tool->listTags();

        $this->assertArrayHasKey('tags', $result);
        $this->assertCount(20, $result['tags'], 'default limit is 20');
        $this->assertSame(25, $result['total']);
        $this->assertSame(1, $result['page']);
        $this->assertSame(20, $result['limit']);
        $this->assertSame(['id' => 1, 'name' => 'tag-1'], $result['tags'][0]);
    }

    public function testListTagsSecondPageReturnsCorrectSlice(): void
    {
        $this->tagRepository->findAll()->willReturn($this->tags(25));

        $result = $this->tool->listTags(2, 20);

        $this->assertCount(5, $result['tags'], 'page 2 with limit 20 returns remaining 5 tags');
        $this->assertSame(25, $result['total']);
        $this->assertSame(2, $result['page']);
        $this->assertSame(['id' => 21, 'name' => 'tag-21'], $result['tags'][0]);
    }

    public function testListTagsCustomLimit(): void
    {
        $this->tagRepository->findAll()->willReturn($this->tags(10));

        $result = $this->tool->listTags(1, 3);

        $this->assertCount(3, $result['tags']);
        $this->assertSame(10, $result['total']);
        $this->assertSame(1, $result['page']);
        $this->assertSame(3, $result['limit']);
    }

    public function testListTagsReturnsEmptyResultWhenNoTags(): void
    {
        $this->tagRepository->findAll()->willReturn([]);

        $result = $this->tool->listTags();

        $this->assertSame([], $result['tags']);
        $this->assertSame(0, $result['total']);
    }

    public function testListTagsReturnsErrorOnFailure(): void
    {
        $this->tagRepository->findAll()->willThrow(new \RuntimeException('DB error'));

        $result = $this->tool->listTags();

        $this->assertArrayHasKey('error', $result);
        $this->assertArrayHasKey('hint', $result);
        $this->assertIsString($result['hint']);
        $this->assertNotEmpty($result['hint']);
    }

    public function testListTagsMethodHasMcpToolAttribute(): void
    {
        $reflection = new \ReflectionMethod(TagListTool::class, 'listTags');
        $attributes = $reflection->getAttributes(McpTool::class);

        $this->assertCount(1, $attributes, 'listTags() must have exactly one #[McpTool] attribute');

        $instance = $attributes[0]->newInstance();
        $this->assertSame('sulu_tag_list', $instance->name);
    }
}
