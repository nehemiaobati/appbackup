<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../vendor/autoload.php';

try {
    \Dotenv\Dotenv::createImmutable(__DIR__ . '/../')->load();
} catch (\Throwable $e) {
    http_response_code(500);
    die("<!DOCTYPE html><html><head><title>Configuration Error</title><style>body{font-family:sans-serif;background-color:#f8d7da;color:#721c24;padding:2rem;text-align:center;}div{max-width:600px;margin:auto;background-color:#f5c6cb;border:1px solid #721c24;border-radius:0.25rem;padding:1.5rem;}</style></head><body><div><h1>Configuration Error</h1><p>The <code>.env</code> file is missing or could not be loaded. Please ensure it exists in the root directory and is readable.</p></div></body></html>");
}

require_once __DIR__ . '/../src/router.php';
