<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('issued_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('policy_request_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('policy_number')->unique();
            $table->string('provider_reference')->nullable()->unique();
            $table->timestamp('issued_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('issued_policies');
    }
};
