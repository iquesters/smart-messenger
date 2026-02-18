<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflows', function (Blueprint $table) {
            $table->id();
            $table->ulid('uid')->unique();
            $table->string('name');
            // $table->string('trigger_event'); // message_received, status_update, manual, webhook_event
            // $table->integer('priority')->default(1);
            $table->string('status')->default('active');
            $table->boolean('is_default')->default(false);
            $table->bigInteger('created_by')->default(0);
            $table->bigInteger('updated_by')->default(0);
            $table->timestamps();
        });

        // New workflow_metas table
        Schema::create('workflow_metas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ref_parent')->constrained('workflows')->onDelete('cascade')->onUpdate('cascade');
            $table->string('meta_key');
            $table->longText('meta_value');
            $table->string('status')->default('active');
            $table->bigInteger('created_by')->default(0);
            $table->bigInteger('updated_by')->default(0);
            $table->timestamps();

            $table->index(
                ['ref_parent', 'meta_key'],
                'workflow_meta_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_metas');
        Schema::dropIfExists('workflows');
    }
};