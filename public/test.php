<?php
/**
 * Production Error Diagnostics
 * Upload to: public/prod-debug.php
 */

echo "<h1>Production Diagnostics</h1>";
echo "<pre>";

// 1. Check var directory
echo "=== var/ Directory ===\n";
$var_path = __DIR__ . '/../var';
echo "var/ exists: " . (is_dir($var_path) ? '✓' : '✗') . "\n";
echo "var/ is writable: " . (is_writable($var_path) ? '✓' : '✗') . "\n\n";

// 2. Check log directory
echo "=== var/log/ Directory ===\n";
$log_path = $var_path . '/log';
if (!is_dir($log_path)) {
    echo "var/log/ DOES NOT EXIST - CREATING...\n";
    @mkdir($log_path, 0755, true);
    echo "Created: " . (is_dir($log_path) ? '✓' : '✗') . "\n";
} else {
    echo "var/log/ exists: ✓\n";
}
echo "var/log/ is writable: " . (is_writable($log_path) ? '✓' : '✗') . "\n\n";

// 3. Check cache directory
echo "=== var/cache/ Directory ===\n";
$cache_path = $var_path . '/cache';
if (is_dir($cache_path)) {
    echo "var/cache/ exists: ✓\n";
    echo "var/cache/ is writable: " . (is_writable($cache_path) ? '✓' : '✗') . "\n";
    
    // Check prod cache
    $prod_cache = $cache_path . '/prod';
    echo "var/cache/prod/ exists: " . (is_dir($prod_cache) ? '✓' : '✗') . "\n";
} else {
    echo "var/cache/ DOES NOT EXIST\n";
}
echo "\n";

// 4. Environment variables
echo "=== Environment ===\n";
echo "APP_ENV: " . ($_ENV['APP_ENV'] ?? getenv('APP_ENV') ?? 'NOT SET') . "\n";
echo "APP_DEBUG: " . ($_ENV['APP_DEBUG'] ?? getenv('APP_DEBUG') ?? 'NOT SET') . "\n\n";

// 5. File permissions
echo "=== File Permissions ===\n";
echo "Current user: " . get_current_user() . "\n";
echo "PHP version: " . phpversion() . "\n";
echo "PHP user: " . (function_exists('posix_getpwuid') ? posix_getpwuid(posix_geteuid())['name'] : 'N/A') . "\n\n";

// 6. Try to create test log
echo "=== Write Test ===\n";
$test_log = $log_path . '/test.txt';
if (@file_put_contents($test_log, 'test')) {
    echo "Can write to var/log/: ✓\n";
    @unlink($test_log);
} else {
    echo "Cannot write to var/log/: ✗ (PROBLEM!)\n";
}

// 7. List existing logs
echo "\n=== Existing Logs ===\n";
$files = @scandir($log_path);
if ($files) {
    foreach ($files as $file) {
        if ($file !== '.' && $file !== '..') {
            $size = filesize($log_path . '/' . $file);
            echo "$file (" . number_format($size) . " bytes)\n";
        }
    }
} else {
    echo "Cannot read var/log/ directory\n";
}

echo "\n</pre>";
?>