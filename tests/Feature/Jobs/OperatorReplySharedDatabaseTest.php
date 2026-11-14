<?php

namespace Iquesters\SmartMessenger\Tests\Feature\Jobs;

use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Iquesters\SmartMessenger\Jobs\MessageJobs\OperatorReplyJob;
use Iquesters\SmartMessenger\SmartMessengerServiceProvider;
use Orchestra\Testbench\TestCase;

/** One phase of the disposable cross-repository API → worker → projector test. */
class OperatorReplySharedDatabaseTest extends TestCase
{
    protected function getPackageProviders($app)
    {
        return [SmartMessengerServiceProvider::class];
    }

    protected function defineEnvironment($app)
    {
        $database = getenv('OPERATOR_PIPELINE_DB_FILE');
        if ($database) {
            $app['config']->set('database.default', 'sqlite');
            $app['config']->set('database.connections.sqlite.database', $database);
            $app['config']->set('queue.default', 'sync');
        }
    }

    public function test_a_util_accepted_command_is_sent_and_persisted_by_the_worker(): void
    {
        if (!getenv('OPERATOR_PIPELINE_DB_FILE')) {
            $this->markTestSkipped('disposable shared database not configured');
        }
        Event::forget(JobProcessed::class);
        $command = DB::table('operator_outbound_commands')->where('conversation_id', 101)->first();
        $this->assertNotNull($command);
        $this->assertSame('dispatched', $command->state);
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.pipeline.1']]], 200)]);

        (new OperatorReplyJob(['command_uid' => $command->uid]))->handle();

        $sent = DB::table('operator_outbound_commands')->where('uid', $command->uid)->first();
        $this->assertSame('sent', $sent->state);
        $this->assertNotNull($sent->message_id);
        $this->assertSame('operator', DB::table('message_metas')
            ->where('ref_parent', $sent->message_id)->where('meta_key', 'actor')
            ->value('meta_value'));
        $this->assertSame($command->uid, DB::table('message_metas')
            ->where('ref_parent', $sent->message_id)->where('meta_key', 'operator_command_uid')
            ->value('meta_value'));
        Http::assertSentCount(1);
    }
}
