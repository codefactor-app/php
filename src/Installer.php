<?php
declare(strict_types=1);

namespace Codefactor;

final class Installer
{
    public static function platform(): string
    {
        $os = match (PHP_OS_FAMILY) {
            'Linux' => 'linux', 'Darwin' => 'darwin', 'Windows' => 'win32',
            default => throw new \RuntimeException('Unsupported Codefactor operating system'),
        };
        $arch = match (strtolower(php_uname('m'))) {
            'x86_64', 'amd64' => 'x64', 'aarch64', 'arm64' => 'arm64',
            default => throw new \RuntimeException('Unsupported Codefactor architecture'),
        };
        return "$os-$arch";
    }

    public static function binaryPath(): string
    {
        return dirname(__DIR__) . '/libexec/codefactor' . (PHP_OS_FAMILY === 'Windows' ? '.exe' : '');
    }

    private static function releaseBase(array $config, string $version): string
    {
        $override = getenv('CODEFACTOR_RELEASE_BASE_URL');
        if ($version === 'latest' && !$override) {
            return rtrim($config['latest_url'], '/');
        }
        $base = rtrim($override ?: $config['base_url'], '/');
        return str_contains($base, '{version}') ? str_replace('{version}', $version, $base) : $base . '/' . $version;
    }

    public static function install(?string $token = null): string
    {
        $root = dirname(__DIR__);
        $destination = self::binaryPath();
        if (!is_dir(dirname($destination)) && !mkdir(dirname($destination), 0755, true)) {
            throw new \RuntimeException('Cannot create Codefactor binary directory');
        }
        $lock = fopen($root . '/libexec/.install.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new \RuntimeException('Cannot lock Codefactor installation');
        }
        $temporary = null;
        try {
            $config = json_decode(file_get_contents($root . '/release.json'), true, 512, JSON_THROW_ON_ERROR);
            $override = getenv('CODEFACTOR_BINARY');
            if ($override !== false && $override !== '') {
                if (!is_file($override)) {
                    throw new \RuntimeException('CODEFACTOR_BINARY does not point to a file');
                }
                if (realpath($override) !== realpath($destination)) {
                    $temporary = tempnam(dirname($destination), '.codefactor-');
                    if ($temporary === false || !copy($override, $temporary) || !chmod($temporary, 0755) || !rename($temporary, $destination)) {
                        throw new \RuntimeException('Cannot copy CODEFACTOR_BINARY');
                    }
                }
                return $destination;
            }
            $platform = self::platform();
            if (!in_array($platform, $config['platforms'], true)) {
                throw new \RuntimeException("No Codefactor release for $platform");
            }
            $metadata = $root . '/libexec/codefactor.manifest.json';
            $base = self::releaseBase($config, $config['version']);
            $token ??= getenv('CODEFACTOR_TOKEN') ?: null;
            $manifest = json_decode(self::download("$base/manifest-$platform.json", $token, 65536), true, 512, JSON_THROW_ON_ERROR);
            $filename = "codefactor-$platform" . (PHP_OS_FAMILY === 'Windows' ? '.exe' : '');
            $version = $manifest['version'] ?? null;
            if (!is_string($version) || !preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/D', $version)
                || ($config['version'] !== 'latest' && $version !== $config['version']) || ($manifest['platform'] ?? '') !== $platform
                || ($manifest['file'] ?? '') !== $filename || !preg_match('/^[a-f0-9]{64}$/', $manifest['sha256'] ?? '')) {
                throw new \RuntimeException('Invalid Codefactor release manifest');
            }
            if (is_file($destination) && filesize($destination) === ($manifest['size'] ?? -1)
                && hash_file('sha256', $destination) === $manifest['sha256']) {
                chmod($destination, 0755);
                file_put_contents($metadata, json_encode($manifest, JSON_THROW_ON_ERROR));
                return $destination;
            }
            $base = self::releaseBase($config, $version);
            $binary = self::download("$base/$filename", $token, 128 * 1024 * 1024);
            if (strlen($binary) !== ($manifest['size'] ?? -1) || hash('sha256', $binary) !== $manifest['sha256']) {
                throw new \RuntimeException('Codefactor binary checksum mismatch');
            }
            $temporary = tempnam(dirname($destination), '.codefactor-');
            if ($temporary === false || file_put_contents($temporary, $binary) === false || !chmod($temporary, 0755)
                || !rename($temporary, $destination)) {
                throw new \RuntimeException('Cannot install Codefactor binary');
            }
            file_put_contents($metadata, json_encode($manifest, JSON_THROW_ON_ERROR));
            return $destination;
        } finally {
            if ($temporary !== null && is_file($temporary)) {
                unlink($temporary);
            }
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private static function download(string $url, ?string $token, int $limit): string
    {
        if (!filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
            throw new \RuntimeException('Enable PHP allow_url_fopen to download Codefactor releases, or set CODEFACTOR_BINARY to a local binary');
        }
        if ($token !== null && strpbrk($token, "\r\n") !== false) {
            throw new \InvalidArgumentException('Invalid Codefactor token');
        }
        $ssl = ['verify_peer' => true, 'verify_peer_name' => true];
        $ca = getenv('CODEFACTOR_CA_BUNDLE');
        if ($ca) {
            $ssl['cafile'] = $ca;
        } elseif (parse_url($url, PHP_URL_HOST) === 'gitlab.local') {
            $ssl['cafile'] = dirname(__DIR__) . '/certs/gitlab.local.pem';
        }
        $jobToken = getenv('CODEFACTOR_JOB_TOKEN') ?: getenv('CI_JOB_TOKEN');
        $header = parse_url($url, PHP_URL_HOST) === 'github.com' ? ''
            : ($token ? "PRIVATE-TOKEN: $token\r\n" : ($jobToken ? "JOB-TOKEN: $jobToken\r\n" : ''));
        for ($redirects = 0; $redirects <= 5; $redirects++) {
            if (parse_url($url, PHP_URL_SCHEME) !== 'https') {
                throw new \RuntimeException('Codefactor release URL must use HTTPS');
            }
            $context = stream_context_create([
                'ssl' => $ssl,
                'http' => ['timeout' => 60, 'follow_location' => 0, 'header' => $header],
            ]);
            $stream = @fopen($url, 'rb', false, $context);
            if ($stream === false) {
                throw new \RuntimeException('Cannot download Codefactor release; check CODEFACTOR_TOKEN, TLS certificate and published platform');
            }
            try {
                $response = stream_get_meta_data($stream)['wrapper_data'];
                if (preg_match('/^HTTP\/\S+ (301|302|303|307|308)\b/', $response[0])) {
                    if ($header !== '' || $redirects === 5) {
                        throw new \RuntimeException('Unsafe Codefactor release redirect');
                    }
                    foreach ($response as $line) {
                        if (stripos($line, 'Location:') === 0) {
                            $url = trim(substr($line, 9));
                            continue 2;
                        }
                    }
                    throw new \RuntimeException('Codefactor redirect has no location');
                }
                if (!preg_match('/^HTTP\/\S+ 200\b/', $response[0])) {
                    throw new \RuntimeException('Codefactor download failed');
                }
                $data = stream_get_contents($stream, $limit + 1);
                if ($data === false || strlen($data) > $limit) {
                    throw new \RuntimeException('Codefactor download exceeded its size limit');
                }
                return $data;
            } finally {
                fclose($stream);
            }
        }
        throw new \RuntimeException('Too many Codefactor release redirects');
    }
}
