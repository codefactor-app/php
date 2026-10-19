<?php
declare(strict_types=1);
spl_autoload_register(function (string $class): void {
    if (str_starts_with($class, 'Codefactor\\')) {
        require __DIR__ . '/../src/' . substr($class, strlen('Codefactor\\')) . '.php';
    }
});

use Codefactor\Codefactor;
use Codefactor\Installer;

function check(bool $condition, string $message): void { if (!$condition) { throw new RuntimeException($message); } }
function removeTree(string $path): void {
    foreach (scandir($path) as $entry) {
        if ($entry === '.' || $entry === '..') { continue; }
        $child = "$path/$entry";
        if (is_dir($child)) { removeTree($child); } else { unlink($child); }
    }
    rmdir($path);
}

if (!getenv('CODEFACTOR_BINARY') && !is_file(Installer::binaryPath())) { Installer::install(); }
$directory = sys_get_temp_dir() . '/codefactor php ; ' . bin2hex(random_bytes(8));
mkdir($directory);
$sources = [
    'calls.php' => '<?php function leaf(){return 1;} function caller(){return leaf();}',
    'calls.py' => "def leaf():\n    return 1\ndef caller(a, b, c):\n    return leaf()\n",
    'calls.ts' => 'export function leaf(){return 1;} export function caller(){return leaf();}',
    'calls.js' => 'function leaf(){return 1;} function caller(){return leaf();}',
    'calls.rs' => 'fn leaf()->i32{1} fn caller()->i32{leaf()}',
];
try {
    foreach ($sources as $name => $source) { file_put_contents("$directory/$name", $source); }
    $client = new Codefactor();
    $result = $client->scan($directory, ['architecture' => false]);
    check($result->data['summary']['files'] === 5, 'All five languages must be available through PHP');
    check($result->data['summary']['functions'] === 10, 'Function count');
    check($result->data['parse_errors'] === [], 'Fixture parse errors');
    check($client->scan($directory, ['languages' => ['python']])->data['summary']['files'] === 1, 'Language filtering');
    check(isset($client->ast("$directory/calls.py")->data['root']), 'AST result');
    $graph = $client->graph('calls.caller', $directory, 'callees', 2)->data;
    check(count($graph['edges']) > 0, 'Resolved graph edges');
    $config = "$directory/limits.yaml";
    file_put_contents($config, "limits:\n  function_params: {warn: 1, error: 2}\ncache:\n  enabled: false\n");
    $violations = $client->scan($directory, ['config' => $config]);
    check($violations->exitCode === 2 && $violations->data['summary']['errors'] > 0, 'Violations must remain readable reports');
    file_put_contents($config, '[invalid: yaml');
    try { $client->scan($directory, ['config' => $config]); throw new LogicException('Invalid YAML was accepted'); }
    catch (RuntimeException $error) { check(str_contains($error->getMessage(), 'failed (3)'), 'Config error must be distinct'); }
    try { $client->scan("$directory/missing"); throw new LogicException('Missing project was accepted'); }
    catch (RuntimeException $error) { check(str_contains($error->getMessage(), 'failed'), 'Execution error'); }
    if (PHP_OS_FAMILY !== 'Windows') {
        $fake = "$directory/slow";
        file_put_contents($fake, '#!' . PHP_BINARY . "\n<?php usleep(30 * 1000000);\n");
        chmod($fake, 0755);
        try { (new Codefactor($fake, 0.05))->scan($directory); throw new LogicException('Timeout was ignored'); }
        catch (RuntimeException $error) { check(str_contains($error->getMessage(), 'timed out'), 'Timeout'); }
    }
    echo "PHP integration passed: install, five languages, scan, AST, graph, violations, failures, timeout\n";
} finally { removeTree($directory); }
