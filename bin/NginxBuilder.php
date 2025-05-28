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
        $upstream = explode('.', $domain)[0];
        echo "[NginxBuilder] Processing nginx for:" . PHP_EOL;
        print_r([
            'domain' => $domain,
            'port' => $port,
            'rootPath' => $rootPath,
            'upstream' => $upstream,
            'staticFiles' => $staticFiles
        ]);
        $templatePath = '/setup/nginx.conf';
        $template = file_get_contents(__DIR__ . $templatePath);
        $template = str_replace('_UPSTREAM_', $upstream, $template);
        $template = str_replace('_PORT_', $port, $template);
        $template = str_replace('_ROOT_', $rootPath, $template);
        $template = str_replace('_DOMAIN_', $domain, $template);
        $template = str_replace('_STATIC_FILES_', $staticFiles, $template);
        $nginxConfigPath = "/etc/nginx/sites-available/$domain";
        echo "[NginxBuilder] saving into $nginxConfigPath" . PHP_EOL;
        file_put_contents($nginxConfigPath, $template);
        $linkPath = "/etc/nginx/sites-enabled/$domain";
        symlink($nginxConfigPath, $linkPath);
        echo "[NginxBuilder] Link check for $linkPath = " . readlink($linkPath) . PHP_EOL;
        echo "[NginxBuilder] Reloading Nginx server." . PHP_EOL;
        System('sudo service nginx reload');
    }
}
