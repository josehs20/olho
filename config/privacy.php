<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Retenção (dias) por categoria — configuração de produto, ajustável.
    |--------------------------------------------------------------------------
    */
    'retention_days' => [
        'investigations' => (int) env('RETENTION_INVESTIGATIONS_DAYS', 365),
        'reports' => (int) env('RETENTION_REPORTS_DAYS', 365),
        'source_results' => (int) env('RETENTION_RESULTS_DAYS', 365),
        'logs' => (int) env('RETENTION_LOGS_DAYS', 30),
        'audit' => (int) env('RETENTION_AUDIT_DAYS', 1825),
        'financial' => (int) env('RETENTION_FINANCIAL_DAYS', 1825),
    ],
];
