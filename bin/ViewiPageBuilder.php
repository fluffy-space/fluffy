<?php

class ViewiPageBuilder
{
    private string $viewiPageName;

    public function __construct(private string $baseDir, private array $inputs)
    {
    }

    public function build()
    {
        if (count($this->inputs) == 0) {
            die('[ViewiPageBuilder] Too few parameters, expected prod, dev, local or your custom.' . PHP_EOL);
        }
        $action = $this->inputs[0];
        $pathToModels = $this->baseDir . '/viewi-app/Components/Views/Admin/';
        switch ($action) {
            case 'create': {
                    if (count($this->inputs) < 2) {
                        die('[ViewiPageBuilder] Too few parameters for action ' . $action . '. Expected create [Name] [SubFolder]' . PHP_EOL);
                    }
                    $this->viewiPageName = $this->inputs[1];
                    echo "[ViewiPageBuilder] running viewiPage build action '$action' for '$this->viewiPageName'.." . PHP_EOL;
                    $folder = '';
                    if (count($this->inputs) > 2) {
                        $folder = $this->inputs[2];
                    }

                    $modelFolder = $folder;
                    if (count($this->inputs) > 3) {
                        $modelFolder = $this->inputs[3];
                    }

                    $modelTargets = [
                        ['List.php', 'entityListTemplate.php', ''],
                        ['List.html', 'entityListTemplate.html', ''],
                        ['Edit.php', 'entityEditTemplate.php', ''],
                        ['Edit.html', 'entityEditTemplate.html', ''],
                    ];
                    foreach ($modelTargets as $keyValue) {
                        $baseName = $keyValue[0];
                        $basePrefix = $keyValue[2] ?? '';
                        $templateName = $keyValue[1];
                        $modelPath = $pathToModels;
                        $namespace = '';
                        if ($folder) {
                            $modelPath .= $folder . '/';
                            $namespace = '\\' . str_replace('/', '\\', $folder);
                        }
                        $modelNamespace = '';
                        if ($modelFolder) {
                            $modelNamespace = '\\' . str_replace('/', '\\', $modelFolder);
                        }
                        if (!file_exists($modelPath)) {
                            mkdir($modelPath, 0777, true);
                        }
                        $filePath = $modelPath . $basePrefix . $this->viewiPageName .  $baseName;
                        if (file_exists($filePath)) {
                            $line = readline("[ViewiPageBuilder] File $filePath already exists. Do you wish to override? [y,n]: " . PHP_EOL);
                            readline_add_history($line);
                            if ($line !== 'y') {
                                echo "[ViewiPageBuilder] canceling generating" . PHP_EOL;
                                break;
                            }
                        }
                        echo "[ViewiPageBuilder] path $filePath" . PHP_EOL;
                        $template = file_get_contents(__DIR__ . '/model/' . $templateName);
                        $template = str_replace('EntityName', $this->viewiPageName, $template);
                        $template = str_replace('entityName', lcfirst($this->viewiPageName), $template);
                        $template = str_replace('\\SubFolder', $namespace, $template);
                        $template = str_replace('\\ModelFolder', $modelNamespace, $template);

                        file_put_contents($filePath, $template);
                    }
                    break;
                }
            default: {
                    die("[ViewiPageBuilder] Unrecognizable command '$action'" . PHP_EOL);
                    break;
                }
        };
    }
}
