<?php

class ModelBuilder
{
    private array $vars;
    private array $steps;
    private string $modelName;

    public function __construct(private string $baseDir, private array $inputs) {}

    private function generateFile(string $name, string $namespace, string $templatePath)
    {
        $template = file_get_contents(__DIR__ . $templatePath);
        $template = str_replace('EntityName', $this->modelName, $template);
        $template = str_replace('\\SubFolder', $namespace, $template);
        $template = str_replace('EntityTable', $name, $template);
        $template = str_replace('EntityBaseName', $name, $template);
        $template = str_replace('entityBaseName', lcfirst($name), $template);
        // $template = str_replace('\'COLUMNS\'', $name, $template);
        // $template = str_replace('\'PKEYS\'', $name, $template);
        // $template = str_replace('\'INDEXES\'', $name, $template);
        // echo $template;
        return $template;
    }

    private function writeFile(string $name, string $subName, string $subPath, string $destinationPath, string $content)
    {
        $path = $destinationPath . $subPath . $name . $subName . '.php';
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        file_put_contents($path, $content);
        echo "[ModelBuilder] generated file $path" . PHP_EOL;
    }

    private function makeFile(string $name, string $namespace, string $templatePath, string $subName, string $subPath, string $destinationPath)
    {
        $this->writeFile($name, $subName, $subPath, $destinationPath, $this->generateFile($name, $namespace, $templatePath));
    }

    private function addContent(string $destinationPath, array $templatePaths, string $name, string $namespace, string $subName)
    {
        $content = file_get_contents($destinationPath);
        foreach ($templatePaths as $where => $templatePath) {
            $newItemContent = $this->generateFile($name, $namespace, $templatePath);
            if (!str_contains($content, $newItemContent)) {
                $content = str_replace($where, $newItemContent . $where, $content);
            } else {
                echo "[ModelBuilder] skipping, already present '$newItemContent' in $destinationPath" . PHP_EOL;
            }
        }
        file_put_contents($destinationPath, $content);
        echo "[ModelBuilder] updated file $destinationPath" . PHP_EOL;
    }

    public function build()
    {
        if (count($this->inputs) == 0) {
            die('[ModelBuilder] Too few parameters, expected prod, dev, local or your custom.' . PHP_EOL);
        }
        $action = $this->inputs[0];
        $pathToEntities = $this->baseDir . '/Application/Data/Entities/';
        $pathToMigrations = $this->baseDir . '/Application/Migrations/';
        $pathToRepository = $this->baseDir . '/Application/Data/Repositories/';
        $pathToMigrationContext = $this->baseDir . '/Application/Migrations/MigrationsContext.php';
        $pathToStartUp = $this->baseDir . '/Application/StartUp.php';
        switch ($action) {

            case 'build': {
                    if (count($this->inputs) < 2) {
                        die('[ModelBuilder] Too few parameters for action ' . $action . '. Expected create ModelEntity [SubFolder]' . PHP_EOL);
                    }
                    $this->modelName = $this->inputs[1];
                    echo "[ModelBuilder] running model build action '$action' for '$this->modelName'.." . PHP_EOL;
                    $modelPath = $pathToEntities;
                    $namespace = '';
                    $folder = '';
                    $subPath = '';
                    if (count($this->inputs) > 2) {
                        $folder = $this->inputs[2];
                    }
                    if ($folder) {
                        $modelPath .= $folder . '/';
                        $subPath = $folder . '/';
                        $namespace = '\\' . str_replace('/', '\\', $folder);
                    }
                    $filePath = $modelPath . $this->modelName . '.php';
                    if (!file_exists($filePath)) {
                        die("[ModelBuilder] entity model file '$filePath' not found at '$action' for '$this->modelName'.." . PHP_EOL);
                    }
                    $baseModelName = str_replace('Entity', '', $this->modelName);
                    // repository
                    $this->makeFile($baseModelName, $namespace, '/model/repositoryTemplate.php', 'Repository', '', $pathToRepository);
                    // migration
                    $this->makeFile($baseModelName, $namespace, '/model/migrationTemplate.php', 'Migration', $subPath, $pathToMigrations);
                    // register migration
                    $this->addContent($pathToMigrationContext, ['/** @insert **/' => '/model/migrationContextItemTemplate.php', '/** @namespaces **/' => '/model/migrationNamespaceTemplate.php'], $baseModelName, $namespace, 'Migration');
                    // register repository
                    $this->addContent($pathToStartUp, ['/** @insert **/' => '/model/repositoryStartUpItemTemplate.php', '/** @namespaces **/' => '/model/repositoryNamespaceTemplate.php'], $baseModelName, $namespace, 'Repository');
                    break;
                }

            case 'create': {
                    if (count($this->inputs) < 2) {
                        die('[ModelBuilder] Too few parameters for action ' . $action . '. Expected create ModelEntity [SubFolder]' . PHP_EOL);
                    }
                    $this->modelName = $this->inputs[1];
                    echo "[ModelBuilder] running model build action '$action' for '$this->modelName'.." . PHP_EOL;
                    $folder = '';
                    if (count($this->inputs) > 2) {
                        $folder = $this->inputs[2];
                    }
                    $modelPath = $pathToEntities;
                    $namespace = '';
                    if ($folder) {
                        $modelPath .= $folder . '/';
                        $namespace = '\\' . str_replace('/', '\\', $folder);
                    }
                    if (!file_exists($modelPath)) {
                        mkdir($modelPath, 0777, true);
                    }
                    $filePath = $modelPath . $this->modelName . '.php';
                    if (file_exists($filePath)) {
                        $line = readline("[ModelBuilder] File $filePath already exists. Do you wish to override? [y,n]: " . PHP_EOL);
                        readline_add_history($line);
                        if ($line !== 'y') {
                            echo "[ModelBuilder] canceling generating" . PHP_EOL;
                            break;
                        }
                    }
                    echo "[ModelBuilder] path $filePath" . PHP_EOL;
                    $template = file_get_contents(__DIR__ . '/model/entityTemplate.php');
                    $template = str_replace('EntityName', $this->modelName, $template);
                    $template = str_replace('\\SubFolder', $namespace, $template);
                    file_put_contents($filePath, $template);

                    $mapPath = $modelPath . $this->modelName . 'Map.php';
                    echo "[ModelBuilder] model path $mapPath" . PHP_EOL;
                    $template = file_get_contents(__DIR__ . '/model/entityMapTemplate.php');
                    $template = str_replace('EntityName', $this->modelName, $template);
                    $template = str_replace('\\SubFolder', $namespace, $template);
                    $template = str_replace('EntityTable', str_replace('Entity', '', $this->modelName), $template);
                    file_put_contents($mapPath, $template);
                    break;
                }
            default: {
                    die("[ModelBuilder] Unrecognizable command '$action'" . PHP_EOL);
                    break;
                }
        };
    }
}
