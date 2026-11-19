<?php

// SpinupWP may deploy the checkout before Composer installs newly locked packages.
// Composer resolves installed classes first. The private archive supplies only its missing MFA classes.
$archive = 'phar://'.__DIR__.'/authenticator.phar/';
$prefixes = require $archive.'prefixes.php';
spl_autoload_register(static function (string $class) use ($archive, $prefixes) {
    foreach ($prefixes as $prefix => $directory) {
        if (str_starts_with($class, $prefix)) {
            $path = $archive.$directory.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
            if (is_file($path)) {
                require $path;
            }

            return;
        }
    }
});
