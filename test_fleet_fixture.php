<?php
/**
 * SHARED FIXTURE for the older fleet regression scripts (5-Oct-2026).
 *
 * Those scripts were written in Aug/Sep against the maintenance set-up of the day, and they
 * went red when the SET-UP changed, not the code:
 *   • the jobs were renamed (labels now carry an icon / "?" suffix), and they look jobs up by
 *     their old exact names;
 *   • "Oil Change" (id 1) became a VAN-only job at 3,000 km and a separate bike "Oil Change"
 *     (id 7) was added;
 *   • the van was given its own override (Oil Change every 1,000 km) on 10-Sep.
 *
 * A test of a RULE must not depend on this month's configuration. `fleetFixtureBegin()` opens
 * ONE outer transaction and puts the configuration back to the shape the scripts assume;
 * everything the script then does (including its own nested transactions — savepoints) runs
 * inside it, and it is rolled back when the script ends. Nothing is ever committed.
 *
 * Usage, right after the app is booted:
 *     require __DIR__ . '/test_fleet_fixture.php';
 *     fleetFixtureBegin();
 */

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function fleetFixtureBegin(): void
{
    DB::beginTransaction();
    register_shutdown_function(function () {
        try { while (DB::transactionLevel() > 0) DB::rollBack(); } catch (\Throwable $e) {}
        try { Cache::flush(); } catch (\Throwable $e) {}
    });

    $t = 't_fleet_maintenance_types';
    $van = Schema::hasColumn($t, 'interval_km_van');

    // The two oil jobs as the scripts know them: one "Oil Change" for every class, one
    // "Oil + Tuning" above it. The later bike-only duplicate is parked for the run.
    DB::table($t)->where('id', 1)->update(array_merge(
        ['type_name' => 'Oil Change', 'interval_km' => 1000, 'resets_service_clock' => 1, 'is_active' => 1],
        $van ? ['applies_to' => 'both', 'basis' => 'km', 'interval_km_van' => 5000] : []));
    DB::table($t)->where('id', 2)->update(array_merge(
        ['type_name' => 'Oil + Tuning', 'interval_km' => 2000, 'resets_service_clock' => 1, 'is_active' => 1],
        $van ? ['applies_to' => 'bike', 'basis' => 'km'] : []));
    DB::table($t)->where('id', 3)->update(['type_name' => 'Brake Shoe', 'interval_km' => 10000, 'is_active' => 1]);
    DB::table($t)->where('id', 7)->update(['is_active' => 0]);

    // No machine carries its own schedule unless a script gives it one.
    if (Schema::hasTable('t_ops_vehicle_service_schedule')) {
        DB::table('t_ops_vehicle_service_schedule')->delete();
    }

    \App\Services\Riders\VehicleService::bumpServiceConfig();
    \App\Services\Riders\VehicleService::flushServiceMemo();
    try { Cache::flush(); } catch (\Throwable $e) {}
}
