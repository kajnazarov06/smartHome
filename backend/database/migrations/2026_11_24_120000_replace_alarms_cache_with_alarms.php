<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Alarms are now stored here first (the user's desired state) and pushed to the clock
     * in the background, instead of being a read-through cache of the clock.
     */
    public function up(): void
    {
        Schema::create('alarms', function (Blueprint $table) {
            $table->id(); // the alarm ID the API exposes
            $table->string('device_id', 64);
            $table->unsignedInteger('device_alarm_id')->nullable(); // ID assigned by the clock; null until it accepted the alarm
            $table->string('time', 5); // "HH:MM"
            $table->json('days'); // ISO weekdays, 1 = Monday ... 7 = Sunday
            $table->boolean('enabled')->default(true);
            $table->string('label', 64)->nullable();
            $table->json('extra')->nullable(); // optional hardware-dependent fields (snooze_min, sound, volume)
            $table->string('sync_status', 16)->default('pending'); // App\Enums\AlarmSyncStatus
            $table->json('sync_error')->nullable(); // {code, message} of the last refusal
            $table->unsignedInteger('revision')->default(1); // bumped on every user change; guards against stale sync writes
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
            $table->softDeletes(); // a deleted alarm stays until the clock has deleted it too

            $table->foreign('device_id')->references('id')->on('devices')->cascadeOnDelete();
            $table->unique(['device_id', 'device_alarm_id']);
            $table->index(['sync_status', 'device_id']);
        });

        $now = now();
        foreach (DB::table('alarms_cache')->orderBy('id')->get() as $row) {
            DB::table('alarms')->insert([
                'device_id' => $row->device_id,
                'device_alarm_id' => $row->alarm_id,
                'time' => $row->time,
                'days' => $row->days,
                'enabled' => $row->enabled,
                'label' => $row->label,
                'extra' => $row->extra,
                'sync_status' => 'synced',
                'synced_at' => $row->synced_at,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        Schema::drop('alarms_cache');
    }

    public function down(): void
    {
        Schema::create('alarms_cache', function (Blueprint $table) {
            $table->id();
            $table->string('device_id', 64);
            $table->unsignedInteger('alarm_id');
            $table->string('time', 5);
            $table->json('days');
            $table->boolean('enabled')->default(true);
            $table->string('label', 64)->nullable();
            $table->json('extra')->nullable();
            $table->timestamp('synced_at');

            $table->foreign('device_id')->references('id')->on('devices')->cascadeOnDelete();
            $table->unique(['device_id', 'alarm_id']);
        });

        $rows = DB::table('alarms')->whereNull('deleted_at')->whereNotNull('device_alarm_id')->get();
        foreach ($rows as $row) {
            DB::table('alarms_cache')->insert([
                'device_id' => $row->device_id,
                'alarm_id' => $row->device_alarm_id,
                'time' => $row->time,
                'days' => $row->days,
                'enabled' => $row->enabled,
                'label' => $row->label,
                'extra' => $row->extra,
                'synced_at' => $row->synced_at ?? now(),
            ]);
        }

        Schema::drop('alarms');
    }
};
