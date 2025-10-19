<?php

class ControllerBuilder
{
    private string $controllerName;

    public function __construct(private string $baseDir, private array $inputs) {}

    public function build()
    {
        if (count($this->inputs) == 0) {
            die('[ControllerBuilder] Too few parameters, expected prod, dev, local or your custom.' . PHP_EOL);
        }
        $action = $this->inputs[0];
        $pathToControllers = $this->baseDir . '/Application/Controllers/Admin/';
        $pathToModels = $this->baseDir . '/viewi-app/Components/Models/';
        switch ($action) {
            case 'create': {
                    if (count($this->inputs) < 2) {
                        die('[ControllerBuilder] Too few parameters for action ' . $action . '. Expected create [Name] [SubFolder]' . PHP_EOL);
                    }
                    $this->controllerName = $this->inputs[1];
                    echo "[ControllerBuilder] running controller build action '$action' for '$this->controllerName'.." . PHP_EOL;
                    $folder = '';
                    if (count($this->inputs) > 2) {
                        $folder = $this->inputs[2];
                    }
                    $controllerPath = $pathToControllers;
                    $namespace = '';
                    if ($folder) {
                        $controllerPath .= $folder . '/';
                        $namespace = '\\' . str_replace('/', '\\', $folder);
                    }
                    if (!file_exists($controllerPath)) {
                        mkdir($controllerPath, 0777, true);
                    }
                    $filePath = $controllerPath . $this->controllerName . 'Controller.php';
                    if (file_exists($filePath)) {
                        // $line = readline("[ControllerBuilder] File $filePath already exists. Do you wish to override? [y,n]: " . PHP_EOL);
                        // readline_add_history($line);
                        $line = 'y';
                        if ($line !== 'y') {
                            echo "[ControllerBuilder] canceling generating" . PHP_EOL;
                            break;
                        }
                    }
                    echo "[ControllerBuilder] path $filePath" . PHP_EOL;
                    $template = file_get_contents(__DIR__ . '/model/entityControllerTemplate.php');
                    $template = str_replace('EntityName', $this->controllerName, $template);
                    $template = str_replace('entityName', lcfirst($this->controllerName), $template);
                    $template = str_replace('\\SubFolder', $namespace, $template);
                    file_put_contents($filePath, $template);

                    $modelTargets = [
                        ['Model', 'baseEntityModelTemplate', 'Base'],
                        ['Model', 'entityModelTemplate'],
                        ['Validation', 'entityValidation'],
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
                        if (!file_exists($modelPath)) {
                            mkdir($modelPath, 0777, true);
                        }
                        $filePath = $modelPath . $basePrefix . $this->controllerName .  $baseName . '.php';
                        if (file_exists($filePath)) {
                            //$line = readline("[ControllerBuilder] File $filePath already exists. Do you wish to override? [y,n]: " . PHP_EOL);
                            //readline_add_history($line);
                            $line = 'y';
                            if ($line !== 'y') {
                                echo "[ControllerBuilder] canceling generating" . PHP_EOL;
                                break;
                            }
                        }
                        echo "[ControllerBuilder] path $filePath" . PHP_EOL;
                        $template = file_get_contents(__DIR__ . '/model/' . $templateName . '.php');
                        $template = str_replace('EntityName', $this->controllerName, $template);
                        $template = str_replace('entityName', lcfirst($this->controllerName), $template);
                        $template = str_replace('\\SubFolder', $namespace, $template);
                        file_put_contents($filePath, $template);
                    }
                    break;
                }
            default: {
                    die("[ControllerBuilder] Unrecognizable command '$action'" . PHP_EOL);
                    break;
                }
        };
    }
}
