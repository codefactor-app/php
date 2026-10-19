<?php
declare(strict_types=1);
namespace Codefactor;

final class Result
{
    public function __construct(
        public readonly array $data,
        public readonly int $exitCode,
        public readonly string $stderr,
    ) {}
}
