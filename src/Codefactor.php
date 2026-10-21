<?php
declare(strict_types=1);

namespace Codefactor;

final class Codefactor
{
    public function __construct(private readonly ?string $binary = null, private readonly float $timeout = 120.0)
    {
        if (!is_finite($timeout) || $timeout <= 0) {
            throw new \InvalidArgumentException('Timeout must be positive');
        }
    }

    /** Options: config (path), languages (list), architecture (bool), strictConfig (bool). */
    public function scan(string $root = '.', array $options = []): Result
    {
        $args = ['scan', '--format', 'json'];
        foreach ($options as $key => $value) {
            switch ($key) {
                case 'config': $args[] = '--config'; $args[] = self::absolute($value); break;
                case 'languages':
                    if (!is_array($value) || $value === [] || array_diff($value, ['php', 'python', 'rust', 'javascript', 'typescript', 'js', 'ts'])) {
                        throw new \InvalidArgumentException('Invalid languages');
                    }
                    $args[] = '--lang'; $args[] = implode(',', $value); break;
                case 'architecture': if ($value === false) { $args[] = '--no-architecture'; } break;
                case 'strictConfig': if ($value === true) { $args[] = '--strict-config'; } break;
                default: throw new \InvalidArgumentException("Unknown scan option: $key");
            }
        }
        $result = $this->run([...$args, '--', self::absolute($root)]);
        if (!isset($result->data['schema_version'], $result->data['summary'], $result->data['files'])) {
            throw new \RuntimeException('Invalid Codefactor analysis report');
        }
        return $result;
    }

    public function ast(string $path, ?string $config = null): Result
    {
        $args = ['ast', '--format', 'json'];
        if ($config !== null) { $args = [...$args, '--config', self::absolute($config)]; }
        return $this->run([...$args, '--', self::absolute($path)]);
    }

    public function graph(string $selector, string $root = '.', string $direction = 'both', ?int $depth = null): Result
    {
        if (!in_array($direction, ['callers', 'callees', 'both'], true) || ($depth !== null && $depth < 0)) {
            throw new \InvalidArgumentException('Invalid graph direction or depth');
        }
        $args = ['graph', $direction, '--root', self::absolute($root), '--format', 'json'];
        if ($depth !== null) { $args = [...$args, '--depth', (string)$depth]; }
        return $this->run([...$args, '--', $selector]);
    }

    private static function absolute(string $path): string
    {
        if ($path === '' || str_contains($path, "\0")) { throw new \InvalidArgumentException('Invalid path'); }
        return realpath($path) ?: (str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) ? $path : getcwd() . '/' . $path);
    }

    private function run(array $args): Result
    {
        $binary = $this->binary ?? Core::resolve(...Core::context($args));
        if (!is_file($binary)) { throw new \RuntimeException('Codefactor binary missing; run Composer with the Codefactor plugin enabled'); }
        $process = proc_open([$binary, ...$args], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($process)) { throw new \RuntimeException('Cannot start Codefactor'); }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = $stderr = '';
        $start = hrtime(true);
        $exitCode = -1;
        try {
            while (true) {
                $stdout .= stream_get_contents($pipes[1]);
                $stderr .= stream_get_contents($pipes[2]);
                $status = proc_get_status($process);
                if (!$status['running']) { $exitCode = $status['exitcode']; break; }
                if ((hrtime(true) - $start) / 1e9 > $this->timeout) {
                    proc_terminate($process, 9);
                    throw new \RuntimeException('Codefactor analysis timed out');
                }
                if (strlen($stdout) + strlen($stderr) > 512 * 1024 * 1024) {
                    proc_terminate($process, 9);
                    throw new \RuntimeException('Codefactor output exceeded 512 MiB');
                }
                usleep(1000);
            }
            $stdout .= stream_get_contents($pipes[1]);
            $stderr .= stream_get_contents($pipes[2]);
        } finally {
            fclose($pipes[1]); fclose($pipes[2]);
            $closed = proc_close($process);
            if ($exitCode < 0) { $exitCode = $closed; }
        }
        if (!in_array($exitCode, [0, 1, 2], true)) { throw new \RuntimeException("Codefactor failed ($exitCode): " . trim($stderr)); }
        try { $data = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR); }
        catch (\JsonException $error) { throw new \RuntimeException("Codefactor failed ($exitCode): " . trim($stderr), 0, $error); }
        if (!is_array($data)) { throw new \RuntimeException('Codefactor did not return a JSON object'); }
        return new Result($data, $exitCode, $stderr);
    }
}
