<?php
// Proxy endpoint to allow hardware devices (server.py) to access core/log.php
// since the root .htaccess blocks direct HTTP access to the core/ directory.

require_once __DIR__ . '/../../core/log.php';
