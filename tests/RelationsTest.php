<?php

namespace Tests;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Orchestra\Testbench\TestCase;
use Sushi\SushiTrain;

class RelationsTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        config(['sushi.cache-path' => $cachePath = __DIR__ . '/cache']);
        if (! file_exists($cachePath)) {
            mkdir($cachePath, 0777, true);
        }
    }

    /** @test */
    function can_retrieve_with_a_has_one_related_model() {
        $parent = ParentModel::with('child')->find(1);
        $this->assertNotEmpty($parent->child);
        $this->assertEquals(1, $parent->child->id);
        $this->assertEquals('child:1', $parent->child->ref);
    }

    /** @test */
    function can_retrieve_with_has_many_related_models() {
        $child = ChildModel::with('children')->find(1);
        $this->assertNotEmpty($child->children);
        $this->assertEquals(2, $child->children->count());
        $child1 = $child->children->get(0);
        $child2 = $child->children->get(1);
        if ($child1->ref == 'grandchild:1') {
            $this->assertEquals('grandchild:2', $child2->ref);
        } elseif ($child1->ref == 'grandchild:2') {
            $this->assertEquals('grandchild:1', $child2->ref);
        } else {
            $this->fail("Unexpected grandchild ref, expected grandchild:1 or grandchild:2, got {$child1->ref}");
        }
    }

    /** @test */
    function can_retrieve_with_a_belongs_to_related_model() {
        $child = ChildModel::with('parent')->find(1);
        $this->assertNotEmpty($child->parent);
        $this->assertEquals(1, $child->parent->id);
        $this->assertEquals('parent:1', $child->parent->ref);
    }

    /** @test */
    function can_retrieve_with_has_many_through_related_models() {
        $parent = ParentModel::with('grandchildren')->find(1);
        $this->assertNotEmpty($parent->grandchildren);
        $this->assertEquals(2, $parent->grandchildren->count());
        $child1 = $parent->grandchildren->get(0);
        $child2 = $parent->grandchildren->get(1);
        if ($child1->ref == 'grandchild:1') {
            $this->assertEquals('grandchild:2', $child2->ref);
        } elseif ($child1->ref == 'grandchild:2') {
            $this->assertEquals('grandchild:1', $child2->ref);
        } else {
            $this->fail("Unexpected grandchild ref, expected grandchild:1 or grandchild:2, got {$child1->ref}");
        }
    }

    /** @test */
    function can_retrieve_with_belongs_to_many_related_models() {
        DistantRelationModelParentModel::bootSushi();
        $parent = ParentModel::with('distantRelations')->find(1);
        $this->assertNotEmpty($parent->distantRelations);
        $this->assertEquals(2, $parent->distantRelations->count());
        $distant1 = $parent->distantRelations->get(0);
        $distant2 = $parent->distantRelations->get(1);
        if ($distant1->ref == 'distant:1') {
            $this->assertEquals('distant:2', $distant2->ref);
        } elseif ($distant1->ref == 'distant:2') {
            $this->assertEquals('distant:1', $distant2->ref);
        } else {
            $this->fail("Unexpected grandchild ref, expected grandchild:1 or grandchild:2, got {$distant1->ref}");
        }
    }
}

class ParentModel extends Model {
    use SushiTrain;

    protected $rows = [
        ['id' => 1, 'ref' => 'parent:1'],
        ['id' => 2, 'ref' => 'parent:2'],
    ];

    public function child() {
        return $this->hasOne(ChildModel::class, 'parent_id');
    }

    public function grandchildren() {
        return $this->hasManyThrough(GrandChildModel::class, ChildModel::class, 'parent_id', 'parent_id');
    }

    public function distantRelations() {
        return $this->belongsToMany(DistantRelationModel::class, null, 'parent_id', 'distant_id')->using(DistantRelationModelParentModel::class);
    }
}

class ChildModel extends Model {
    use SushiTrain;

    protected $rows = [
        ['id' => 1, 'ref' => 'child:1', 'parent_id' => 1],
        ['id' => 2, 'ref' => 'child:2', 'parent_id' => 2],
    ];

    public function parent() {
        return $this->belongsTo(ParentModel::class, 'parent_id');
    }

    public function children() {
        return $this->hasMany(GrandChildModel::class, 'parent_id');
    }
}

class GrandChildModel extends Model {
    use SushiTrain;

    protected $rows = [
        ['id' => 1, 'ref' => 'grandchild:1', 'parent_id' => 1],
        ['id' => 2, 'ref' => 'grandchild:2', 'parent_id' => 1],
    ];

    public function parent() {
        return $this->belongsTo(ParentModel::class, 'parent_id');
    }
}

class DistantRelationModel extends Model {
    use SushiTrain;

    protected $rows = [
        ['id' => 1, 'ref' => 'distant:1'],
        ['id' => 2, 'ref' => 'distant:2'],
    ];

    public function parent() {
        return $this->belongsToMany(ParentModel::class);
    }
}

class DistantRelationModelParentModel extends Pivot {
    use SushiTrain;

    //protected $table = 'parent_model_distant_relation_model';

    protected $rows = [
        ['parent_id' => 1, 'distant_id' => 1],
        ['parent_id' => 1, 'distant_id' => 2],
        ['parent_id' => 2, 'distant_id' => 2],
    ];
}



