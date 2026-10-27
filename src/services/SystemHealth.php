<?php

namespace dgrigg\canary\services;

use yii\base\Component;

use dgrigg\canary\Canary;

/**
 * Server-level checks for signs of compromise. Written for Linux servers;
 * checks whose tools are missing are skipped quietly.
 */
class SystemHealth extends Component
{
    /**
     * Directories where malware typically drops and runs its payload.
     */
    public array $tempDirs = ['/tmp', '/var/tmp', '/dev/shm'];

    /**
     * Run every check. Returns alert strings; empty means clean.
     *
     * @return string[]
     */
    public function check(): array
    {
        return array_merge(
            $this->checkTempProcesses(),
            $this->checkTempExecutables(),
            $this->checkCpu(),
            $this->checkMiningConnections(),
            $this->checkRedis(),
            $this->checkCrontab(),
        );
    }

    /**
     * Processes whose binary lives in a temp directory.
     */
    public function checkTempProcesses(): array
    {
        $found = [];

        foreach (glob('/proc/[0-9]*/exe') ?: [] as $exe) {
            $target = @readlink($exe);

            if ($target && $this->inTempDir($target)) {
                $found[] = 'pid ' . basename(dirname($exe)) . ' → ' . $target;
            }
        }

        return $found ? ['Process running from a temp directory: ' . implode('; ', $found)] : [];
    }

    /**
     * Executable files sitting at the top level of a temp directory.
     */
    public function checkTempExecutables(): array
    {
        $found = [];

        foreach ($this->tempDirs as $dir) {
            foreach (@scandir($dir) ?: [] as $name) {
                $path = $dir . '/' . $name;

                if ($name !== '.' && $name !== '..' && is_file($path) && !is_link($path) && (@fileperms($path) & 0111)) {
                    $found[] = $path;
                }
            }
        }

        return $found ? ['Executable files found in a temp directory: ' . implode(', ', $found)] : [];
    }

    /**
     * Any process above the CPU threshold.
     */
    public function checkCpu(): array
    {
        $threshold = Canary::$plugin->getSettings()->cpuThreshold;
        $found = [];

        foreach ($this->lines($this->exec('ps aux 2>/dev/null')) as $i => $line) {
            $cols = preg_split('/\s+/', $line, 11);

            if ($i === 0 || count($cols) < 11 || !is_numeric($cols[2])) {
                continue;
            }

            if ((float) $cols[2] > $threshold) {
                $found[] = "{$cols[0]} pid {$cols[1]} {$cols[2]}% {$cols[10]}";
            }
        }

        return $found ? ["Process using >{$threshold}% CPU: " . implode('; ', $found)] : [];
    }

    /**
     * Outbound TCP connections to common mining pool ports. Only flags
     * connections where the mining port is the remote port and the local
     * port is not a web listener, so inbound browsers whose ephemeral
     * source port happens to match are ignored.
     */
    public function checkMiningConnections(): array
    {
        $ports = Canary::$plugin->getSettings()->miningPorts;
        $found = [];

        foreach ($this->lines($this->exec('ss -Htnp 2>/dev/null')) as $line) {
            $cols = preg_split('/\s+/', $line);

            if (count($cols) < 5) {
                continue;
            }

            // Port is whatever follows the last ":", so IPv4 and [IPv6] both parse
            $localPort = (int) substr(strrchr($cols[3], ':'), 1);
            $remotePort = (int) substr(strrchr($cols[4], ':'), 1);

            if (in_array($remotePort, $ports, true) && !in_array($localPort, [80, 443], true)) {
                $found[] = $line;
            }
        }

        return $found ? ['Suspicious outbound connection (mining pool port): ' . implode('; ', $found)] : [];
    }

    /**
     * Redis listening on something other than localhost.
     */
    public function checkRedis(): array
    {
        $lines = $this->lines($this->exec('redis-cli config get bind 2>/dev/null'));
        $bind = end($lines);

        if ($bind && !str_contains($bind, '127.0.0.1')) {
            return ['Redis is not bound to localhost: ' . $bind];
        }

        return [];
    }

    /**
     * Crontab entries that don't match any expected pattern. A crontab that
     * exists but can't be read is reported too, so a permissions problem
     * doesn't pass as a clean result.
     */
    public function checkCrontab(): array
    {
        $settings = Canary::$plugin->getSettings();

        if (!$settings->enableCrontabCheck || !function_exists('exec')) {
            return [];
        }

        $owner = $settings->cronUser !== '' ? $settings->cronUser : $this->processUser();
        $command = $settings->cronUser !== ''
            ? 'crontab -u ' . escapeshellarg($settings->cronUser) . ' -l 2>&1'
            : 'crontab -l 2>&1';

        $output = [];
        $exitCode = 0;
        @exec($command, $output, $exitCode);
        $lines = $this->lines(implode("\n", $output));

        if ($exitCode === 127) {
            // crontab isn't installed
            return [];
        }

        if ($exitCode !== 0) {
            $message = implode(' ', $lines);

            // An account with no crontab has nothing to report
            if (stripos($message, 'no crontab for') !== false) {
                return [];
            }

            return ["Crontab for {$owner} could not be read" . ($message !== '' ? ": {$message}" : '') . '. Run the scan as a user that can read it, or turn off the crontab check.'];
        }

        $unexpected = array_filter($lines, function($line) use ($settings) {
            if (str_starts_with($line, '#')) {
                return false;
            }

            foreach ($settings->allowedCronPatterns as $pattern) {
                if ($pattern !== '' && str_contains($line, $pattern)) {
                    return false;
                }
            }

            return true;
        });

        return $unexpected ? ['Unexpected crontab entries: ' . implode('; ', $unexpected)] : [];
    }

    /**
     * The system account this PHP process runs as.
     */
    private function processUser(): string
    {
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $info = posix_getpwuid(posix_geteuid());

            if (!empty($info['name'])) {
                return $info['name'];
            }
        }

        return 'the current user';
    }

    private function inTempDir(string $path): bool
    {
        foreach ($this->tempDirs as $dir) {
            if (str_starts_with($path, $dir . '/')) {
                return true;
            }
        }

        return false;
    }

    protected function exec(string $command): string
    {
        if (!function_exists('shell_exec')) {
            return '';
        }

        return (string) @shell_exec($command);
    }

    private function lines(string $output): array
    {
        return array_values(array_filter(array_map('trim', explode("\n", $output)), 'strlen'));
    }
}
