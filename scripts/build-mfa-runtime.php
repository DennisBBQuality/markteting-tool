<?php

// Rebuild with php -d phar.readonly=0 scripts/build-mfa-runtime.php after composer install.
// Only these four locked packages, their source and licences belong in the release archive.
$root = dirname(__DIR__);
$archivePath = $root.'/resources/mfa/authenticator.phar';
$temp = sys_get_temp_dir().'/pitboard-mfa-'.bin2hex(random_bytes(6)).'.phar';
$archive = new Phar($temp);
$archive->startBuffering();
$archive->setStub('<?php __HALT_COMPILER(); ?>');
$archive->setSignatureAlgorithm(Phar::SHA256);
$packages = ['pragmarx/google2fa', 'paragonie/constant_time_encoding', 'bacon/bacon-qr-code', 'dasprid/enum'];
$prefixes = [];
foreach ($packages as $package) {
    $dir = $root.'/vendor/'.$package;
    $manifest = json_decode(file_get_contents($dir.'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
    foreach ($manifest['autoload']['psr-4'] as $prefix => $path) {
        $prefixes[$prefix] = $package.'/'.rtrim($path, '/').'/';
    }
    $paths = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir.'/src')) as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $paths[] = $file->getPathname();
        }
    }
    $paths = array_merge($paths, glob($dir.'/LICENSE*'), [$dir.'/composer.json']);
    sort($paths);
    foreach ($paths as $path) {
        $archive->addFromString($package.'/'.substr($path, strlen($dir) + 1), file_get_contents($path));
    }
}
$archive->addFromString('prefixes.php', '<?php return '.var_export($prefixes, true).';');
$archive->stopBuffering();
rename($temp, $archivePath);
