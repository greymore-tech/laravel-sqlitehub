<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('email')->unique();
            $table->string('name', 100)->nullable();
            $table->text('bio')->nullable();
            $table->integer('visits')->default(0);
            $table->bigInteger('points')->default(0);
            $table->float('rating')->nullable();
            $table->decimal('balance', 12, 2)->default(0);
            $table->boolean('active')->default(true);
            $table->date('born_on')->nullable();
            $table->dateTime('last_seen_at')->nullable();
            $table->json('settings')->nullable();
            $table->binary('avatar')->nullable();
            $table->enum('tier', ['free', 'pro'])->default('free');
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->timestamps();
            $table->index(['customer_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('posts');
        Schema::dropIfExists('customers');
    }
};
