<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class AppController extends Controller
{
    /**
     * Latest PRIMARY app version.
     *
     * Served at GET /api/app/version and consumed by the main
     * NF mobile APK (applicationId: com.nizamifarmsmobile). The APK that the
     * download_url points at MUST have this same applicationId, otherwise
     * Android will install it as a new app instead of upgrading in place.
     */
    public function getLatestVersion()
    {
        return response()->json([
            'success' => true,
            'version' => [
                'code' => 1570,                  // Current versionCode (increment this when releasing new version)
                'name' => '15.7.0',              // Current versionName (user-facing version)
                'download_url' => url('/apk/latest'),  // Serves APK directly with proper headers
                'release_notes' => 'Bug fixes and improvements.',
                'force_update' => true,        // Set to true to force users to update
                'min_supported_version' => 800  // Minimum versionCode that can still use the app
            ]
        ]);
    }

    /**
     * Latest QURBANI companion app version.
     *
     * Served at GET /api/app/version/qurbani and consumed ONLY by the
     * com.nizamifarmsmobile.qurbani APK. Its download_url points at
     * /apk/qurbani-latest which serves the .qurbani APK. Kept fully separate
     * from the primary tracker above so shipping one app never forces the
     * other to update (which is important since the Qurbani build is a
     * temporary event companion and rarely changes).
     *
     * The values here are maintained by build-production-apk-auto.bat when
     * the operator picks "Qurbani only" or "Both".
     */
    public function getLatestQurbaniVersion()
    {
        return response()->json([
            'success' => true,
            'version' => [
                'code' => 1060,                                    // Qurbani versionCode - bumped by build script
                'name' => '10.6.0-qurbani',                        // Qurbani versionName (suffixed by gradle)
                'download_url' => url('/apk/qurbani-latest'),     // Serves com.nizamifarmsmobile.qurbani APK
                'release_notes' => 'Qurbani companion app updates.',
                'force_update' => false,                          // Soft prompt - user can postpone
                'min_supported_version' => 800
            ]
        ]);
    }

    /**
     * Latest NF MESSAGES app version.
     *
     * Served at GET /api/app/version/messages and consumed ONLY by the
     * com.nizamifarmsmobile.messages APK (the dedicated WhatsApp-style
     * messaging app for management). Its download_url points at
     * /apk/messages-latest which serves the .messages APK.
     *
     * Same separation rationale as the Qurbani tracker above: the three APKs
     * have different applicationIds, so a "new version" of one would install
     * as a SIBLING of the others rather than an upgrade. Pointing this flavor
     * at the primary tracker would nag messaging users to install the Rider
     * app on top of themselves.
     *
     * The values here are maintained by build-production-apk-auto.bat when the
     * operator picks "MESSAGES only" or "ALL".
     *
     * NOTE: this file must stay BOM-free. A UTF-8 BOM makes the version
     * endpoint 500 (documented trap — the .bat writes it with
     * UTF8Encoding($false) for exactly this reason).
     */
    public function getLatestMessagesVersion()
    {
        return response()->json([
            'success' => true,
            'version' => [
                'code' => 1520,                                   // Messages versionCode - bumped by build script
                'name' => '15.2.0-messages',                      // Messages versionName (suffixed by gradle)
                'download_url' => url('/apk/messages-latest'),    // Serves com.nizamifarmsmobile.messages APK
                'release_notes' => 'NF Messages updates.',
                'force_update' => false,                          // Soft prompt - user can postpone
                'min_supported_version' => 800
            ]
        ]);
    }
}
