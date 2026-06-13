<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('faq_items', function (Blueprint $table) {
            $table->id();
            $table->ulid('uid')->unique();
            $table->unsignedBigInteger('integration_id');
            $table->text('question');
            $table->text('answer');
            $table->string('status')->default('active');
            $table->integer('sort_order')->default(0);
            $table->bigInteger('created_by')->default(0);
            $table->bigInteger('updated_by')->default(0);
            $table->timestamps();

            $table->index(['integration_id', 'status'], 'faq_items_integration_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('faq_items');
    }
};