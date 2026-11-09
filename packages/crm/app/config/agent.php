<?php

return [

    /*
    |--------------------------------------------------------------------------
    | WhatsApp agent
    |--------------------------------------------------------------------------
    |
    | The Beatriz SDR runs inside the CRM. n8n is no longer on the message path.
    | The webhook receives WAHA events, the buffer RPCs handle debounce, and a
    | queued job runs the grounded turn.
    |
    */

    'webhook_token' => env('WAHA_WEBHOOK_TOKEN', env('CRM_INTERNAL_TOKEN')),

    'queue' => env('AGENT_QUEUE', 'agent'),

    // Retrospective mock drafts run on a separate worker so they never block
    // live WhatsApp turns.
    'mock_queue' => env('AGENT_MOCK_QUEUE', 'mock'),

    // Simulate a human pause/typing cadence before sending. Disabled by the
    // simulator and the test suite so a scenario run stays fast.
    'humanize' => (bool) env('AGENT_HUMANIZE', true),

    'debounce' => [
        // Answer only after this much silence (no new message, no typing).
        'quiet_ms' => (int) env('AGENT_QUIET_MS', 6000),
        // Never hold a turn longer than this, even if presence is noisy.
        'max_wait_ms' => (int) env('AGENT_MAX_WAIT_MS', 20000),
        // Safety cap for a lost "paused" presence event.
        'typing_grace_ms' => (int) env('AGENT_TYPING_GRACE_MS', 30000),
    ],

    'models' => [
        'preflight' => env('AGENT_PREFLIGHT_MODEL', env('AGENT_MODEL', 'anthropic/claude-haiku-5.5')),
        'agent' => env('AGENT_MODEL', 'anthropic/claude-haiku-5.5'),
        'claims' => env('AGENT_CLAIMS_MODEL', env('AGENT_MODEL', 'anthropic/claude-haiku-5.5')),
        'repair' => env('AGENT_REPAIR_MODEL', env('AGENT_MODEL', 'anthropic/claude-haiku-5.5')),
        'eval' => env('AGENT_EVAL_MODEL', env('AGENT_MODEL', 'anthropic/claude-haiku-5.5')),
        'audio' => env('AGENT_AUDIO_MODEL', 'google/gemini-2.5-flash'),
        'vision' => env('AGENT_VISION_MODEL', 'google/gemini-2.5-flash'),
        'judge' => env('AGENT_JUDGE_MODEL', env('AGENT_MODEL', 'anthropic/claude-haiku-5.5')),
    ],

    'calibration' => [
        'min_compared' => (int) env('AGENT_CALIBRATION_MIN_COMPARED', 40),
        'min_acceptable_rate' => (float) env('AGENT_CALIBRATION_MIN_ACCEPTABLE', 0.9),
        'min_response_rate' => (float) env('AGENT_CALIBRATION_MIN_RESPONSE', 0.9),
        'min_per_intent' => (int) env('AGENT_CALIBRATION_MIN_PER_INTENT', 5),
        'core_intents' => [
            'ask_price' => 5,
            'ask_availability' => 5,
            'qualify' => 5,
            'objection' => 5,
            'close' => 5,
            'reschedule' => 5,
            'aftercare' => 5,
            'coverup' => 5,
        ],
    ],

    'openrouter' => [
        'url' => rtrim((string) env('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'), '/'),
        'key' => env('OPENROUTER_API_KEY'),
        'timeout' => (int) env('AGENT_AI_TIMEOUT', 30),
        'retries' => (int) env('AGENT_AI_RETRIES', 2),
    ],

    'eval' => [
        'enabled' => (bool) env('AGENT_EVAL_ENABLED', false),
        'scenarios' => base_path('tests/Agent/Scenarios'),
        'output' => storage_path('app/agent-evals'),
    ],

];
