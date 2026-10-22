<?php

// Populate Composer's standard archive cache from GitHub's exact-commit archive host.
// This also works in environments that allow codeload but block api.github.com.
$lock = json_decode(file_get_contents('composer.lock'), true, flags: JSON_THROW_ON_ERROR);
$cache = trim(shell_exec('composer config cache-files-dir'));
foreach ($lock['packages'] as $package) {
    $dist = $package['dist'] ?? [];
    if (! preg_match('~^https://api.github.com/repos/(.+)/zipball/(.+)$~', $dist['url'] ?? '', $match)) {
        continue;
    }
    $path = $cache.'/'.$package['name'].'/'.sha1($dist['url']).'.zip';
    if (is_file($path)) {
        continue;
    }
    @mkdir(dirname($path), 0777, true);
    $out = fopen($path, 'wb');
    $curl = curl_init('https://codeload.github.com/'.$match[1].'/legacy.zip/'.$match[2]);
    curl_setopt_array($curl, [CURLOPT_FILE => $out, CURLOPT_FOLLOWLOCATION => true, CURLOPT_FAILONERROR => true, CURLOPT_TIMEOUT => 120]);
    if (getenv('COMPOSER_CAFILE')) {
        curl_setopt($curl, CURLOPT_CAINFO, getenv('COMPOSER_CAFILE'));
    }
    $ok = curl_exec($curl);
    fclose($out);
    if (! $ok || (! empty($dist['shasum']) && sha1_file($path) !== $dist['shasum'])) {
        @unlink($path);
        throw new RuntimeException('Failed to obtain verified archive for '.$package['name']);
    }
}
