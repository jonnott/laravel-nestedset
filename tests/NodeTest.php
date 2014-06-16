<?php

use Illuminate\Database\Capsule\Manager as Capsule;
use Kalnoy\Nestedset\NestedSet;

class NodeTest extends PHPUnit_Framework_TestCase {
    public static function setUpBeforeClass()
    {
        $schema = Capsule::schema();

        $schema->dropIfExists('categories');
        $schema->create('categories', function ($table) {
            $table->increments('id');
            $table->string('name');
            NestedSet::columns($table);
        });

        Capsule::enableQueryLog();
    }

    public function setUp()
    {
        $data = include __DIR__.'/data/categories.php';

        Capsule::table('categories')->insert($data);
    }

    public function tearDown()
    {
        Capsule::table('categories')->truncate();
    }

    // public static function tearDownAfterClass()
    // {
    //     $log = Capsule::getQueryLog();
    //     foreach ($log as $item) {
    //         echo $item['query']." with ".implode(', ', $item['bindings'])."\n";
    //     }
    // }

    public function assertTreeNotBroken($table = 'categories')
    {
        $checks = array();

        // Check if lft and rgt values are ok
        $checks[] = "from $table where _lft >= _rgt or (_rgt - _lft) % 2 = 0";

        // Check if lft and rgt values are unique
        $checks[] = "from $table c1, $table c2 where c1.id <> c2.id and ".
            "(c1._lft=c2._lft or c1._rgt=c2._rgt or c1._lft=c2._rgt or c1._rgt=c2._lft)";

        // Check if parent_id is set correctly
        $checks[] = "from $table c, $table p, $table m where c.parent_id=p.id and m.id <> p.id and m.id <> c.id and ".
             "(c._lft not between p._lft and p._rgt or c._lft between m._lft and m._rgt and m._lft between p._lft and p._rgt)";

        foreach ($checks as $i => $check) {
            $checks[$i] = 'select 1 as error '.$check;
        }

        $sql = 'select max(error) as errors from ('.implode(' union ', $checks).') _';

        $actual = Capsule::connection()->selectOne($sql);

        $this->assertEquals(array('errors' => null), $actual, "The tree structure of $table is broken!");
    }

    public function dumpTree($items)
    {
        foreach ($items as $item)
        {
            echo $item->name." ".$item->getLft()." ".$item->getRgt().PHP_EOL;
        }
    }

    public function assertNodeRecievesValidValues($node)
    {
        $lft = $node->getLft();
        $rgt = $node->getRgt();
        $nodeInDb = $this->findCategory($node->name);

        $this->assertEquals(
            [ $nodeInDb->getLft(), $nodeInDb->getRgt() ], 
            [ $lft, $rgt ], 
            'Node is not synced with database after save.'
        );
    }

    public function findCategory($name)
    {
        return Category::whereName($name)->first();
    }

    public function testTreeNotBroken()
    {
        $this->assertTreeNotBroken();
    }

    public function nodeValues($node)
    {
        return array($node->_lft, $node->_rgt, $node->parent_id);
    }

    public function testGetsNodeData()
    {
        $data = Category::getNodeData(3);

        $this->assertEquals([ '_lft' => 3, '_rgt' => 4 ], $data);
    }

    public function testGetsPlainNodeData()
    {
        $data = Category::getPlainNodeData(3);

        $this->assertEquals([ 3, 4 ], $data);
    }

    public function testRecievesValidValuesWhenAppendedTo()
    {
        $node = new Category([ 'name' => 'test' ]);
        $root = Category::root();

        $root->append($node);

        $this->assertEquals(array($root->_rgt, $root->_rgt + 1, $root->id), $this->nodeValues($node));
        $this->assertTreeNotBroken();
    }

    public function testRecievesValidValuesWhenPrependedTo()
    {
        $root = Category::root();
        $node = new Category([ 'name' => 'test' ]);
        $root->prepend($node);

        $this->assertEquals(array($root->_lft + 1, $root->_lft + 2, $root->id), $this->nodeValues($node));
        $this->assertTreeNotBroken();
    }

    public function testRecievesValidValuesWhenInsertedAfter()
    {
        $target = $this->findCategory('apple');
        $node = new Category([ 'name' => 'test' ]);
        $node->after($target)->save();

        $this->assertEquals(array($target->_rgt + 1, $target->_rgt + 2, $target->parent->id), $this->nodeValues($node));
        $this->assertTreeNotBroken();
    }

    public function testRecievesValidValuesWhenInsertedBefore()
    {
        $target = $this->findCategory('apple');
        $node = new Category([ 'name' => 'test' ]);
        $node->before($target)->save();

        $this->assertEquals(array($target->_lft, $target->_lft + 1, $target->parent->id), $this->nodeValues($node));
        $this->assertTreeNotBroken();
    }

    public function testCategoryMovesDown()
    {
        $node = $this->findCategory('apple');
        $target = $this->findCategory('mobile');

        $target->append($node);

        $this->assertNodeRecievesValidValues($node);
        $this->assertTreeNotBroken();
    }

    public function testCategoryMovesUp()
    {
        $node = $this->findCategory('samsung');
        $target = $this->findCategory('notebooks');

        $target->append($node);

        $this->assertTreeNotBroken();
        $this->assertNodeRecievesValidValues($node);
    }

    /**
     * @expectedException Exception
     */
    public function testFailsToInsertIntoItself()
    {
        $node = $this->findCategory('notebooks');
        $target = $node->children()->first();

        $node->after($target)->save();
    }

    public function testWithoutRootWorks()
    {
        $result = Category::withoutRoot()->pluck('name');

        $this->assertNotEquals('store', $result);
    }

    public function testAncestorsReturnsAncestorsWithoutNodeItself()
    {
        $node = $this->findCategory('apple');
        $path = $node->ancestors()->lists('name');

        $this->assertEquals(array('store', 'notebooks'), $path);
    }

    public function testDescendantsQueried()
    {
        $node = $this->findCategory('mobile');
        $descendants = $node->descendants()->lists('name');

        $this->assertEquals(array('nokia', 'samsung', 'galaxy', 'sony'), $descendants);
    }

    public function testWithDepthWorks()
    {
        $nodes = Category::withDepth()->limit(4)->lists('depth');

        $this->assertEquals(array(0, 1, 2, 2), $nodes);
    }

    public function testWithDepthWithCustomKeyWorks()
    {
        $node = Category::whereIsRoot()->withDepth('level')->first();

        $this->assertTrue(isset($node['level']));
    }

    public function testWithDepthWorksAlongWithDefaultKeys()
    {
        $node = Category::withDepth()->first();

        $this->assertTrue(isset($node->name));
    }

    public function testParentIdAttributeAccessorAppendsNode()
    {
        $node = new Category(array('name' => 'lg', 'parent_id' => 5));
        $node->save();

        $this->assertEquals(5, $node->parent_id);
    }

    /**
     * @expectedException Exception
     */
    public function testFailsToSaveNodeUntilNotInserted()
    {
        $node = new Category;
        $node->save();
    }

    public function testNodeIsDeletedWithDescendants()
    {
        $node = $this->findCategory('notebooks');
        $this->assertTrue($node->delete());

        $this->assertTreeNotBroken();

        $nodes = Category::whereIn('id', array(2, 3, 4))->count();
        $this->assertEquals(0, $nodes);
    }

    /**
     * @expectedException Exception
     */
    public function testFailsToSaveNodeUntilParentIsSaved()
    {
        $node = new Category(array('title' => 'Node'));
        $parent = new Category(array('title' => 'Parent'));

        $node->appendTo($parent)->save();
    }

    public function testGetsSiblings()
    {
        $node = $this->findCategory('samsung');
        $siblings = $node->siblings()->lists('id');

        $this->assertEquals(array(6, 9), $siblings);
    }

    public function testGetsNextSiblings()
    {
        $node = $this->findCategory('samsung');
        $siblings = $node->nextSiblings()->lists('id');

        $this->assertEquals(array(9), $siblings);
    }

    public function testGetsPrevSiblings()
    {
        $node = $this->findCategory('samsung');
        $siblings = $node->prevSiblings()->lists('id');

        $this->assertEquals(array(6), $siblings);
    }

    public function testFetchesReversed()
    {
        $node = $this->findCategory('sony');
        $siblings = $node->prevSiblings()->reversed()->pluck('id');

        $this->assertEquals(7, $siblings);
    }

    public function testToTreeBuildsWithDefaultOrder()
    {
        $tree = Category::whereBetween('_lft', array(8, 17))->get()->toTree();

        $this->assertEquals(1, count($tree));

        $root = $tree->first();
        $this->assertEquals('mobile', $root->name);
        $this->assertEquals(3, count($root->children));
    }

    public function testToTreeBuildsWithCustomOrder()
    {
        $tree = Category::whereBetween('_lft', array(8, 17))
            ->orderBy('title')
            ->get()
            ->toTree();

        $this->assertEquals(1, count($tree));

        $root = $tree->first();
        $this->assertEquals('mobile', $root->name);
        $this->assertEquals(3, count($root->children));
    }

    public function testToTreeBuildsWithDefaultOrderAndMultipleRootNodes()
    {
        $tree = Category::withoutRoot()->get()->toTree();

        $this->assertEquals(2, count($tree));
    }

    public function testToTreeBuildsWithRootItemIdProvided()
    {
        $tree = Category::whereBetween('_lft', array(8, 17))->get()->toTree(5);

        $this->assertEquals(3, count($tree));

        $root = $tree[1];
        $this->assertEquals('samsung', $root->name);
        $this->assertEquals(1, count($root->children));
    }

    public function testRetrievesNextNode()
    {
        $node = $this->findCategory('apple');
        $next = $node->next()->first();

        $this->assertEquals('lenovo', $next->name);
    }

    public function testRetrievesPrevNode()
    {
        $node = $this->findCategory('apple');
        $next = $node->prev()->first();

        $this->assertEquals('notebooks', $next->name);
    }

    public function testMultipleAppendageWorks()
    {
        $parent = $this->findCategory('mobile');

        $child = new Category([ 'name' => 'test' ]);

        $parent->append($child);
        
        $child->append(new Category([ 'name' => 'sub' ]));

        $parent->append(new Category([ 'name' => 'test2' ]));

        $this->assertTreeNotBroken();
    }

    public function testDefaultCategoryIsSavedAsRoot()
    {
        $node = new Category([ 'name' => 'test' ]);
        $node->save();

        $this->assertEquals(19, $node->_lft);
        $this->assertTreeNotBroken();

        $this->assertTrue($node->isRoot());
    }

    public function testExistingCategorySavedAsRoot()
    {
        $node = $this->findCategory('apple');
        $node->saveAsRoot();

        $this->assertTreeNotBroken();
        $this->assertTrue($node->isRoot());
    }

    public function testNodeMovesDownSeveralPositions()
    {
        $node = $this->findCategory('nokia');

        $this->assertTrue($node->down(2));

        $this->assertEquals($node->_lft, 15);
    }

    public function testNodeMovesUpSeveralPositions()
    {
        $node = $this->findCategory('sony');

        $this->assertTrue($node->up(2));

        $this->assertEquals($node->_lft, 9);
    }

    public function testCountsTreeErrors()
    {
        $errors = with(new Category)->countErrors();

        $this->assertEquals([ 'oddness' => 0, 'duplicates' => 0, 'wrong_parent' => 0 ], $errors);
    }
}