<?php
declare(strict_types=1);

if (PHP_OS_FAMILY === 'Windows') {
    echo "PHP CLI subprocess fixtures require POSIX; validate Windows with a native core release.\n";
    exit(0);
}

function verify(bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
}

function launch(array $command, string $cwd, array $environment, string $input): array {
    $process = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, $cwd, $environment);
    verify(is_resource($process), 'Cannot launch CLI');
    fwrite($pipes[0], $input);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $stdout, $stderr];
}

$root = sys_get_temp_dir() . '/codefactor cli ; ' . bin2hex(random_bytes(8));
mkdir($root);
try {
    $fake = "$root/fake engine";
    file_put_contents($fake, '#!' . PHP_BINARY . "\n<?php\n" . <<<'PHP'
echo json_encode(['args' => array_slice($argv, 1), 'cwd' => getcwd(), 'input' => stream_get_contents(STDIN)]);
fwrite(STDERR, "raw diagnostic\n");
exit(7);
PHP);
    chmod($fake, 0755);
    $arguments = ['scan', '--future-option', 'path ; $(literal)', '--', 'quoted "value"'];
    $environment = array_merge(getenv(), ['CODEFACTOR_BINARY' => $fake]);
    $launcher = realpath(__DIR__ . '/../bin/codefactor');
    foreach ([[], ['-d', 'disable_functions=pcntl_exec']] as $options) {
        [$status, $stdout, $stderr] = launch([PHP_BINARY, ...$options, $launcher, ...$arguments], $root, $environment, "stdin data\n");
        verify($status === 7, 'Core exit status must pass through');
        verify(json_decode($stdout, true, 512, JSON_THROW_ON_ERROR) === ['args' => $arguments, 'cwd' => realpath($root), 'input' => "stdin data\n"], 'Arguments, cwd and stdin must pass through');
        verify($stderr === "raw diagnostic\n", 'stderr must pass through');
    }
    $environment['CODEFACTOR_BINARY'] = "$root/missing";
    [$status, $stdout, $stderr] = launch([PHP_BINARY, $launcher, '--version'], $root, $environment, '');
    verify($status === 127 && $stdout === '' && str_contains($stderr, 'binary missing'), 'Missing engine diagnostics');
    echo "PHP CLI passed: arguments, cwd, stdin, stdout, stderr, exit status, fallback, missing engine\n";
} finally {
    unlink($fake);
    rmdir($root);
}
