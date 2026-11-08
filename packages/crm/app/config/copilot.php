<?php

return [

    'enabled' => (bool) env('COPILOT_ENABLED', true),

    // The analyst can be upgraded independently from the WhatsApp agent.
    'model' => env('COPILOT_MODEL', env('AGENT_MODEL', 'anthropic/claude-haiku-5.5')),

    'timezone' => env('COPILOT_TIMEZONE', 'America/Sao_Paulo'),

    'daily_time' => env('COPILOT_DAILY_TIME', '08:00'),
    'weekly_day' => (int) env('COPILOT_WEEKLY_DAY', 1), // 1 = Monday
    'weekly_time' => env('COPILOT_WEEKLY_TIME', '08:10'),

    'mail' => (bool) env('COPILOT_MAIL', true),

    // Comma-separated staff e-mails. Empty = all users with role=staff.
    'recipients' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('COPILOT_RECIPIENTS', '')),
    ))),

    'windows' => [
        'ops_hours' => (int) env('COPILOT_OPS_HOURS', 24),
        'calibration_days' => (int) env('COPILOT_CALIBRATION_DAYS', 7),
        'gates_days' => (int) env('COPILOT_GATES_DAYS', 30),
    ],

    'thresholds' => [
        'stuck_draft_hours' => 24,
        'stuck_replied_hours' => 2,
        'queue_warning_minutes' => 10,
        'handoff_warning_hours' => 1,
        'handoff_critical_hours' => 4,
    ],

    'max_findings' => 6,
];
