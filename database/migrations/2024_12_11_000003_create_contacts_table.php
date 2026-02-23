<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->ulid('uid')->unique();
            $table->string('name');
            $table->string('identifier');
            $table->string('status')->default('active');
            $table->bigInteger('created_by')->default(0);
            $table->bigInteger('updated_by')->default(0);
            $table->timestamps();
        });

        // New contact_metas table
        Schema::create('contact_metas', function (Blueprint $table) {
            $table->id();

            $table->foreignId('ref_parent')
                ->constrained('contacts')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();

            $table->string('meta_key');
            $table->longText('meta_value');
            $table->string('status')->default('active');
            $table->bigInteger('created_by')->default(0);
            $table->bigInteger('updated_by')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_metas');
        Schema::dropIfExists('contacts');
    }
};