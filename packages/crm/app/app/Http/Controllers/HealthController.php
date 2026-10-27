<?php

namespace App\Http\Controllers;

/**
 * Session-free liveness probe used by the container healthcheck and by Coolify.
 *
 * Lives in routes/api.php (prefix `/api`) so it never touches the database,
 * the session store or the filesystem.
 */
class HealthController extends Controller
{
    public function __invoke()
    {
        return response()->json(['status' => 'ok']);
    }
}
