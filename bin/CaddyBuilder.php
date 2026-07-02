<?php

use Swoole\Constant;

/**
 * Generates a Caddy site config for one domain — the Caddy counterpart of
 * NginxBuilder. Where nginx uses sites-available + a sites-enabled symlink,
 * Caddy uses a single config, so we keep a managed main /etc/caddy/Caddyfile
 * (global options + `import sites/*.caddy`) and write one self-contained site
 * file per domain into /etc/caddy/sites/. Run with sudo (writes under /etc).
 *
 *   sudo php fluffy caddy urlicer.wsl.com
 */
class CaddyBuilder
{
    private const MAIN = '/etc/caddy/Caddyfile';
    private const SITES = '/etc/caddy/sites';

    public function __construct(private string $baseDir, private array $inputs, private array $serverConfig)
    {
    }

    public function build()
    {
        echo "[CaddyBuilder] starting" . PHP_EOL;
        if (count($this->inputs) == 0) {
            die('[CaddyBuilder] Too few parameters, expected domain name.' . PHP_EOL);
        }
        $port = $this->serverConfig['port'] ?? 8101;
        $domain = $this->inputs[0];
        $rootPath = realpath($this->serverConfig['swoole'][Constant::OPTION_DOCUMENT_ROOT]);
        if (!file_exists($this->serverConfig['static_files'])) {
            mkdir($this->serverConfig['static_files'], 0777, true);
        }
        $staticFiles = realpath($this->serverConfig['static_files']);
        echo "[CaddyBuilder] Processing caddy for:" . PHP_EOL;
        print_r([
            'domain' => $domain,
            'port' => $port,
            'rootPath' => $rootPath,
            'staticFiles' => $staticFiles
        ]);

        // TLS mode: `internal` (self-signed Caddy CA, dev — matches the old
        // nginx-selfsigned template) or `auto` (real Let's Encrypt; needs a public
        // domain + `email` in the main Caddyfile — set by install-caddy-ubuntu24.sh).
        // Default `internal` preserves dev behaviour; production runs with
        //   sudo FLUFFY_CADDY_TLS=auto php fluffy caddy your.domain
        $tlsMode = getenv('FLUFFY_CADDY_TLS') ?: 'internal';
        $tlsDirective = $tlsMode === 'auto'
            ? '# automatic HTTPS via Let\'s Encrypt (email set in /etc/caddy/Caddyfile)'
            : 'tls internal   # self-signed via Caddy\'s internal CA (dev)';
        echo "[CaddyBuilder] TLS mode: $tlsMode" . PHP_EOL;

        $template = file_get_contents(__DIR__ . '/setup/Caddyfile');
        $template = str_replace('_TLS_', $tlsDirective, $template);
        $template = str_replace('_PORT_', $port, $template);
        $template = str_replace('_ROOT_', $rootPath, $template);
        $template = str_replace('_DOMAIN_', $domain, $template);
        $template = str_replace('_STATIC_FILES_', $staticFiles, $template);

        // Ensure the managed main Caddyfile exists and imports the per-domain files.
        $importLine = 'import ' . self::SITES . '/*.caddy';
        if (!is_dir(self::SITES)) {
            mkdir(self::SITES, 0755, true);
        }
        if (!file_exists(self::MAIN)) {
            echo "[CaddyBuilder] creating " . self::MAIN . PHP_EOL;
            file_put_contents(
                self::MAIN,
                "{\n\tgrace_period 30s\n\tadmin 127.0.0.1:2019\n}\n\n" . $importLine . "\n"
            );
        } elseif (strpos(file_get_contents(self::MAIN), $importLine) === false) {
            // Existing Caddyfile without our import — append it (don't clobber, and
            // don't add a second global block, which Caddy would reject).
            echo "[CaddyBuilder] adding import line to " . self::MAIN . PHP_EOL;
            file_put_contents(self::MAIN, rtrim(file_get_contents(self::MAIN)) . "\n\n" . $importLine . "\n");
        }

        $siteConfigPath = self::SITES . "/$domain.caddy";
        echo "[CaddyBuilder] saving into $siteConfigPath" . PHP_EOL;
        file_put_contents($siteConfigPath, $template);

        echo "[CaddyBuilder] Validating config." . PHP_EOL;
        System('caddy validate --config ' . self::MAIN . ' 2>&1', $validateCode);
        if ($validateCode !== 0) {
            die("[CaddyBuilder] Invalid Caddy config — NOT reloading. Fix $siteConfigPath and retry." . PHP_EOL);
        }

        // Graceful, zero-downtime reload via Caddy's admin API. `caddy reload`
        // adapts the Caddyfile and POSTs it to the admin endpoint (127.0.0.1:2019,
        // set in the main Caddyfile) — the same path `systemctl reload caddy` takes,
        // but startup-method-agnostic and it surfaces a real exit code on failure.
        // (If Caddy isn't running yet, start it once: `sudo systemctl start caddy`.)
        echo "[CaddyBuilder] Reloading Caddy server (admin API)." . PHP_EOL;
        System('caddy reload --config ' . self::MAIN . ' 2>&1', $reloadCode);
        if ($reloadCode !== 0) {
            echo "[CaddyBuilder] Reload failed — is Caddy running? Try: sudo systemctl start caddy" . PHP_EOL;
        }
    }
}
