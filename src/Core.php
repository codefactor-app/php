<?php
declare(strict_types=1);
namespace Codefactor;

/** Project-local engine selection, shared by the CLI and analysis API. */
final class Core
{
    private const VALUE_OPTIONS = ['--config', '--file', '--root', '--format', '--out', '--lang', '--limit', '--group-by', '--function', '--from', '--to', '--depth', '--max-paths', '--architecture-profile', '--log-level', '--color', '--port', '--host', '--a', '--b', '--category'];

    public static function context(array $args): array
    {
        $config = $root = null;
        $positional = [];
        for ($i = 0; $i < count($args); $i++) {
            $arg = $args[$i];
            if ($arg === '--') { $positional = [...$positional, ...array_slice($args, $i + 1)]; break; }
            if (str_starts_with($arg, '--')) {
                $pair = explode('=', $arg, 2);
                if (in_array($pair[0], self::VALUE_OPTIONS, true)) {
                    $value = $pair[1] ?? ($args[++$i] ?? null);
                    if ($pair[0] === '--config' || ($pair[0] === '--file' && ($positional[0] ?? '') === 'config')) { $config = $value; }
                    if ($pair[0] === '--root') { $root = $value; }
                }
            } elseif (!str_starts_with($arg, '-')) { $positional[] = $arg; }
        }
        if ($root === null && in_array($positional[0] ?? '', ['scan', 'metrics', 'serve', 'ast'], true)) { $root = $positional[1] ?? null; }
        if ($config === null && ($positional[0] ?? '') === 'config' && in_array($positional[1] ?? '', ['check', 'show'], true)) { $config = 'analyzer.yaml'; }
        return ['config' => $config, 'root' => $root];
    }

    private static function absolute(string $path): string
    {
        return realpath($path) ?: ((str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path)) ? $path : getcwd() . '/' . $path);
    }

    private static function yaml(string $file): mixed
    {
        // Composer registers dependency loaders for plugins even with --no-autoloader.
        // Direct vendor/bin execution also needs to work before an autoload dump.
        if (!class_exists(\Symfony\Component\Yaml\Yaml::class)) {
            foreach ([dirname(__DIR__) . '/vendor', dirname(__DIR__, 3)] as $vendor) {
                if (is_file($vendor . '/symfony/yaml/Yaml.php')) {
                    spl_autoload_register(static function (string $class) use ($vendor): void {
                        $prefix = 'Symfony\\Component\\Yaml\\';
                        if (str_starts_with($class, $prefix)) {
                            $path = $vendor . '/symfony/yaml/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
                            if (is_file($path)) { require_once $path; }
                        }
                    });
                    if (is_file($vendor . '/symfony/deprecation-contracts/function.php')) { require_once $vendor . '/symfony/deprecation-contracts/function.php'; }
                    if (is_file($vendor . '/symfony/polyfill-ctype/bootstrap.php')) { require_once $vendor . '/symfony/polyfill-ctype/bootstrap.php'; }
                    break;
                }
            }
        }
        try { return \Symfony\Component\Yaml\Yaml::parseFile($file, \Symfony\Component\Yaml\Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE | \Symfony\Component\Yaml\Yaml::PARSE_OBJECT_FOR_MAP); }
        catch (\Throwable $error) { throw new \RuntimeException('Codefactor failed (3): ' . $error->getMessage(), 3, $error); }
    }

    public static function selection(?string $config = null, ?string $root = null): array
    {
        $directory = self::absolute($root ?? '.');
        if (is_file($directory)) { $directory = dirname($directory); }
        if ($config !== null) { $config = self::absolute($config); }
        else {
            foreach (['analyzer.yaml', 'analyzer.yml'] as $name) {
                if (is_file($directory . '/' . $name)) { $config = $directory . '/' . $name; break; }
            }
        }
        if ($config !== null) { $config = self::absolute($config); }
        $version = getenv('CODEFACTOR_CORE_VERSION');
        if ($config !== null) {
            $document = self::yaml($config);
            if ($document !== null && !($document instanceof \stdClass)) { throw new \RuntimeException('Codefactor failed (3): config must be a mapping', 3); }
            $document = $document === null ? [] : (array) $document;
            if ($version === false && is_array($document) && array_key_exists('core', $document)) {
                if (!($document['core'] instanceof \stdClass)) { throw new \RuntimeException('Codefactor failed (3): core must be a mapping', 3); }
                $document['core'] = (array) $document['core'];
                $version = array_key_exists('version', $document['core']) ? $document['core']['version'] : 'latest';
            }
        }
        $version = $version === false ? 'latest' : $version;
        if (!is_string($version) || !preg_match('/^(latest|[0-9]+\.[0-9]+\.[0-9]+)$/D', $version)) { throw new \RuntimeException('Codefactor failed (3): core.version must be latest or X.Y.Z', 3); }
        $release = json_decode(file_get_contents(dirname(__DIR__) . '/release.json'), true, 512, JSON_THROW_ON_ERROR);
        $base = getenv('CODEFACTOR_RELEASE_BASE_URL') ?: $release['base_url'];
        $latest = getenv('CODEFACTOR_RELEASE_BASE_URL') ? '' : $release['latest_url'];
        $source = hash('sha256', rtrim($base, '/') . "\n" . rtrim($latest, '/'));
        return [$version, ($config !== null ? dirname($config) : $directory) . '/.codefactor/core/' . $source . '/' . Installer::platform()];
    }

    private static function validate(array $manifest, string $version = 'latest'): array
    {
        $platform = Installer::platform();
        if (!is_string($manifest['version'] ?? null) || !preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/D', $manifest['version'])
            || ($version !== 'latest' && $manifest['version'] !== $version) || ($manifest['platform'] ?? '') !== $platform
            || ($manifest['file'] ?? '') !== "codefactor-$platform" . (PHP_OS_FAMILY === 'Windows' ? '.exe' : '')
            || !is_string($manifest['sha256'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $manifest['sha256'])
            || !is_int($manifest['size'] ?? null) || $manifest['size'] <= 0 || $manifest['size'] > 128 * 1024 * 1024) { throw new \RuntimeException('Invalid Codefactor release manifest'); }
        return $manifest;
    }

    private static function cachedPath(string $directory, array $manifest): string
    {
        return $directory . '/' . $manifest['version'] . '/' . $manifest['sha256'] . '/codefactor' . (PHP_OS_FAMILY === 'Windows' ? '.exe' : '');
    }

    private static function verified(string $binary, array $manifest): bool
    {
        return is_file($binary) && filesize($binary) === $manifest['size'] && hash_file('sha256', $binary) === $manifest['sha256'];
    }

    public static function resolve(?string $config = null, ?string $root = null): string
    {
        if ($override = getenv('CODEFACTOR_BINARY')) { return self::absolute($override); }
        [$version, $directory] = self::selection($config, $root);
        if (is_file($directory . '/active.json')) {
            $manifest = self::validate(json_decode(file_get_contents($directory . '/active.json'), true, 512, JSON_THROW_ON_ERROR));
            if ($version === 'latest' || $manifest['version'] === $version) {
                $binary = self::cachedPath($directory, $manifest);
                if (!self::verified($binary, $manifest)) { throw new \RuntimeException('Codefactor cached binary is damaged; run codefactor core update'); }
                return $binary;
            }
        }
        if ($version !== 'latest') {
            foreach (glob($directory . '/' . $version . '/*/codefactor.manifest.json') ?: [] as $metadata) {
                $manifest = self::validate(json_decode(file_get_contents($metadata), true, 512, JSON_THROW_ON_ERROR), $version);
                $binary = self::cachedPath($directory, $manifest);
                if (basename(dirname($metadata)) === $manifest['sha256'] && self::verified($binary, $manifest)) { return $binary; }
            }
        }
        $binary = Installer::binaryPath();
        if (is_file($binary)) {
            if ($version === 'latest') { return $binary; }
            $metadata = dirname($binary) . '/codefactor.manifest.json';
            if (is_file($metadata)) {
                $manifest = self::validate(json_decode(file_get_contents($metadata), true, 512, JSON_THROW_ON_ERROR));
                if ($manifest['version'] === $version && self::verified($binary, $manifest)) { return $binary; }
            }
        }
        throw new \RuntimeException("Codefactor Core $version is not installed; run codefactor core update" . ($config !== null ? " --config $config" : ''));
    }

    public static function update(?string $config = null): array
    {
        if ($override = getenv('CODEFACTOR_BINARY')) {
            if (!is_file($override)) { throw new \RuntimeException('CODEFACTOR_BINARY does not point to a file'); }
            return ['external', self::absolute($override)];
        }
        [$version, $directory] = self::selection($config);
        if (!is_dir($directory) && !mkdir($directory, 0755, true)) { throw new \RuntimeException('Cannot create Codefactor cache'); }
        $lock = $directory . '/.update.lock';
        $acquired = false;
        for ($attempt = 0; $attempt < 300; $attempt++) {
            if (@mkdir($lock)) { $acquired = true; break; }
            if (!is_dir($lock)) { throw new \RuntimeException('Cannot lock Codefactor cache'); }
            usleep(100000);
        }
        if (!$acquired) { throw new \RuntimeException("Codefactor update is locked; if no update is running remove $lock"); }
        $temporary = null;
        try {
            $release = json_decode(file_get_contents(dirname(__DIR__) . '/release.json'), true, 512, JSON_THROW_ON_ERROR);
            $base = Installer::releaseBase($release, $version);
            $manifest = self::validate(json_decode(Installer::download($base . '/manifest-' . Installer::platform() . '.json', getenv('CODEFACTOR_TOKEN') ?: null, 65536), true, 512, JSON_THROW_ON_ERROR), $version);
            $binary = self::cachedPath($directory, $manifest);
            Installer::install(version: $version, destination: $binary, manifest: $manifest);
            $temporary = tempnam($directory, '.active-');
            if ($temporary === false || file_put_contents($temporary, json_encode($manifest, JSON_THROW_ON_ERROR)) === false || !rename($temporary, $directory . '/active.json')) { throw new \RuntimeException('Cannot activate Codefactor binary'); }
            return [$manifest['version'], $binary];
        } finally {
            if ($temporary !== null && is_file($temporary)) { unlink($temporary); }
            rmdir($lock);
        }
    }

    public static function updateCommand(array $args): int
    {
        if (in_array('--help', $args, true) || in_array('-h', $args, true)) { echo "Usage: codefactor core update [--config PATH]\n"; return 0; }
        $config = null;
        if (($args[0] ?? '') !== 'update') { throw new \RuntimeException('Usage: codefactor core update [--config PATH]'); }
        for ($i = 1; $i < count($args); $i++) {
            if ($args[$i] === '--config' && isset($args[$i + 1])) { $config = $args[++$i]; }
            elseif (str_starts_with($args[$i], '--config=') && strlen($args[$i]) > 9) { $config = substr($args[$i], 9); }
            else { throw new \RuntimeException('Usage: codefactor core update [--config PATH]'); }
        }
        [$version, $binary] = self::update($config);
        echo "Codefactor Core $version: $binary\n";
        return 0;
    }
}
