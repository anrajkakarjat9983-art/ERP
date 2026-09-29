<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ReleaseController extends Controller
{
    private function authorize(Request $request): ?JsonResponse
    {
        $expected = (string) env('RELEASE_TOKEN');
        $provided = (string) $request->header('X-Release-Token');

        if ($expected === '' || ! hash_equals($expected, $provided)) {
            return response()->json(['ok' => false, 'error' => 'unauthorized'], 401);
        }

        return null;
    }

    public function ping(Request $request): JsonResponse
    {
        if ($denied = $this->authorize($request)) {
            return $denied;
        }

        $db = ['configured' => (bool) env('DB_URL'), 'connection' => env('DB_CONNECTION')];

        try {
            DB::connection()->getPdo();
            $db['reachable'] = true;
        } catch (Throwable $e) {
            $db['reachable'] = false;
            $db['error'] = $e->getMessage();
        }

        return response()->json(['ok' => true, 'db' => $db]);
    }

    public function migrate(Request $request): JsonResponse
    {
        if ($denied = $this->authorize($request)) {
            return $denied;
        }

        $log = [];
        Artisan::call('migrate', ['--force' => true], $log);
        $migrateOutput = trim(Artisan::output());

        $seeded = null;
        try {
            if (Schema::hasTable('users') && DB::table('users')->count() > 0) {
                $seeded = 'skipped (users already present)';
            } else {
                Artisan::call('db:seed', ['--force' => true]);
                $seeded = 'ran';
            }
        } catch (Throwable $e) {
            $seeded = 'failed: ' . $e->getMessage();
        }

        return response()->json([
            'ok' => true,
            'migrate' => $migrateOutput,
            'seed' => $seeded,
            'pending' => trim(Artisan::call('migrate:status') ? Artisan::output() : ''),
        ]);
    }
}
