<?php

namespace Iquesters\SmartMessenger\Tests\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The V9 tables this package touches, for tests.
 *
 * Only `operator_outbound_commands` is created: it is the one Operator Inbox
 * table smart-messenger reads or writes. The rest of the projection is owned
 * by chatbot-util and chatbot-job, and creating it here would imply this
 * package knows about tables it must not touch.
 *
 * Mirrors `chatbot-util/db/9.sql`. ENUMs become strings because SQLite has
 * none; the states are validated by the code under test, not the column.
 */
trait CreatesOperatorInboxTables
{
    protected function createOperatorOutboundCommandsTable(): void
    {
        if (Schema::hasTable('operator_outbound_commands')) {
            return;
        }

        Schema::create('operator_outbound_commands', function (Blueprint $table) {
            $table->id();
            $table->string('uid', 26)->unique();
            $table->unsignedBigInteger('organisation_id');
            $table->unsignedBigInteger('conversation_id');
            $table->unsignedBigInteger('user_id');
            $table->string('client_message_id', 36);
            $table->unsignedBigInteger('anchor_inbound_message_id');
            $table->string('message_type', 16)->default('text');
            $table->text('body');
            $table->string('state', 16);
            $table->unsignedBigInteger('laravel_job_id')->nullable();
            $table->unsignedBigInteger('message_id')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'client_message_id']);
        });
    }
}
