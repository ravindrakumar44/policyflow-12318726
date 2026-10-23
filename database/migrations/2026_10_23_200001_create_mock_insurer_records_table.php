<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mock_insurer_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('policy_request_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('scenario')->default('SUCCESS');
            $table->string('status')->default('NOT_SUBMITTED')->index();
            $table->string('provider_reference')->nullable()->unique();
            $table->string('policy_number')->nullable()->unique();
            $table->json('last_response')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mock_insurer_records');
    }
};
