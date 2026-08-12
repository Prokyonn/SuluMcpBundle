# Tool output schemas

Read-oriented tools (`*_get`, `*_list`, `*_tree`, `sulu_content_search`, `sulu_get_context`) declare an `outputSchema` on their `#[McpTool]` attribute so MCP clients get validated, typed `structuredContent` back instead of having to re-parse JSON out of a text blob. This page documents the convention so it can be applied consistently to the tools that don't have one yet.

Create/update/delete/publish/unpublish tools intentionally do **not** declare an `outputSchema` — this convention covers read tools only.

## Where schemas are declared

Directly on the attribute, next to the tool method:

```php
#[McpTool(
    name: 'sulu_tag_list',
    description: '...',
    outputSchema: [
        'type' => 'object',
        'properties' => [
            'tags' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => ['type' => 'integer'],
                        'name' => ['type' => 'string'],
                    ],
                    'required' => ['id', 'name'],
                ],
            ],
            ...OutputSchema::PAGINATION_PROPERTIES,
            'error' => OutputSchema::ERROR_PROPERTY,
            'hint' => OutputSchema::HINT_PROPERTY,
        ],
    ],
)]
public function listTags(int $page = 1, int $limit = 20): array
```

The SDK (`Mcp\Capability\Discovery\SchemaGenerator::generateOutputSchema()`) only emits an `outputSchema` when one is explicitly provided — there is no auto-derivation from PHP return types, so every schema is hand-written. The top-level schema **must** be `type: object`; `Mcp\Schema\Tool`'s constructor throws otherwise.

## Derive the schema from the method body, not the description

Read the tool method's actual `return` statements — every one of them, including early returns — before writing the schema. Tool descriptions are written for the AI client and sometimes idealize the response; the code is the source of truth.

## Required vs. optional: check every return path

A property belongs in `required` only if **every** `return` statement in the method includes it — the empty-result path, the not-found/permission-denied path, and the exception-catch path, not just the happy path. Concretely:

- Get-by-id tools (`ArticleGetTool`, `PageGetTool`, `SnippetGetTool`, `MediaGetTool`) return a success shape (`uuid`/`data`/…) on one path and an `{error, hint}` shape on the not-found path. The two shapes share no keys, so nothing is `required` at the top level — declare both shapes' properties as optional siblings.
- List/tree tools usually share an envelope (`total`/`page`/`limit`, or a `tree`/`categories` array) across their success **and** empty-result paths, but drop it on an exception-catch path. Check the catch block specifically — some tools return `{error, hint}` there (e.g. `PageTreeTool`), others return `{error}` only, with no `hint` at all (e.g. `SnippetListTool`, `BlockListTool`'s several early returns). Don't declare a `hint` property a tool never actually returns.
- Only mark something required when it is genuinely on every path, single-return-statement tools (`MediaListTool`, `GetContextTool`) can safely require their whole envelope.

Getting this wrong in the "too strict" direction breaks strict clients on valid responses (a not-found result rejected because `uuid` was marked required); this repo's tests exist specifically to catch that (see below).

## Shared shapes: `Sulu\Mcp\UserInterface\Mcp\Tool\OutputSchema`

Common fragments — the `total`/`page`/`limit` pagination envelope, the `error` and `hint` string properties, and a named "permissive object" marker — live as plain constants on `OutputSchema` (`src/UserInterface/Mcp/Tool/OutputSchema.php`) so they aren't copy-pasted across every tool:

```php
OutputSchema::PAGINATION_PROPERTIES  // ['total' => ..., 'page' => ..., 'limit' => ...]
OutputSchema::ERROR_PROPERTY          // ['type' => 'string']
OutputSchema::HINT_PROPERTY           // ['type' => 'string']
OutputSchema::FREEFORM_OBJECT         // ['type' => 'object']
```

Spread pagination properties into a `properties` array with `...OutputSchema::PAGINATION_PROPERTIES` (attribute arguments are constant expressions, and array-spreading a class constant in one is allowed). This is deliberately just constants, not a schema-builder DSL — don't add builder methods here.

When a shape is reused only *within* one tool (e.g. `ContentSearchTool`'s `items` and `results` properties are the same per-hit object; `GetContextTool`'s `seoFields` and `excerptFields` are the same field-definition list), define a `private const` on that tool class instead of adding it to the shared helper — it doesn't need to be shared across files to avoid duplication.

## Keep genuinely free-form content permissive

Don't invent a fixed key list for a value you can't guarantee. Two different reasons this comes up, both handled by `OutputSchema::FREEFORM_OBJECT` (or an inline `['type' => 'object']`/`['type' => 'array', 'items' => ['type' => 'object']]`):

1. **Sulu content whose keys depend on the project's template configuration** — the normalized `data` bag on `*_get` tools, block content on `sulu_block_list`, the `templates`/`blocks`/`fieldTypes` legend on `sulu_get_context`. These vary per Sulu installation; a fixed schema would be a lie.
2. **Structures sourced from a dependency this bundle doesn't control** — `NavigationGetTool`'s `navigation` tree comes straight from `NavigationRepository::getNavigationTree()`, an external Sulu core API. Contrast this with `PageTreeTool`'s `tree` and `CategoryListTool`'s `categories`, which **are** modeled precisely (see below) because `buildTreeNode()`/`buildTree()` are this bundle's own code with a fixed, guaranteed shape.

`ContactListTool`'s `items` array is a third case worth calling out: the item shape depends on the `type` request parameter (`account` items carry `id`/`name`, `contact` items carry `id`/`firstName`/`lastName`). Rather than union both shapes, it's declared as an array of permissive objects.

## Recursive trees: `$defs` + `$ref`, only for shapes this bundle guarantees

`PageTreeTool` and `CategoryListTool` build a genuinely recursive, fixed-shape node (`buildTreeNode()` / `buildTree()` always emit the same keys at every depth) — that's modeled with a named `$defs` entry the node's `children` property `$ref`s back to itself:

```php
outputSchema: [
    '$id' => 'urn:sulu-mcp:page-tree-output',   // required for local $ref resolution
    'type' => 'object',
    'properties' => [
        'tree' => ['type' => 'array', 'items' => ['$ref' => '#/$defs/PageTreeNode']],
        // ...
    ],
    '$defs' => [
        'PageTreeNode' => [
            'type' => 'object',
            'properties' => [/* ... */ 'children' => ['type' => 'array', 'items' => ['$ref' => '#/$defs/PageTreeNode']]],
            'required' => [/* every key buildTreeNode() always sets */],
        ],
    ],
],
```

The root schema needs a `$id` for the local `#/$defs/...` pointer to resolve — without one, `opis/json-schema` (the validator both the SDK and this bundle's tests use) fails to resolve the `$ref` and every value is reported invalid. Only reach for this when the recursion is this bundle's own guaranteed output; don't use it to paper over genuinely free-form nesting.

## Nullability

Check the actual PHP return type of the underlying getter, not just its docblock — Sulu's own docblocks are occasionally wrong. `Sulu\Bundle\CategoryBundle\Api\Category::getName()` claims `@return string` but its body falls through to a bare `return;` (i.e. `null`) when no translation exists, so `CategoryListTool`'s `name` property is typed `['string', 'null']`, not `'string'`.

## Applying this to `ArticleListTool` and `PageListTool`

These two are excluded from this iteration (a parallel branch is adding sorting parameters to them). Both are single, well-behaved pagination envelopes with **no** disjoint error shape — every `return` statement in `listArticles()`/`listPages()` includes the full envelope:

- `PageListTool::listPages()` returns `{pages, total, page, limit}` on all three paths (not-permitted webspace, empty result, success), plus `hint` only on the not-permitted-webspace path. So `pages`/`total`/`page`/`limit` are `required`; `hint` is not.
- `ArticleListTool::listArticles()` returns `{articles, total, page, limit}` on all three of its paths (no permitted templates, empty result, success) and never returns `hint` or `error` at all — don't add those properties just because sibling tools have them.

Both tools' list items are `{uuid: string, data: <freeform object>}`, the same shape as `SnippetListTool`'s `snippets` items — `data` is a `SUMMARY_FIELDS` subset of normalized content and should stay `OutputSchema::FREEFORM_OBJECT` rather than enumerating `SUMMARY_FIELDS` as fixed properties, since `array_key_exists` means any subset can be missing.

## Testing

Every tool with an `outputSchema` has a companion `<Tool>OutputSchemaTest.php` that drives the tool with real/Prophecy collaborators and asserts each realistic return value (success, empty, not-found, permission-denied-hint, exception) against the declared schema, via the shared `Sulu\Mcp\Tests\Unit\UserInterface\Mcp\Tool\OutputSchemaAssertionTrait`. That trait validates with `Mcp\Capability\Discovery\SchemaValidator` — the JSON Schema validator `mcp/sdk` already ships (backed by `opis/json-schema`, already vendored) to validate tool *input* — so no new validation dependency was needed. Add the same kind of test file for `ArticleListTool`/`PageListTool` once their schemas land.
