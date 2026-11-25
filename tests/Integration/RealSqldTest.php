<?php

namespace SQLiteHub\Laravel\Tests\Integration;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Orchestra\Testbench\TestCase;
use RuntimeException;
use SQLiteHub\Laravel\SQLiteHubServiceProvider;

/**
 * Runs the driver against a real sqld. Skipped unless these are set:
 *
 *   SQLD_TEST_ADMIN_URL=http://127.0.0.1:19090
 *   SQLD_TEST_HTTP_URL=http://127.0.0.1:18080
 *
 * Start one with (from the repo root): docker compose -f compose.test.yml up -d sqld-test
 * That sqld trusts the main repo's test signing key, so tokens are checked as on the shards.
 */
class RealSqldTest extends TestCase
{
    /** phpunit.xml's SQLITEHUB_TOKEN_SIGNING_KEY in the main repo (test-only key). */
    private const SIGNING_SEED = 'wjiPOGYVkgoLa3CzpyIOQ3HMfnh1jYVnX7DmSPvuMII=';

    private string $namespace;

    private string $adminUrl;

    protected function getPackageProviders($app)
    {
        return [SQLiteHubServiceProvider::class];
    }

    protected function setUp(): void
    {
        $this->adminUrl = (string) getenv('SQLD_TEST_ADMIN_URL');
        $httpUrl = (string) getenv('SQLD_TEST_HTTP_URL');
        if ($this->adminUrl === '' || $httpUrl === '') {
            $this->markTestSkipped('Set SQLD_TEST_ADMIN_URL and SQLD_TEST_HTTP_URL to run against a real sqld.');
        }

        parent::setUp();

        $this->namespace = 'it-'.Str::lower(Str::random(10)).'--laravel';
        Http::post("{$this->adminUrl}/v1/namespaces/{$this->namespace}/create", (object) [])->throw();

        config()->set('database.default', 'sqlitehub');
        config()->set('database.connections.sqlitehub', [
            'driver' => 'sqlitehub',
            'endpoint' => $httpUrl,
            'host_header' => "{$this->namespace}.db.test",
            'token' => self::token($this->namespace),
            'prefix' => '',
            'foreign_key_constraints' => true,
            'timeout' => 10,
        ]);
    }

    protected function tearDown(): void
    {
        if (isset($this->namespace)) {
            DB::disconnect();
            Http::delete("{$this->adminUrl}/v1/namespaces/{$this->namespace}");
        }

        parent::tearDown();
    }

    /** An EdDSA JWT with the claims SQLiteHub issues: {"id": namespace, "a": "rw"|"ro"}. */
    public static function token(string $namespace, string $access = 'rw'): string
    {
        $b64 = fn (string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $pair = sodium_crypto_sign_seed_keypair(base64_decode(self::SIGNING_SEED));
        $signing = $b64(json_encode(['alg' => 'EdDSA', 'typ' => 'JWT'])).'.'.$b64(json_encode(['id' => $namespace, 'a' => $access, 'iat' => time()]));

        return $signing.'.'.$b64(sodium_crypto_sign_detached($signing, sodium_crypto_sign_secretkey($pair)));
    }

    public function test_migrations_eloquent_and_transactions(): void
    {
        $this->assertMatchesRegularExpression('/^3\.\d+/', DB::connection()->getServerVersion());

        $migration = new class extends Migration
        {
            public function up(): void
            {
                Schema::create('customers', function (Blueprint $table) {
                    $table->id();
                    $table->string('email')->unique();
                    $table->json('meta')->nullable();
                    $table->binary('avatar')->nullable();
                    $table->timestamps();
                });
                Schema::create('invoices', function (Blueprint $table) {
                    $table->id();
                    $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
                    $table->decimal('total', 12, 2);
                    $table->boolean('paid')->default(false);
                    $table->timestamps();
                });
                Schema::table('invoices', function (Blueprint $table) {
                    $table->string('note')->nullable()->after('total');
                    $table->index(['customer_id', 'paid']);
                });
            }
        };
        DB::transaction(fn () => $migration->up());

        $this->assertSame(['customers', 'invoices'], Schema::getTableListing(schemaQualified: false));
        $this->assertTrue(Schema::hasColumns('invoices', ['note', 'paid']));

        // Eloquent CRUD, with value round-trips.
        $avatar = random_bytes(64);
        $customer = RealCustomer::create(['email' => 'asha@example.com', 'meta' => ['tier' => 'gold'], 'avatar' => $avatar]);
        $this->assertSame(1, $customer->id);
        $found = RealCustomer::find($customer->id);
        $this->assertSame(['tier' => 'gold'], $found->meta);
        $this->assertSame($avatar, $found->avatar);

        $invoice = $found->invoices()->create(['total' => 1499.75, 'paid' => true]);
        $this->assertSame(1499.75, (float) RealInvoice::find($invoice->id)->total);
        $this->assertTrue(RealInvoice::find($invoice->id)->paid);
        $note = "it's \"paid\"\n\\ ₹ 🎉 \u{0}";
        $invoice->update(['note' => $note]);
        $this->assertSame($note, $invoice->fresh()->note);

        // Unique violations are Laravel's exception.
        try {
            RealCustomer::create(['email' => 'asha@example.com']);
            $this->fail('Expected a unique constraint violation');
        } catch (UniqueConstraintViolationException $e) {
            $this->assertSame(['email'], $e->columns);
        }

        // Foreign keys are on in every stream.
        $this->expectExceptionOnInsertWithoutParent();

        // Transactions: commit, rollback on exception, nested rollback to a savepoint.
        DB::transaction(function () {
            RealCustomer::create(['email' => 'ravi@example.com']);
            try {
                DB::transaction(function () {
                    RealCustomer::create(['email' => 'nested@example.com']);
                    throw new RuntimeException('inner');
                });
            } catch (RuntimeException) {
            }
        });
        $this->assertSame(['asha@example.com', 'ravi@example.com'], RealCustomer::orderBy('id')->pluck('email')->all());

        try {
            DB::transaction(function () {
                RealCustomer::create(['email' => 'lost@example.com']);
                throw new RuntimeException('outer');
            });
        } catch (RuntimeException) {
        }
        $this->assertFalse(RealCustomer::where('email', 'lost@example.com')->exists());
        $this->assertNull(DB::connection()->getPdo()->baton(), 'no stream left open');

        // Cascading delete (foreign keys enforced) and drop-all (migrate:fresh).
        $found->delete();
        $this->assertSame(0, RealInvoice::count());
        Schema::dropAllTables();
        $this->assertSame([], Schema::getTableListing());
    }

    public function test_read_only_tokens_and_bad_tokens(): void
    {
        Schema::create('t', fn (Blueprint $table) => $table->integer('x'));
        DB::table('t')->insert(['x' => 1]);

        config()->set('database.connections.ro', ['token' => self::token($this->namespace, 'ro')] + config('database.connections.sqlitehub'));
        $this->assertSame(1, DB::connection('ro')->table('t')->count());
        try {
            DB::connection('ro')->table('t')->insert(['x' => 2]);
            $this->fail('A read-only token must not write');
        } catch (QueryException $e) {
            $this->assertNotSame('', $e->getMessage());
        }

        config()->set('database.connections.bad', ['token' => 'not-a-token'] + config('database.connections.sqlitehub'));
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('HTTP 401');
        DB::connection('bad')->table('t')->count();
    }

    private function expectExceptionOnInsertWithoutParent(): void
    {
        try {
            DB::table('invoices')->insert(['customer_id' => 999, 'total' => 1]);
            $this->fail('Expected a foreign key violation');
        } catch (QueryException $e) {
            $this->assertStringContainsString('FOREIGN KEY constraint failed', $e->getMessage());
        }
    }
}

class RealCustomer extends Model
{
    protected $table = 'customers';

    protected $guarded = [];

    protected $casts = ['meta' => 'array'];

    public function invoices()
    {
        return $this->hasMany(RealInvoice::class, 'customer_id');
    }
}

class RealInvoice extends Model
{
    protected $table = 'invoices';

    protected $guarded = [];

    protected $casts = ['paid' => 'boolean', 'total' => 'decimal:2'];
}
