#!/usr/bin/env php
<?php

declare(strict_types=1);

$execFile = $argv[0];
if (2 !== \count($argv)) {
    echo \sprintf("Usage: php %s targets.txt", $execFile) . PHP_EOL;
    exit;
}

$fileTargetsPath = $argv[1];

if (!\file_exists($fileTargetsPath)) {
    echo \sprintf('File "%s" does not exists.', $fileTargetsPath) . PHP_EOL;
    exit;
}

$contents = \file_get_contents($fileTargetsPath);

if ('' === $contents) {
    echo 'Content is empty..' . PHP_EOL;
    exit;
}

$lines = \explode(PHP_EOL, $contents);

function buildTargets(string $ip, string $portsString): array
{
    $portsString = \trim($portsString, '()');
    $ports = \explode(',', $portsString);
    $targets = [];

    foreach ($ports as $port) {
        [$portId, $protocol] = \explode('/', $port);
        $portId = (int) $portId;
        $protocol = \trim(\mb_strtolower($protocol));

        switch ($portId) {
            case 443:
                $protocol = 'https';
                break;
            case 80:
                $protocol = 'http';
                break;
            case 53:
                $protocol = 'dns';
                break;
            case 5432:
            case 5672:
            case 22:
                $protocol = 'tcp';
                break;
            default:
        }

        $targets[buildTargetTitle($ip, $portId, $protocol)] = buildTarget($protocol, $ip, $portId);
    }

    return $targets;
}

function buildTargetTitle(string $ip, int $portId, string $protocol): string
{
    return \implode('_', [$ip, $portId, $protocol]);
}

function buildTarget(string $protocol, string $ip, int $portId): array
{
    $templates = [
        'http' => [
            'host' => 'http://%s',
            'port' => 80,
            'executor' => 'exec',
            'app' => 'bombardier',
            'durationSecs' => 60,
            'enabled' => true
        ],
        'https' => [
            'host' => 'https://%s',
            'port' => 443,
            'executor' => 'exec',
            'app' => 'bombardier',
            'durationSecs' => 60,
            'enabled' => true
        ],
        'tcp' => [
            'host' => '%s',
            'port' => 0,
            'executor' => 'exec',
            'app' => 'dripper',
            'durationSecs' => 60,
            'enabled' => true,
            'args' => [
                '-m',
                'tcp'
            ]
        ],
        'udp' => [
            'host' => '%s',
            'port' => 0,
            'executor' => 'exec',
            'app' => 'dripper',
            'durationSecs' => 60,
            'enabled' => true,
            'args' => [
                '-m',
                'udp'
            ]
        ],
        'dns' => [
            'host' => '%s',
            'port' => 53,
            'executor' => 'exec',
            'app' => 'dnsperf',
            'durationSecs' => 60,
            'enabled' => true,
        ],
    ];

    if (!isset($templates[$protocol])) {
        $protocol = 'tcp';
    }

    $target = $templates[$protocol];
    $target['host'] = \sprintf($target['host'], $ip);
    $target['port'] = $portId;

    return $target;
}

$targets = [];

foreach($lines as $line) {
    $parts = \explode(' ', $line, 2);
    $host = $parts[0];

    if (false === filter_var($host, FILTER_VALIDATE_IP)) {
        continue;
    }

    $ports = $parts[1];
    if (1 !== \preg_match('/tcp|udp|dns|mikrotik_bw|postgres|amqp|http|https/i', $ports)) {
        echo \sprintf('Line "%s" not contains tcp/udp ports', $line) . PHP_EOL;
        continue;
    }

    $targets[] = buildTargets($host, $ports);
}

$targets = \array_merge([], ...$targets);
if (0 === \count($targets)) {
    echo 'Targets not found' . PHP_EOL;
    exit;
}

echo \json_encode($targets, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) . PHP_EOL;