---
name: laravel-adjacency-list
description: Use staudenmeir/laravel-adjacency-list to build recursive tree and graph relationships in Laravel Eloquent using common table expressions (CTE) - ancestors, descendants, trees, nested results, depth, paths, cycle detection, and custom relationships.
compatible_agents:
  - Claude Code
  - Cursor
tags:
  - laravel
  - eloquent
  - trees
  - graphs
  - hierarchical-data
---

# Laravel Adjacency List

## When to use this skill

Use this skill when working with `staudenmeir/laravel-adjacency-list` in a Laravel 11+ application:

- Building hierarchical/tree data structures (categories, menus, org charts, nested comments)
- Traversing ancestors, descendants, siblings, or bloodlines recursively
- Working with directed graphs with multiple parents per node (BOM, family trees)
- Querying tree depth, paths, or ordering (breadth-first, depth-first)
- Generating nested tree structures from flat query results
- Combining recursive relationships with other Eloquent relationships
- Filtering models by position in a tree (root, leaf, has children, has parent)

## Installation

```bash
composer require staudenmeir/laravel-adjacency-list:"^1.0"
```

## Trees: One Parent per Node (One-to-Many)

### Getting Started

The table needs a self-referencing parent key column:

```php
Schema::create('categories', function (Blueprint $table) {
    $table->id();
    $table->string('name');
    $table->unsignedBigInteger('parent_id')->nullable();
    $table->timestamps();

    $table->foreign('parent_id')->references('id')->on('categories');
});
```

Add the `HasRecursiveRelationships` trait to your model:

```php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Staudenmeir\LaravelAdjacencyList\Eloquent\HasRecursiveRelationships;

class Category extends Model
{
    use HasRecursiveRelationships;
}
```

By default, the trait expects `parent_id` as the parent key and the model's primary key as the local key. Override to customize:

```php
class Category extends Model
{
    use HasRecursiveRelationships;

    public function getParentKeyName()
    {
        return 'parent_id'; // default
    }

    public function getLocalKeyName()
    {
        return 'id'; // default
    }
}
```

### Included Relationships

The trait provides these relationships:

| Relationship | Description |
|---|---|
| `ancestors()` | Recursive parents |
| `ancestorsAndSelf()` | Recursive parents and itself |
| `bloodline()` | Ancestors, descendants, and itself |
| `children()` | Direct children only |
| `childrenAndSelf()` | Direct children and itself |
| `descendants()` | Recursive children |
| `descendantsAndSelf()` | Recursive children and itself |
| `parent()` | Direct parent only |
| `parentAndSelf()` | Direct parent and itself |
| `rootAncestor()` | Topmost parent |
| `rootAncestorOrSelf()` | Topmost parent or itself if root |
| `siblings()` | Parent's other children |
| `siblingsAndSelf()` | All parent's children |

```php
// Access as properties (lazy load)
$ancestors = Category::find($id)->ancestors;

// Eager load
$categories = Category::with('descendants')->get();

// Query scopes
$categories = Category::whereHas('siblings', function ($query) {
    $query->where('name', 'Electronics');
})->get();

// Aggregates
$total = Category::find($id)->descendants()->count();

// Bulk operations
Category::find($id)->descendants()->update(['active' => false]);
```

### Tree Queries

Use `tree()` to get all models starting from root nodes:

```php
$tree = Category::tree()->get();
```

Use `treeOf()` for custom root constraints:

```php
$tree = Category::treeOf(function ($query) {
    $query->whereNull('parent_id')->where('store_id', 1);
})->get();
```

Pass a maximum depth to limit traversal:

```php
$tree = Category::tree(3)->get();

$tree = Category::treeOf($constraint, 3)->get();
```

### Filters

Filter models by their position in the tree:

```php
$roots = Category::isRoot()->get();              // no parent
$leaves = Category::isLeaf()->get();             // no children
$withChildren = Category::hasChildren()->get();  // has children
$withParent = Category::hasParent()->get();       // has parent
```

### Order

Order results breadth-first or depth-first:

```php
$tree = Category::tree()->breadthFirst()->get();  // siblings before children

$descendants = Category::find($id)->descendants()->depthFirst()->get();  // children before siblings
```

### Depth

Ancestor, bloodline, descendant, and tree queries include a `depth` column. Depth is positive for descendants, negative for ancestors, relative to the query's starting point:

```php
$descendantsAndSelf = Category::find($id)->descendantsAndSelf()->depthFirst()->get();

echo $descendantsAndSelf[0]->depth; // 0
echo $descendantsAndSelf[1]->depth; // 1
echo $descendantsAndSelf[2]->depth; // 2
```

Filter by depth with `whereDepth()`:

```php
$descendants = Category::find($id)->descendants()->whereDepth(2)->get();

$descendants = Category::find($id)->descendants()->whereDepth('<', 3)->get();
```

Use `withMaxDepth()` for better query performance — it limits the CTE recursion instead of filtering after the fact:

```php
$descendants = Category::withMaxDepth(3, function () use ($id) {
    return Category::find($id)->descendants;
});

// Negative depths for ancestors
$ancestors = Category::withMaxDepth(-3, function () use ($id) {
    return Category::find($id)->ancestors;
});
```

Override `getDepthName()` if your table already has a `depth` column:

```php
public function getDepthName()
{
    return 'tree_depth';
}
```

### Path

Query results include a `path` column with dot-separated local keys:

```php
$descendantsAndSelf = Category::find(1)->descendantsAndSelf()->depthFirst()->get();

echo $descendantsAndSelf[0]->path; // 1
echo $descendantsAndSelf[1]->path; // 1.2
echo $descendantsAndSelf[2]->path; // 1.2.3
```

Customize the path column name and separator:

```php
class Category extends Model
{
    public function getPathName()
    {
        return 'path'; // default
    }

    public function getPathSeparator()
    {
        return '.'; // default
    }
}
```

### Custom Paths

Add custom path columns built from any model attribute:

```php
class Category extends Model
{
    public function getCustomPaths()
    {
        return [
            [
                'name' => 'slug_path',
                'column' => 'slug',
                'separator' => '/',
            ],
        ];
    }
}

$descendantsAndSelf = Category::find(1)->descendantsAndSelf;

echo $descendantsAndSelf[0]->slug_path; // electronics
echo $descendantsAndSelf[1]->slug_path; // electronics/phones
echo $descendantsAndSelf[2]->slug_path; // electronics/phones/smartphones
```

Reverse custom paths with `'reverse' => true`.

### Nested Results

Convert a flat collection into a nested tree with `toTree()`:

```php
$categories = Category::tree()->get();

$tree = $categories->toTree();
```

This recursively sets `children` relationships. The result is a nested structure suitable for JSON responses or recursive rendering.

Use `loadTreeRelationships()` to hydrate `ancestors` and `parent` relationships from already-loaded tree data, reducing N+1 queries:

```php
$categories = Category::tree()->get();

$categories->loadTreeRelationships();

$tree = $categories->toTree();
```

### Initial & Recursive Query Constraints

Add constraints to the CTE's initial and/or recursive query. Useful for skipping subtrees:

```php
// Constraint applied to both initial and recursive queries
$tree = Category::withQueryConstraint(function (Builder $query) {
    $query->where('categories.active', true);
}, function () {
    return Category::tree()->get();
});

// Only the initial query
Category::withInitialQueryConstraint($callback, $query);

// Only the recursive query
Category::withRecursiveQueryConstraint($callback, $query);
```

### Cycle Detection

Enable cycle detection if your tree data may contain cycles:

```php
class Category extends Model
{
    use HasRecursiveRelationships;

    public function enableCycleDetection(): bool
    {
        return true;
    }

    // Optionally include the cycle start node with an is_cycle flag
    public function includeCycleStart(): bool
    {
        return true;
    }
}
```

### Relationship Checks

```php
$child->isChildOf($parent);           // true/false
$parent->isParentOf($child);          // true/false
$node->getDepthRelatedTo($ancestor);  // int (e.g., 2)
```

### Custom Relationships (Of Descendants)

Retrieve related models across the entire subtree:

```php
class Category extends Model
{
    use HasRecursiveRelationships;

    // All products of this category AND its descendants
    public function recursiveProducts()
    {
        return $this->hasManyOfDescendantsAndSelf(Product::class);
    }

    // Only descendants' products (not this category's own)
    public function descendantProducts()
    {
        return $this->hasManyOfDescendants(Product::class);
    }
}

$products = Category::find($id)->recursiveProducts;
$count = Category::withCount('recursiveProducts')->get();
```

Available "of descendants" relationship types:

| Method | Base Relationship |
|---|---|
| `hasManyOfDescendants()` / `hasManyOfDescendantsAndSelf()` | HasMany |
| `belongsToManyOfDescendants()` / `belongsToManyOfDescendantsAndSelf()` | BelongsToMany |
| `morphToManyOfDescendants()` / `morphToManyOfDescendantsAndSelf()` | MorphToMany |
| `morphedByManyOfDescendants()` / `morphedByManyOfDescendantsAndSelf()` | MorphedByMany |

#### Intermediate Scopes

Adjust the descendants query in custom relationships:

```php
Category::find($id)->recursiveProducts()->withTrashedDescendants()->get();

Category::find($id)->recursiveProducts()->withIntermediateScope('active', new ActiveScope())->get();

Category::find($id)->recursiveProducts()->withIntermediateScope(
    'depth',
    function ($query) {
        $query->whereDepth('<=', 10);
    }
)->get();
```

### Deep Relationship Concatenation

Combine recursive relationships with [staudenmeir/eloquent-has-many-deep](https://github.com/staudenmeir/eloquent-has-many-deep) for deep traversals:

```bash
composer require staudenmeir/eloquent-has-many-deep
```

```php
class Category extends Model
{
    use \Staudenmeir\EloquentHasManyDeep\HasRelationships;
    use \Staudenmeir\LaravelAdjacencyList\Eloquent\HasRecursiveRelationships;

    public function descendantProducts(): \Staudenmeir\EloquentHasManyDeep\HasManyDeep
    {
        return $this->hasManyDeepFromRelations(
            $this->descendants(),
            (new static)->products()
        );
    }

    public function products()
    {
        return $this->hasMany(Product::class);
    }
}
```

Recursive relationships must be at the beginning of deep relationships.

## Graphs: Multiple Parents per Node (Many-to-Many)

### Getting Started

Store directed graphs as nodes and edges in a pivot table:

```php
Schema::create('nodes', function (Blueprint $table) {
    $table->id();
    $table->string('name');
    $table->timestamps();
});

Schema::create('edges', function (Blueprint $table) {
    $table->unsignedBigInteger('source_id');
    $table->unsignedBigInteger('target_id');
    $table->string('label')->nullable();
    $table->unsignedBigInteger('weight')->nullable();
});
```

Add the `HasGraphRelationships` trait and specify the pivot table:

```php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Staudenmeir\LaravelAdjacencyList\Eloquent\HasGraphRelationships;

class Node extends Model
{
    use HasGraphRelationships;

    public function getPivotTableName(): string
    {
        return 'edges';
    }
}
```

Customize the pivot table's parent/child key names (defaults are `parent_id` and `child_id`):

```php
class Node extends Model
{
    use HasGraphRelationships;

    public function getPivotTableName(): string
    {
        return 'edges';
    }

    public function getParentKeyName(): string
    {
        return 'source_id';
    }

    public function getChildKeyName(): string
    {
        return 'target_id';
    }
}
```

### Graph Relationships

| Relationship | Description |
|---|---|
| `ancestors()` | Recursive parents |
| `ancestorsAndSelf()` | Recursive parents and itself |
| `children()` | Direct children |
| `childrenAndSelf()` | Direct children and itself |
| `descendants()` | Recursive children |
| `descendantsAndSelf()` | Recursive children and itself |
| `parents()` | Direct parents (multiple) |
| `parentsAndSelf()` | Direct parents and itself |

```php
$ancestors = Node::find($id)->ancestors;

$nodes = Node::with('descendants')->get();

$total = Node::find($id)->descendants()->count();
```

### Pivot Columns

Access additional columns from the edge/pivot table:

```php
class Node extends Model
{
    use HasGraphRelationships;

    public function getPivotTableName(): string
    {
        return 'edges';
    }

    public function getPivotColumns(): array
    {
        return ['label', 'weight'];
    }
}

foreach (Node::find($id)->descendants as $node) {
    echo $node->pivot->label;
    echo $node->pivot->weight;
}
```

### Subgraphs

Query subgraphs from custom starting points:

```php
$subgraph = Node::subgraph(function ($query) {
    $query->whereIn('id', [1, 5, 10]);
})->get();

// With maximum depth
$subgraph = Node::subgraph($constraint, 3)->get();
```

### Graph Features

Graphs support the same depth, path, custom paths, ordering, nested results, cycle detection, query constraints, and deep relationship concatenation features as trees. See the tree sections above — the API is identical, just use `HasGraphRelationships` instead of `HasRecursiveRelationships`.

## Common Pitfalls

- **Using `whereDepth()` instead of `withMaxDepth()` for performance** — `whereDepth()` builds the entire tree and filters afterward; `withMaxDepth()` limits CTE recursion and is significantly faster for large trees.
- **Forgetting cycle detection** — if your data can have cycles, enable `enableCycleDetection()` to prevent infinite recursion.
- **Not using `loadTreeRelationships()`** — when you already have tree data loaded, call this before accessing `ancestors` or `parent` to avoid N+1 queries.
- **MariaDB subquery limitation** — MariaDB doesn't support correlated CTEs in subqueries, so `whereHas('descendants')` and `withCount('descendants')` won't work on MariaDB.
- **Using `HasRecursiveRelationships` for many-to-many** — use `HasGraphRelationships` when a node can have multiple parents.
- **Missing `QueriesExpressions` on related models** — when using custom "of descendants" relationships outside Laravel or with package discovery disabled, the related model needs `use \Staudenmeir\LaravelCte\Eloquent\QueriesExpressions`.
- **Recursive relationships at the end of deep relationships** — with `eloquent-has-many-deep`, recursive relationships must be at the *beginning* of the concatenation chain.

## References

- [GitHub Repository](https://github.com/staudenmeir/laravel-adjacency-list)
- [Packagist](https://packagist.org/packages/staudenmeir/laravel-adjacency-list)
- [eloquent-has-many-deep (for deep concatenation)](https://github.com/staudenmeir/eloquent-has-many-deep)
