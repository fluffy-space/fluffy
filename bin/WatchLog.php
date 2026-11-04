<?php

/**
 * Runs `<script> watch --logged` and copies its output, stdout and stderr in one stream, to both
 * the terminal and ~/<basename of the project dir>-watch.log. Returns the child's exit code.
 *
 * Ctrl+C reaches the child and this process alike (same process group), so it stops like a
 * `| tee` pipeline did.
 */
function watchWithLog(string $projectDir, string $script): int
{
    $home = posix_getpwuid(posix_geteuid())['dir'];
    $logPath = $home . '/' . basename($projectDir) . '-watch.log';
    $log = fopen($logPath, 'w');
    if ($log === false) {
        fwrite(STDERR, "[Watcher] can't write $logPath" . PHP_EOL);
        return 1;
    }
    echo "[Watcher] log: $logPath" . PHP_EOL;

    $process = proc_open(
        [PHP_BINARY, $script, 'watch', '--logged'],
        [0 => STDIN, 1 => ['pipe', 'w'], 2 => ['redirect', 1]],
        $pipes,
        $projectDir
    );
    if ($process === false) {
        fwrite(STDERR, '[Watcher] could not start the watcher' . PHP_EOL);
        return 1;
    }

    while (($chunk = fread($pipes[1], 65536)) !== false && $chunk !== '') {
        fwrite(STDOUT, $chunk);
        fwrite($log, $chunk);
    }
    fclose($pipes[1]);
    fclose($log);
    return proc_close($process);
}
