<?php
// src/Infrastructure/Helpers/helpers_bootstrap.php
foreach (glob(__DIR__ . '/*.php') as $helperFile) {
    if ($helperFile === __DIR__ . '/helpers_bootstrap.php') {
        continue; // Skip the bootstrap file itself
    }
    require_once $helperFile;
}