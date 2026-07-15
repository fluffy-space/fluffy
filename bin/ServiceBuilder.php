<?php

/**
 * Generates a systemd unit per app instance from `instances` in configs/server.php
 * (`php fluffy service` for all, `php fluffy service <color>` for one).
 *
 * Each instance is the SAME code in a sibling release directory, told apart only by that
 * directory's name — configs/server.php maps it to a port. So the unit is just the template
 * plus a WorkingDirectory, and blue/green need no per-instance config of their own.
 *
 * Units are written, reloaded and enabled, but NOT started: a release dir that hasn't been
 * built yet would crash-loop under Restart=always. Start it yourself once it's ready.
 */
class ServiceBuilder
{
    public function __construct(private string $baseDir, private array $inputs, private array $serverConfig)
    {
    }

    public function build()
    {
        echo "[ServiceBuilder] starting" . PHP_EOL;
        $instances = $this->serverConfig['instances'] ?? [];
        if (count($instances) === 0) {
            die('[ServiceBuilder] No `instances` in configs/server.php — nothing to generate.' . PHP_EOL);
        }

        $only = $this->inputs[0] ?? null;
        if ($only !== null && !isset($instances[$only])) {
            die("[ServiceBuilder] Unknown instance '$only'. Known: " . implode(', ', array_keys($instances)) . PHP_EOL);
        }

        // Instances are siblings of the release we're run from: /var/www/urlicer/{blue,green}.
        $releasesDir = dirname($this->baseDir);
        $user = $this->serverConfig['service_user'] ?? 'www-data';
        $group = $this->serverConfig['service_group'] ?? $user;
        $description = $this->serverConfig['service_description'] ?? 'Fluffy Web Application';
        $documentation = $this->serverConfig['service_documentation'] ?? '';

        $template = file_get_contents(__DIR__ . '/setup/fluffy.service');
        $written = [];

        foreach ($instances as $color => $instance) {
            if ($only !== null && $color !== $only) {
                continue;
            }
            $service = $instance['service'];
            $workDir = $releasesDir . DIRECTORY_SEPARATOR . $color;

            echo "[ServiceBuilder] Processing instance:" . PHP_EOL;
            print_r([
                'color' => $color,
                'service' => $service,
                'port' => $instance['port'],
                'workingDirectory' => $workDir . (is_dir($workDir) ? '' : '  <-- MISSING'),
                'user' => "$user:$group"
            ]);
            if (!is_dir($workDir)) {
                // Not fatal — the unit is valid, the release just isn't deployed here yet.
                echo "[ServiceBuilder] WARNING: $workDir does not exist — $service will fail to start until it does." . PHP_EOL;
            }

            $unit = str_replace('_DESCRIPTION_', "$description ($color)", $template);
            $unit = str_replace(
                '_DOCUMENTATION_',
                $documentation === '' ? '' : "Documentation=$documentation",
                $unit
            );
            $unit = str_replace('_WORKDIR_', $workDir, $unit);
            $unit = str_replace('_USER_', $user, $unit);
            $unit = str_replace('_GROUP_', $group, $unit);
            // Drop the blank line left behind when Documentation isn't configured.
            $unit = preg_replace("/\R\R+/", PHP_EOL . PHP_EOL, $unit);

            $unitPath = "/etc/systemd/system/$service.service";
            echo "[ServiceBuilder] saving into $unitPath" . PHP_EOL;
            if (file_put_contents($unitPath, $unit) === false) {
                die("[ServiceBuilder] Could not write $unitPath — run with sudo." . PHP_EOL);
            }
            $written[] = $service;
        }

        echo "[ServiceBuilder] Reloading systemd (daemon-reload)." . PHP_EOL;
        System('sudo systemctl daemon-reload 2>&1', $reloadCode);
        if ($reloadCode !== 0) {
            echo "[ServiceBuilder] daemon-reload failed — is this box running systemd?" . PHP_EOL;
            return;
        }

        foreach ($written as $service) {
            echo "[ServiceBuilder] Enabling $service." . PHP_EOL;
            System("sudo systemctl enable $service 2>&1", $enableCode);
            if ($enableCode !== 0) {
                echo "[ServiceBuilder] Could not enable $service." . PHP_EOL;
            }
        }

        // Deliberately not started — see the class docblock.
        echo PHP_EOL . "[ServiceBuilder] Done. Start when the release is built:" . PHP_EOL;
        foreach ($written as $service) {
            echo "  sudo systemctl start $service   # then: systemctl status $service" . PHP_EOL;
        }
    }
}
