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

        $db = [
            'configured' => (bool) (env('DB_URL') ?: env('DATABASE_URL')),
            'connection' => env('DB_CONNECTION'),
        ];

        try {
            DB::connection()->getPdo();
            $db['reachable'] = true;
        } catch (Throwable $e) {
            $db['reachable'] = false;
            $db['error'] = $e->getMessage();
        }

        return response()->json(['ok' => true, 'db' => $db]);
    }

    public function check(Request $request): JsonResponse
    {
        if ($denied = $this->authorize($request)) {
            return $denied;
        }

        $db = [
            'configured' => (bool) (env('DB_URL') ?: env('DATABASE_URL')),
            'connection' => env('DB_CONNECTION'),
            'host' => config('database.connections.pgsql.host'),
            'driver' => config('database.default'),
        ];

        try {
            DB::connection()->getPdo();
            $db['reachable'] = true;
            $db['server_version'] = DB::selectOne('select version() as v')->v ?? null;
        } catch (Throwable $e) {
            $db['reachable'] = false;
            $db['error'] = $e->getMessage();
        }

        $info = [];
        try {
            $rows = DB::select("select table_name from information_schema.tables where table_schema = 'public' order by table_name");
            $info['table_count'] = count($rows);
            $info['tables'] = array_map(fn ($r) => $r->table_name, $rows);
            if (in_array('migrations', $info['tables'], true)) {
                $info['migrations_recorded'] = DB::table('migrations')->count();
            }
        } catch (Throwable $e) {
            $info['error'] = $e->getMessage();
        }

        return response()->json([
            'ok' => true,
            'php' => [
                'version' => PHP_VERSION,
                'memory_limit' => ini_get('memory_limit'),
                'memory_used_mb' => round(memory_get_peak_usage(true) / 1048576, 1),
                'max_execution_time' => ini_get('max_execution_time'),
                'pdo_pgsql' => extension_loaded('pdo_pgsql'),
            ],
            'db' => $db,
            'schema' => $info,
        ]);
    }

    /**
     * Drop every table in the public schema so migrations can start clean.
     *
     * A release that is killed part way through can leave a table behind
     * without its migration being recorded, and re-running then fails because
     * the table already exists. This is only safe on a database that holds no
     * real data, so it requires an explicit confirmation token.
     */
    public function reset(Request $request): JsonResponse
    {
        if ($denied = $this->authorize($request)) {
            return $denied;
        }

        if ($request->query('confirm') !== 'RESET') {
            return response()->json([
                'ok' => false,
                'error' => 'refusing to reset without ?confirm=RESET',
            ], 400);
        }

        try {
            DB::statement('drop schema public cascade');
            DB::statement('create schema public');
            DB::statement('grant all on schema public to public');

            $tables = DB::select("select table_name from information_schema.tables where table_schema = 'public'");

            return response()->json([
                'ok' => true,
                'reset' => true,
                'remaining_tables' => count($tables),
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'ok' => false,
                'stage' => 'reset',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Migrate a limited number of pending migrations per request.
     *
     * A serverless request has a hard time limit, so running all ~90 migrations
     * in one call can be killed part way through and return an empty 500. Each
     * migration runs as its own transaction and is recorded as it succeeds, so
     * the caller can simply repeat the call until `remaining` reaches zero.
     */
    public function migrate(Request $request): JsonResponse
    {
        if ($denied = $this->authorize($request)) {
            return $denied;
        }

        $limit = max(1, min(50, (int) $request->query('limit', 8)));
        $seedWhenDone = $request->boolean('seed');

        try {
            $applied = Schema::hasTable('migrations')
                ? array_flip(DB::table('migrations')->pluck('migration')->all())
                : [];

            $pending = [];
            foreach (glob(database_path('migrations/*.php')) ?: [] as $file) {
                $name = basename($file, '.php');
                if (! isset($applied[$name])) {
                    $pending[] = basename($file);
                }
            }
            sort($pending);

            $ran = [];
            foreach (array_slice($pending, 0, $limit) as $file) {
                try {
                    // The --path option is resolved from the application root,
                    // so it must include the database/ segment.
                    Artisan::call('migrate', [
                        '--force' => true,
                        '--path' => 'database/migrations/' . $file,
                    ]);
                    $ran[] = $file;
                } catch (Throwable $e) {
                    return response()->json([
                        'ok' => false,
                        'stage' => 'migrate',
                        'failed_file' => $file,
                        'error' => $e->getMessage(),
                        'at' => basename($e->getFile()) . ':' . $e->getLine(),
                        'ran_this_call' => $ran,
                        'remaining' => max(0, count($pending) - count($ran)),
                    ], 500);
                }
            }

            $remaining = max(0, count($pending) - count($ran));
            $result = [
                'ok' => true,
                'ran_this_call' => $ran,
                'ran_count' => count($ran),
                'remaining' => $remaining,
                'total_pending_at_start' => count($pending),
                'done' => $remaining === 0,
                'memory_used_mb' => round(memory_get_peak_usage(true) / 1048576, 1),
            ];

            if ($seedWhenDone && $remaining === 0) {
                try {
                    if (Schema::hasTable('users') && DB::table('users')->count() > 0) {
                        $result['seed'] = 'skipped (users already present)';
                    } else {
                        Artisan::call('db:seed', ['--force' => true]);
                        $result['seed'] = 'ran';
                    }
                } catch (Throwable $e) {
                    $result['ok'] = false;
                    $result['stage'] = 'seed';
                    $result['seed'] = 'failed: ' . $e->getMessage();
                }
            }

            return response()->json($result);
        } catch (Throwable $e) {
            return response()->json([
                'ok' => false,
                'stage' => 'migrate-setup',
                'error' => $e->getMessage(),
                'at' => basename($e->getFile()) . ':' . $e->getLine(),
            ], 500);
        }
    }
}
