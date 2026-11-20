<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Read-through cache of the alarms stored on each device. The device is the source of truth.
     */
    public function up(): void
    {
        Schema::create('alarms_cache', function (Blueprint $table) {
            $table->id();
            $table->string('device_id', 64);
            $table->unsignedInteger('alarm_id'); // ID assigned by the device
            $table->string('time', 5); // "HH:MM"
            $table->json('days'); // ISO weekdays, 1 = Monday ... 7 = Sunday
            $table->boolean('enabled')->default(true);
            $table->string('label', 64)->nullable();
            $table->json('extra')->nullable(); // optional hardware-dependent fields (snooze_min, sound, volume)
            $table->timestamp('synced_at');

            $table->foreign('device_id')->references('id')->on('devices')->cascadeOnDelete();
            $table->unique(['device_id', 'alarm_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alarms_cache');
    }
};
