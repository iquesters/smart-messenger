<?php

namespace Iquesters\SmartMessenger\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Iquesters\Foundation\Http\Middleware\EnsureTenantResourceAdmin;
use Iquesters\SmartMessenger\Tests\Fixtures\TenantAuthorizationFixture;
use Spatie\Permission\Models\Role;

/** Actual package routes, using the shared policy's isolated two-tenant fixture. */
class ChannelAuthorizationTest extends TenantAuthorizationFixture
{
    protected function defineRoutes($router): void
    {
        parent::defineRoutes($router);
        require __DIR__.'/../../routes/web.php';
        Route::prefix('api/smart-messenger')->middleware(['web', 'auth'])->group(__DIR__.'/../../routes/api.php');
    }

    public function test_actual_channel_management_routes_are_guarded(): void
    {
        foreach (app('router')->getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'channels')) {
                $this->assertContains(EnsureTenantResourceAdmin::class.':channel', $route->middleware());
            }
        }
        $this->actingAs($this->makeUser('user'))->getJson('/channels')->assertForbidden();
        $this->postJson('/channels', ['meta' => ['system_user_token' => 'test-only']])->assertForbidden();
    }

    public function test_actual_foreign_channel_edit_is_denied_before_controller(): void
    {
        $user = $this->makeUser('admin');
        foreach ([1, 2] as $id) {
            DB::table('model_has_organisations')->insert(['organisation_id' => $id, 'model_type' => $user->getMorphClass(), 'model_id' => $user->id]);
        }
        DB::table('organisation_role_assignments')->insert(['organisation_id' => 1, 'model_type' => $user->getMorphClass(),
            'model_id' => $user->id, 'role_id' => Role::findByName('admin', 'web')->id]);
        $this->actingAs($user)->getJson('/channels/B/edit')->assertNotFound();
        $this->deleteJson('/channels/B')->assertNotFound();
        $this->postJson('/channels/A/edit/step1', ['organisation_id' => 2])->assertNotFound();
    }

    public function test_draft_and_linked_resources_cannot_cross_tenants(): void
    {
        $user = $this->makeUser('admin');
        foreach ([1, 2] as $id) {
            DB::table('model_has_organisations')->insert(['organisation_id' => $id, 'model_type' => $user->getMorphClass(), 'model_id' => $user->id]);
            DB::table('organisation_role_assignments')->insert(['organisation_id' => $id, 'model_type' => $user->getMorphClass(),
                'model_id' => $user->id, 'role_id' => Role::findByName('admin', 'web')->id]);
        }
        $this->actingAs($user)->withSession(['channel_step1_data' => ['organisation_id' => 1, '_actor_id' => $user->id, '_resource_uid' => 'A']]);
        $this->putJson('/channels/A', ['meta' => ['chatbot_integration_id' => 2]])->assertNotFound();
        $this->putJson('/channels/A', ['meta' => ['workflow_ids' => '[2]']])->assertNotFound();
        $this->putJson('/channels/B', ['meta' => ['test' => 'value']])->assertConflict();
        DB::table('organisation_role_assignments')->where('organisation_id', 1)->delete();
        $this->putJson('/channels/A', ['meta' => ['test' => 'value']])->assertNotFound();
    }

    public function test_legacy_commands_cannot_bypass_scoped_inbox_controls_or_spoof_actor(): void
    {
        $this->actingAs($this->makeUser('user'))->postJson('/inbox/send', ['profile_id' => 1, 'to' => 'test-only'])->assertForbidden();
        $payload = ['session_id' => 'test', 'contact_uid' => 'test', 'chatbot_integration_uid' => 'A', 'agent_user_id' => 999999];
        $this->postJson('/api/smart-messenger/chat-sessions/handover/activate', $payload)->assertForbidden();
        $this->postJson('/api/smart-messenger/chat-sessions/handover/return-to-bot', $payload)->assertForbidden();
        $this->actingAs($this->makeUser('super-admin'))->postJson('/api/smart-messenger/chat-sessions/handover/activate', $payload)->assertForbidden();
    }
}
