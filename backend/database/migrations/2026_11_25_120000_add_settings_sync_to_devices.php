<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Device settings (name, tz, brightness, snooze_min) are saved here first and pushed to the
     * clock in the background, like alarms. `name` + `settings` are the user's desired state.
     */
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->string('settings_sync_status', 16)->default('synced'); // App\Enums\SyncStatus
            $table->json('settings_sync_error')->nullable(); // {code, message} of the last refusal
            $table->unsignedInteger('settings_revision')->default(1); // bumped on every user change
            $table->timestamp('settings_synced_at')->nullable();
            $table->index(['settings_sync_status', 'last_seen_at']);
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropIndex(['settings_sync_status', 'last_seen_at']);
            $table->dropColumn(['settings_sync_status', 'settings_sync_error', 'settings_revision', 'settings_synced_at']);
        });
    }
};
