<?php

namespace Iquesters\SmartMessenger\Tests\Fixtures;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Iquesters\Foundation\FoundationServiceProvider;
use Orchestra\Testbench\TestCase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionServiceProvider;
use Spatie\Permission\Traits\HasRoles;

class SecurityUser extends User
{
    use HasRoles;

    protected $table = 'users';

    protected $guarded = [];

    protected $guard_name = 'web';
}

abstract class TenantAuthorizationFixture extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [PermissionServiceProvider::class, FoundationServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:2fl+Ktvkfl+Fuz4Qp/A75G2RTiWVA/ZoKZvp6fiiM10=');
        $app['config']->set('auth.providers.users.model', SecurityUser::class);
        $app['config']->set('permission.cache.store', 'array');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
    }

    protected function defineRoutes($router): void {}

    protected function makeUser(?string $role = null): SecurityUser
    {
        $user = SecurityUser::create(['name' => 'Test', 'status'=>'active', 'email' => bin2hex(random_bytes(8)).'@example.test', 'password' => bcrypt('test-only')]);
        if ($role) {
            Role::findOrCreate($role, 'web');
            $user->assignRole($role);
        }

        return $user;
    }

    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('email');
            $t->string('password');
            $t->string('status')->default('active');
            $t->timestamps();
        });
        $spatie = dirname((new \ReflectionClass(PermissionServiceProvider::class))->getFileName(), 2);
        (require $spatie.'/database/migrations/create_permission_tables.php.stub')->up();
        Schema::create('organisations', function (Blueprint $t) {
            $t->id();
            $t->string('status');
        });
        Schema::create('model_has_organisations', function (Blueprint $t) {
            $t->unsignedBigInteger('organisation_id');
            $t->string('model_type');
            $t->unsignedBigInteger('model_id');
            $t->primary(['organisation_id', 'model_type', 'model_id']);
        });
        Schema::create('organisation_role_assignments', function (Blueprint $t) {
            $t->unsignedBigInteger('organisation_id');
            $t->string('model_type');
            $t->unsignedBigInteger('model_id');
            $t->unsignedBigInteger('role_id');
            $t->primary(['organisation_id', 'model_type', 'model_id', 'role_id']);
        });
        Schema::create('workflows', function (Blueprint $t) {
            $t->id();
            $t->string('status');
        });
        DB::table('workflows')->insert(['id' => 2, 'status' => 'active']);
        DB::table('model_has_organisations')->insert(['organisation_id' => 2, 'model_type' => 'Iquesters\\SmartMessenger\\Models\\Workflow', 'model_id' => 2]);
        foreach (['integrations', 'channels'] as $table) {
            Schema::create($table, function (Blueprint $t) {
                $t->id();
                $t->string('uid');
            });
            DB::table($table)->insert([['id' => 1, 'uid' => 'A'], ['id' => 2, 'uid' => 'B']]);
        }
        DB::table('organisations')->insert([['id' => 1, 'status' => 'active'], ['id' => 2, 'status' => 'active']]);
        foreach (['Iquesters\Integration\Models\Integration', 'Iquesters\SmartMessenger\Models\Channel'] as $type) {
            DB::table('model_has_organisations')->insert([
                ['organisation_id' => 1, 'model_id' => 1, 'model_type' => $type],
                ['organisation_id' => 2, 'model_id' => 2, 'model_type' => $type],
            ]);
        }
    }

    protected function admin()
    {
        $user = $this->makeUser('admin');
        DB::table('model_has_organisations')->insert([
            ['organisation_id' => 1, 'model_id' => $user->id, 'model_type' => $user->getMorphClass()],
            ['organisation_id' => 2, 'model_id' => $user->id, 'model_type' => $user->getMorphClass()],
        ]);
        DB::table('organisation_role_assignments')->insert(['organisation_id' => 1, 'model_id' => $user->id,
            'model_type' => $user->getMorphClass(), 'role_id' => Role::findByName('admin', 'web')->id]);

        return $user;
    }
}
