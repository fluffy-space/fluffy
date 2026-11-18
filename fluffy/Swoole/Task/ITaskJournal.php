<?php

namespace Fluffy\Swoole\Task;

/**
 * Where queued tasks are written down before they run, so a worker that dies with them is not the
 * end of them. Optional: without one registered, TaskManager queues tasks in memory only, as before.
 *
 * A task is noted by begin() before it is queued and crossed off by done() after it ran, failed or
 * not (a task that threw would only throw again). What is still noted long after it began was lost
 * with its worker; TaskManager::requeueAbandoned() queues it again.
 */
interface ITaskJournal
{
    /**
     * @param array $payload [class, method, params]
     * @return ?string an id for done(), or null when it could not be noted (the task still runs)
     */
    public function begin(array $payload, int $attempt): ?string;

    /**
     * The task began to run (called by the worker that runs it). From here it is measured against
     * the longer $startedOlderThan of takeAbandoned(), so a task that runs long is not taken as lost.
     */
    public function started(string $id): void;

    public function done(string $id): void;

    /**
     * Tasks never crossed off, taken off the journal: those that never started, noted more than
     * $olderThan seconds ago, and those that started more than $startedOlderThan seconds ago.
     * @return array<int, array{payload: array, attempt: int}>
     */
    public function takeAbandoned(int $olderThan, int $limit, int $startedOlderThan = 3600): array;
}
