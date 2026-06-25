<?php

namespace Fluffy\Domain\CronTab;

class CronTabItem
{
    public array $params;
    public TimerInfo $currentRunTimerInfo;
    /**
     * 
     * @param callable|array $task 
     * @param string $schedule 
     * @param bool $runOnStartup
     * @param array $params 
     * @return void 
     */
    public function __construct(public $task, public string $schedule, public bool $runOnStartup = false, array $params = [])
    {
        $this->params = $params;
    }

    /**
     * Stable, human-readable job identity ("Namespace\Class::method"). Used as the key into the
     * cron status table so the id survives worker restarts and is the same across all workers
     * (unlike the cron worker's random uniqueId used for the run lock).
     */
    public function id(): string
    {
        if (is_array($this->task)) {
            return implode('::', $this->task);
        }
        return is_string($this->task) ? $this->task : 'closure';
    }
}
