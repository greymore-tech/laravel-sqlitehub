<?php

namespace SQLiteHub\Laravel\Tests\Feature;

use ArrayObject;
use Illuminate\Support\Facades\DB;
use PDO;
use SQLiteHub\Laravel\Hrana\HranaException;
use SQLiteHub\Laravel\Hrana\HranaStatement;
use SQLiteHub\Laravel\Tests\TestCase;
use stdClass;

class StatementTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->pdo()->exec('create table kv (k text, v)');
        $this->pdo()->exec("insert into kv values ('a', 1), ('b', 2.5), ('c', null)");
    }

    private function select(): HranaStatement
    {
        $statement = $this->pdo()->prepare('select k, v from kv order by k');
        $statement->execute();

        return $statement;
    }

    public function test_fetch_modes(): void
    {
        $this->assertSame([['k' => 'a', 'v' => 1], ['k' => 'b', 'v' => 2.5], ['k' => 'c', 'v' => null]], $this->select()->fetchAll(PDO::FETCH_ASSOC));
        $this->assertSame([['a', 1], ['b', 2.5], ['c', null]], $this->select()->fetchAll(PDO::FETCH_NUM));
        $this->assertSame(['k' => 'a', 'v' => 1, 0 => 'a', 1 => 1], $this->select()->fetch(PDO::FETCH_BOTH));
        $this->assertSame([1, 2.5, null], $this->select()->fetchAll(PDO::FETCH_COLUMN, 1));
        $this->assertSame(['a' => 1, 'b' => 2.5, 'c' => null], $this->select()->fetchAll(PDO::FETCH_KEY_PAIR));
        $this->assertEquals([(object) ['k' => 'a', 'v' => 1]], array_slice($this->select()->fetchAll(PDO::FETCH_OBJ), 0, 1));

        $objects = $this->select()->fetchAll(PDO::FETCH_CLASS, ArrayObject::class, [[], ArrayObject::ARRAY_AS_PROPS]);
        $this->assertInstanceOf(ArrayObject::class, $objects[0]);
        $this->assertSame('a', $objects[0]->k);
        $this->assertInstanceOf(stdClass::class, $this->select()->fetchAll(PDO::FETCH_CLASS)[0]);
    }

    public function test_default_fetch_mode_and_cursor(): void
    {
        $statement = $this->select();
        $this->assertSame(['k' => 'a', 'v' => 1, 0 => 'a', 1 => 1], $statement->fetch(), 'PDO defaults to FETCH_BOTH');

        $statement->setFetchMode(PDO::FETCH_COLUMN, 0);
        $this->assertSame('b', $statement->fetch());
        $this->assertSame(['c'], $statement->fetchAll());
        $this->assertFalse($statement->fetch());
        $this->assertFalse($statement->fetchColumn());
    }

    public function test_fetch_column_object_and_iterator(): void
    {
        $statement = $this->select();
        $this->assertSame(1, $statement->fetchColumn(1));
        $this->assertNull($statement->fetchColumn(5));
        $this->assertEquals((object) ['k' => 'c', 'v' => null], $statement->fetchObject());
        $this->assertFalse($statement->fetchObject());

        $object = $this->select()->fetchObject(ArrayObject::class, [[], ArrayObject::ARRAY_AS_PROPS]);
        $this->assertSame('a', $object->k);
        $this->assertSame('a', $this->select()->fetchObject(null)->k);

        $statement = $this->select();
        $statement->setFetchMode(PDO::FETCH_NUM);
        $this->assertSame([['a', 1], ['b', 2.5], ['c', null]], iterator_to_array($statement));
    }

    public function test_metadata_and_pdo_compat_methods(): void
    {
        $statement = $this->select();
        $this->assertSame(2, $statement->columnCount());
        $this->assertSame(0, $statement->rowCount());
        $this->assertSame('k', $statement->getColumnMeta(0)['name']);
        $this->assertSame('TEXT', $statement->getColumnMeta(0)['native_type']);
        $this->assertFalse($statement->getColumnMeta(9));
        $this->assertFalse($statement->nextRowset());
        $this->assertSame('00000', $statement->errorCode());
        $this->assertSame(['00000', null, null], $statement->errorInfo());
        $this->assertTrue($statement->closeCursor());
        $this->assertSame([], $statement->fetchAll());
    }

    public function test_unsupported_fetch_mode(): void
    {
        $this->expectException(HranaException::class);
        $this->expectExceptionMessage('PDO fetch mode '.PDO::FETCH_LAZY.' is not supported');
        $this->select()->fetch(PDO::FETCH_LAZY);
    }

    public function test_named_and_execute_params(): void
    {
        $statement = $this->pdo()->prepare('select v from kv where k = :k');
        $statement->bindValue(':k', 'b');
        $statement->execute();
        $this->assertSame(2.5, $statement->fetchColumn());

        $statement = $this->pdo()->prepare('select k from kv where v = ? or v = ?');
        $statement->execute([1, 2.5]);
        $this->assertSame(['a', 'b'], $statement->fetchAll(PDO::FETCH_COLUMN));
        $this->assertSame([['type' => 'integer', 'value' => '1'], ['type' => 'float', 'value' => 2.5]], end($this->server->requests)->data()['requests'][1]['stmt']['args']);
    }

    public function test_pdo_methods(): void
    {
        $pdo = $this->pdo();

        $this->assertSame(2, $pdo->exec('update kv set v = 0 where v is not null;'));
        $this->assertSame(1, $pdo->exec("insert into kv values ('d', 4)"));
        $this->assertSame('4', $pdo->lastInsertId());
        $this->assertSame(0, $pdo->exec("insert into kv values ('e', 5); select 1"), 'a sequence reports no count');
        $this->assertSame(['a', 'b', 'c', 'd', 'e'], $pdo->query('select k from kv order by k', PDO::FETCH_COLUMN, 0)->fetchAll());
        $this->assertSame([['n' => 5]], $pdo->query('select count(*) as n from kv')->fetchAll(PDO::FETCH_ASSOC));

        $this->assertSame("'it''s'", $pdo->quote("it's"));
        $this->assertSame("'it''s'", DB::escape("it's"));
        $this->assertSame("x'ff'", DB::escape("\xff", true));
        $this->assertSame('sqlitehub', $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        $this->assertSame('hrana-http/3', $pdo->getAttribute(PDO::ATTR_CLIENT_VERSION));
        $this->assertSame(PDO::ERRMODE_EXCEPTION, $pdo->getAttribute(PDO::ATTR_ERRMODE));
        $this->assertNull($pdo->getAttribute(PDO::ATTR_TIMEOUT));
        $this->assertTrue($pdo->setAttribute(PDO::ATTR_TIMEOUT, 5));
        $this->assertSame(5, $pdo->getAttribute(PDO::ATTR_TIMEOUT));
        $this->assertSame('00000', $pdo->errorCode());
        $this->assertSame(['00000', null, null], $pdo->errorInfo());
    }

    public function test_laravel_fetch_using(): void
    {
        $this->assertSame(['a' => 1, 'b' => 2.5, 'c' => null], DB::table('kv')->orderBy('k')->fetchUsing(PDO::FETCH_KEY_PAIR)->get()->all());
    }

    public function test_blob_round_trip_through_bindings(): void
    {
        $blob = random_bytes(16)."\xfe";
        DB::insert('insert into kv values (?, ?)', ['blob', $blob]);

        $this->assertSame($blob, DB::table('kv')->where('k', 'blob')->value('v'));
        $this->assertSame('blob', $this->server->pipelines()[2][1]['stmt']['args'][1]['type']);
    }
}
