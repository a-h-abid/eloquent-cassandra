<?php


class CollectionTest extends TestCase
{
    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        \Illuminate\Support\Facades\DB::connection('cassandra')->select('TRUNCATE users');

        $faker = Faker\Factory::create();
        for($i = 1; $i <= 20; $i++) {
            \Illuminate\Support\Facades\DB::connection('cassandra')
                ->table('users')
                ->insert([
                    'id' => $i,
                    'name' => $faker->name(),
                    'title' => $faker->title(),
                    'age' => rand(18, 40),
                    'note1' => $faker->sentence(),
                    'note2' => $faker->sentence(),
                    'birthday' => new \Cassandra\Timestamp(time()),
                    'created_at' => new \Cassandra\Timestamp(time()),
                    'updated_at' => new \Cassandra\Timestamp(time()),
                ]);
        }
    }

    public function testModelResponseIsCollection()
    {
        $result = User::get();
        $this->assertInstanceOf(\lroman242\LaravelCassandra\Collection::class, $result);
    }

    public function testCorrectResultsAmount()
    {
        $result = User::get();
        $this->assertEquals(20, $result->count());
    }

    public function testCorrectPageResultsAmount()
    {
        $result = User::setPageSize(5)->getPage();
        $this->assertEquals(5, $result->count());
    }

    public function testCorrectResultsAmountWithSetPageSize()
    {
        $result = User::setPageSize(5)->get();
        $this->assertEquals(20, $result->count());
    }

    public function testCollectionIsLastPage()
    {
        $result = User::setPageSize(5)->getPage();
        $this->assertFalse($result->isLastPage());

        $result = User::setPageSize()->get();
        $this->assertTrue($result->isLastPage());

        $result = User::get();
        $this->assertTrue($result->isLastPage());

        $result = User::setPageSize(5)->get();
        $this->assertTrue($result->isLastPage());
    }

    public function testGetNextPage()
    {
        /** @var \lroman242\LaravelCassandra\Collection $result */
        $result = User::setPageSize(11)->getPage();
        $this->assertFalse($result->isLastPage());

        $nextPageResults = $result->nextPage();
        $this->assertInstanceOf(\lroman242\LaravelCassandra\Collection::class, $nextPageResults);
        $this->assertTrue($nextPageResults->isLastPage());

        $nextPageResults2 = $nextPageResults->nextPage();
        $this->assertNull($nextPageResults2);
    }

    public function testGetNextPageToken()
    {
        $result = User::setPageSize(5)->getPage();
        $this->assertFalse($result->isLastPage());
        $token = $result->getNextPageToken();

        $this->assertNotEmpty($token);
    }

    public function testGetNextPageTokenValid()
    {
        $result = User::setPageSize(11)->getPage();
        $token = $result->getNextPageToken();

        $nextPageResults = User::setPaginationStateToken($token)->getPage();
        $this->assertInstanceOf(\lroman242\LaravelCassandra\Collection::class, $nextPageResults);
        $this->assertTrue($nextPageResults->isLastPage());
    }

    public function testPageCollectionItemsAreModels()
    {
        $results = User::setPageSize(5)->getPage();
        foreach ($results as $result) {
            $this->assertInstanceOf(User::class, $result);
        }
    }

    public function testCollectionItemsAreModels()
    {
        $results = User::get();
        foreach ($results as $result) {
            $this->assertInstanceOf(User::class, $result);
        }
    }

    public function testPageFindInCollectionByKeyValue()
    {
        $result = User::setPageSize(5)->getPage();
        $this->assertInstanceOf(\lroman242\LaravelCassandra\Collection::class, $result);

        $searchResults = $result->find(10);
        $this->assertTrue($searchResults->id == 10);
    }

    public function testFindInCollectionByKeyValue()
    {
        $result = User::get();
        $this->assertInstanceOf(\lroman242\LaravelCassandra\Collection::class, $result);

        $searchResults = $result->find(10);
        $this->assertTrue($searchResults->id == 10);

    }

    public function testFindInCollectionByModel()
    {
        $result = User::get();
        $user = User::first();

        $searchResults = $result->find($user);

        $this->assertEquals($searchResults, $user);
    }

    public function testFindInCollectionByEmptyArrayable()
    {
        $searchValues = collect([]);
        $result = User::get();
        $searchResults = $result->find($searchValues);

        $this->assertInstanceOf(\lroman242\LaravelCassandra\Collection::class, $searchResults);
        $this->assertEquals(0, $searchResults->count());
    }

    public function testFindInCollectionByArrayable()
    {
        $searchValues = collect([1,2,3,4,5]);
        $result = User::get();
        $searchResults = $result->find($searchValues);

        $this->assertInstanceOf(\lroman242\LaravelCassandra\Collection::class, $searchResults);
        $this->assertEquals(5, $searchResults->count());
        foreach ($searchResults as $item) {
            $this->assertTrue($searchValues->contains($item->id));
        }
    }

    public function testFindInEmptyCollectionByArrayable()
    {
        $searchValues = collect([1,2,3,4,5]);
        $result = User::where('id', '>', 40)->allowFiltering(true)->get();
        $searchResults = $result->find($searchValues);

        $this->assertInstanceOf(\lroman242\LaravelCassandra\Collection::class, $searchResults);
        $this->assertEquals(0, $searchResults->count());
    }

    public function testPageGetRows()
    {
        $result = User::setPageSize(5)->getPage();
        $rows = $result->getRows();

        $this->assertInstanceOf(\Cassandra\Rows::class, $rows);
        $this->assertEquals($rows->count(), $result->count());
    }

    public function testAllPagesGetRows()
    {
        $result = User::get();
        $rows = $result->getRows();

        $this->assertNull($rows);
    }

    public function testCollectionGetDictionary()
    {
        $results = User::get();
        $dictionary = $results->getDictionary();

        $this->assertTrue(is_array($dictionary));
        $this->assertEquals(count($dictionary), $results->count());

        foreach ($dictionary as $key => $value) {
            $this->assertEquals($results->find($key), $value);
        }
    }

    public function testCollectionExcept()
    {
        $results = User::get();

        $exceptKeys = [1,2];
        $exceptResults = $results->except($exceptKeys);

        $this->assertInstanceOf(\lroman242\LaravelCassandra\Collection::class, $exceptResults);
        $this->assertEquals($exceptResults->count(), ($results->count() - count($exceptKeys)));

        foreach ($exceptResults as $value) {
            $this->assertFalse(in_array($value->getKey(), $exceptKeys));
        }
    }

    public function testCollectionOnly()
    {
        $results = User::get();

        $onlyKeys = [1, 2];

        $onlyResults = $results->only($onlyKeys);
        $this->assertInstanceOf(\lroman242\LaravelCassandra\Collection::class, $onlyResults);
        $this->assertEquals($onlyResults->count(), count($onlyKeys));

        foreach ($onlyResults as $value) {
            $this->assertTrue(in_array($value->getKey(), $onlyKeys));
        }
    }

    public function testCollectionOnlyNullArgument()
    {
        $results = User::get();

        $onlyResults = $results->only(null);

        $this->assertInstanceOf(\lroman242\LaravelCassandra\Collection::class, $onlyResults);
        $this->assertEquals($onlyResults->count(), $results->count());

        foreach ($onlyResults as $value) {
            $this->assertTrue($results->contains($value));
        }
    }

    public function testUniqueDuplicate()
    {
        $results = User::get();
        $duplicate = User::first();

        $originCount = $results->count();
        $resultsWithDuplicate = $results->push($duplicate);

        $this->assertEquals($resultsWithDuplicate->count(), $originCount + 1);

        $uniqueResults = $resultsWithDuplicate->unique('name');

        $this->assertInstanceOf(\lroman242\LaravelCassandra\Collection::class, $uniqueResults);
        $this->assertEquals($uniqueResults->count(), $originCount);
    }

    public function testCollectionUniqe()
    {
        User::create([
            'id' => 101,
            'name' => 'Tester',
            'title' => 'Mr.',
            'age' => rand(18, 40),
            'note1' => '',
            'note2' => '',
            'birthday' => new \Cassandra\Timestamp(time()),
            'created_at' => new \Cassandra\Timestamp(time()),
            'updated_at' => new \Cassandra\Timestamp(time()),
        ]);
        User::create([
            'id' => 102,
            'name' => 'Tester',
            'title' => 'Mr.',
            'age' => rand(18, 40),
            'note1' => '',
            'note2' => '',
            'birthday' => new \Cassandra\Timestamp(time()),
            'created_at' => new \Cassandra\Timestamp(time()),
            'updated_at' => new \Cassandra\Timestamp(time()),
        ]);

        $results = User::where('id', '>', 15)->allowFiltering(true)->get();
        $this->assertEquals(7, $results->count());

        $uniqueResults = $results->unique('name');
        $this->assertInstanceOf(\lroman242\LaravelCassandra\Collection::class, $uniqueResults);
        $this->assertEquals($uniqueResults->count(), $results->count() - 1);
    }
}