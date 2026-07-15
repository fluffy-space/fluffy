<?php

use Swoole\Constant;

class NginxBuilder
{
    private array $vars;
    private array $steps;
    private string $modelName;

    public function __construct(private string $baseDir, private array $inputs, private array $serverConfig)
    {
    }

    public function build()
    {
        echo "[NginxBuilder] starting" . PHP_EOL;
        if (count($this->inputs) == 0) {
            die('[NginxBuilder] Too few parameters, expected domain name.' . PHP_EOL);
        }
        $port = $this->serverConfig['port'] ?? 8101;
        $domain = $this->inputs[0];
        $rootPath = realpath($this->serverConfig['swoole'][Constant::OPTION_DOCUMENT_ROOT]);
        if (!file_exists($this->serverConfig['static_files'])) {
            mkdir($this->serverConfig['static_files'], 0777, true);
            // sudo chmod -R 0777 /home/ivan/nutritionFiles
        }
        $staticFiles = realpath($this->serverConfig['static_files']);
        // `upstream` in configs/server.php is OPTIONAL and picks one of two layouts:
        //
        //  set   -> one upstream per APP, shared by all its domains, in its own conf.d file.
        //           Every site proxy_passes to that name, so the app port lives in ONE place
        //           and a blue/green rotation swings every domain at once. A domain generated
        //           months later still agrees, since the name comes from config, not the host.
        //  unset -> legacy: one upstream per DOMAIN, inlined in the site file (below). Name is
        //           derived from the FULL domain — the first label alone collides for e.g.
        //           dev.urlicer.com + dev.ivi.to -> both "dev" — and every site file shares one
        //           http context, so a collision is a config error.
        $sharedUpstream = $this->serverConfig['upstream'] ?? null;
        $upstream = $sharedUpstream ?? preg_replace('/[^a-z0-9]+/i', '_', $domain);
        // Serve the real cert if certbot already issued one for this domain
        // (`certbot certonly --webroot -w <root> -d <domain>`); otherwise fall
        // back to the dev self-signed pair so `openresty -t` passes on a box
        // with no cert yet. Re-run this command after the first issuance to
        // switch the site over — certbot's --nginx installer plugin is NOT used
        // (it only understands stock nginx, and would be clobbered here anyway).
        $letsEncryptDir = "/etc/letsencrypt/live/$domain";
        $useLetsEncrypt = is_readable("$letsEncryptDir/fullchain.pem") && is_readable("$letsEncryptDir/privkey.pem");
        $sslCert = $useLetsEncrypt ? "$letsEncryptDir/fullchain.pem" : '/etc/ssl/certs/nginx-selfsigned.crt';
        $sslKey = $useLetsEncrypt ? "$letsEncryptDir/privkey.pem" : '/etc/ssl/private/nginx-selfsigned.key';

        echo "[NginxBuilder] Processing nginx for:" . PHP_EOL;
        print_r([
            'domain' => $domain,
            'port' => $port,
            'rootPath' => $rootPath,
            'upstream' => $upstream,
            'staticFiles' => $staticFiles,
            'cert' => $sslCert . ($useLetsEncrypt ? " (Let's Encrypt)" : ' (self-signed — run certbot for a public domain)')
        ]);
        $upstreamBlock = file_get_contents(__DIR__ . '/setup/nginx-upstream.conf');
        $upstreamBlock = str_replace('_UPSTREAM_', $upstream, $upstreamBlock);
        $upstreamBlock = str_replace('_PORT_', $port, $upstreamBlock);

        $templatePath = '/setup/nginx.conf';
        $template = file_get_contents(__DIR__ . $templatePath);

        if ($sharedUpstream === null) {
            // Legacy layout: inline the block at the top of the site file. Drop the template's
            // `##` header — it documents the standalone conf.d file, not this.
            $inline = preg_replace('/^##.*\R/m', '', $upstreamBlock);
            $template = trim($inline) . PHP_EOL . PHP_EOL . $template;
        } else {
            // Shared upstream (conf.d) — create-if-missing, never overwrite. Under blue/green
            // this file holds the ACTIVE port, so regenerating a site config (e.g. adding a
            // short domain) must not reset traffic to the other release.
            $upstreamConfigPath = "/etc/nginx/conf.d/10-upstream-$upstream.conf";
            if (file_exists($upstreamConfigPath)) {
                echo "[NginxBuilder] upstream exists, leaving as-is: $upstreamConfigPath" . PHP_EOL;
                echo "[NginxBuilder]   (it owns the active port — edit it directly to repoint traffic)" . PHP_EOL;
            } else {
                echo "[NginxBuilder] writing upstream $upstream -> 127.0.0.1:$port into $upstreamConfigPath" . PHP_EOL;
                file_put_contents($upstreamConfigPath, $upstreamBlock);
            }
        }

        $template = str_replace('_UPSTREAM_', $upstream, $template);
        $template = str_replace('_ROOT_', $rootPath, $template);
        $template = str_replace('_DOMAIN_', $domain, $template);
        $template = str_replace('_STATIC_FILES_', $staticFiles, $template);
        $template = str_replace('_SSL_CERT_', $sslCert, $template);
        $template = str_replace('_SSL_KEY_', $sslKey, $template);
        $nginxConfigPath = "/etc/nginx/sites-available/$domain";
        echo "[NginxBuilder] saving into $nginxConfigPath" . PHP_EOL;
        file_put_contents($nginxConfigPath, $template);
        $linkPath = "/etc/nginx/sites-enabled/$domain";
        if (!file_exists($linkPath)) {
            symlink($nginxConfigPath, $linkPath);
        }
        echo "[NginxBuilder] Link check for $linkPath = " . readlink($linkPath) . PHP_EOL;

        // Prefer OpenResty (nginx + Lua — the edge we standardised on); fall back
        // to stock nginx if OpenResty isn't installed. Validate before reloading
        // so a bad site file never takes the live server down.
        $openresty = '/usr/local/openresty/bin/openresty';
        if (is_file($openresty)) {
            $bin = $openresty;
            $service = 'openresty';
        } else {
            $bin = 'nginx';
            $service = 'nginx';
        }

        echo "[NginxBuilder] Validating config ($bin -t)." . PHP_EOL;
        System("sudo $bin -t 2>&1", $validateCode);
        if ($validateCode !== 0) {
            die("[NginxBuilder] Invalid config — NOT reloading. Fix $nginxConfigPath and retry." . PHP_EOL);
        }

        echo "[NginxBuilder] Reloading $service server." . PHP_EOL;
        System("sudo systemctl reload $service 2>&1", $reloadCode);
        if ($reloadCode !== 0) {
            // systemctl may be unavailable (e.g. WSL without systemd) — fall back to the service wrapper.
            System("sudo service $service reload 2>&1", $reloadCode);
        }
        if ($reloadCode !== 0) {
            echo "[NginxBuilder] Reload failed — is $service running? Try: sudo systemctl start $service" . PHP_EOL;
        }
    }
}
