<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            // The device's own ID (reported by /api/info and the mDNS TXT record), not an auto-increment.
            $table->string('id', 64)->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32)->index();
            $table->string('name');
            $table->text('token'); // encrypted cast; ciphertext is much longer than the raw token
            $table->string('last_ip', 255)->nullable();
            $table->string('fw_version', 32)->nullable();
            $table->string('mac', 17)->nullable();
            $table->json('capabilities');
            $table->json('settings')->nullable(); // last known {tz, brightness, snooze_min}
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};
