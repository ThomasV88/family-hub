<?php

// Tests must never touch the dev database. PHPUnit's <env force="true"> values land in $_ENV and
// putenv(), but Laravel reads $_SERVER first, and Docker's env_file values (the dev DB credentials)
// are already in $_SERVER. Mirror the forced values into $_SERVER before the app boots.
require __DIR__.'/../vendor/autoload.php';

foreach ($_ENV as $key => $value) {
    if (isset($_SERVER[$key]) && $_SERVER[$key] !== $value) {
        $_SERVER[$key] = $value;
    }
}
