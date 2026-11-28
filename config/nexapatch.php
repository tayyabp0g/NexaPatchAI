<?php

return [
    // Failed logins allowed before a temporary lockout (FR-007)
    'login_max_attempts' => (int) env('LOGIN_MAX_ATTEMPTS', 5),
    'login_lockout_seconds' => (int) env('LOGIN_LOCKOUT_SECONDS', 300),

    // Create demo users with the seeder (local development only)
    'seed_demo_users' => (bool) env('SEED_DEMO_USERS', false),

    // Maximum size of one uploaded file or ZIP (FR-010, NFR-013)
    'upload_max_mb' => (int) env('UPLOAD_MAX_MB', 50),

    'upload' => [
        // Only these file types are accepted as source code (and are kept when found inside a ZIP).
        'allowed_extensions' => [
            'php', 'phtml', 'inc', 'html', 'htm', 'css', 'js', 'json', 'sql',
            'txt', 'md', 'xml', 'yml', 'yaml', 'ini', 'lock', 'twig', 'tpl', 'env',
        ],
        // Special file names without a normal extension that are useful to scan.
        'allowed_filenames' => ['.htaccess', '.env', '.env.example'],

        // Folders inside a ZIP that are skipped (third-party or tool folders, not the customer's own code).
        'skip_dirs' => ['vendor', 'node_modules', '.git', '__macosx', '.idea', '.vscode'],

        // ZIP safety limits (zip bombs, huge archives)
        'max_entries' => (int) env('UPLOAD_MAX_ENTRIES', 2000),
        'max_extracted_mb' => (int) env('UPLOAD_MAX_EXTRACTED_MB', 200),
        'max_file_mb' => (int) env('UPLOAD_MAX_FILE_MB', 10),
        'max_ratio' => (int) env('UPLOAD_MAX_RATIO', 100),
    ],

    // Security scanner (Day 3)
    // "sync" = scan runs inside the request (no worker needed, best for local use)
    // "queue" = scan is queued; run "php artisan queue:work" (best for production)
    'scan_mode' => env('SCAN_MODE', 'sync'),
    'osv_enabled' => (bool) env('OSV_ENABLED', true),
    'external_scanners' => [
        'enabled' => (bool) env('SECURITY_TOOLS_ENABLED', true),
        'python_binary' => env('SECURITY_PYTHON', PHP_OS_FAMILY === 'Windows' ? 'python' : 'python3'),
        'semgrep_enabled' => (bool) env('SEMGREP_ENABLED', true),
        'semgrep_config' => env('SEMGREP_CONFIG', 'p/security-audit'),
        'gitleaks_enabled' => (bool) env('GITLEAKS_ENABLED', true),
        'timeout' => (int) env('SECURITY_TOOLS_TIMEOUT', 450),
        'max_findings' => (int) env('SCAN_MAX_FINDINGS', 3000),
    ],
    'scan' => [
        'max_file_kb' => (int) env('SCAN_MAX_FILE_KB', 2048),
        'max_findings' => (int) env('SCAN_MAX_FINDINGS', 3000),
    ],

    // Automatic fixing (Day 4)
    'fix' => [
        'max_patches' => (int) env('FIX_MAX_PATCHES', 400),
    ],

    // Optional AI helper for fixes that rules cannot do. Use a FREE tier on purpose:
    //  - LLM_PROVIDER=gemini  + GEMINI_API_KEY   (Google AI Studio free tier)
    //  - LLM_PROVIDER=openai  + LLM_BASE_URL/LLM_API_KEY/LLM_MODEL (Groq, OpenRouter free models, or local Ollama)
    'llm' => [
        'provider' => env('LLM_PROVIDER', 'none'),
        'gemini_key' => env('GEMINI_API_KEY'),
        'gemini_model' => env('GEMINI_MODEL', 'gemini-3.5-flash-lite'),
        'base_url' => env('LLM_BASE_URL', 'https://api.groq.com/openai/v1'),
        'api_key' => env('LLM_API_KEY'),
        'model' => env('LLM_MODEL', 'llama-3.3-70b-versatile'),
        'max_calls' => (int) env('LLM_MAX_CALLS', 30),
        'delay_ms' => (int) env('LLM_DELAY_MS', 4500),
        'timeout' => (int) env('LLM_TIMEOUT', 60),
        'ca_bundle' => env('CA_BUNDLE_PATH'),
    ],
];
