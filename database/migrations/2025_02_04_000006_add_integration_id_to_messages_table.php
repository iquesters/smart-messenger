<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('messages') || Schema::hasColumn('messages', 'integration_id')) {
            return;
        }

        Schema::table('messages', function (Blueprint $table) {
            $table->unsignedBigInteger('integration_id')
                  ->nullable()
                  ->after('channel_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('messages') || ! Schema::hasColumn('messages', 'integration_id')) {
            return;
        }

        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn('integration_id');
        });
    }
};
