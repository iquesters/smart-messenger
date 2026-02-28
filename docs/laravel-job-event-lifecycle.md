# Laravel Job Event Lifecycle

## Scope
- This document maps the Laravel queue lifecycle events currently registered by `SmartMessengerServiceProvider`.
- It covers every listener in `src/Listeners` that is attached through `Event::listen(...)`.
- The focus is the current package behavior, not every possible Laravel queue event.

## Registration Point
- All listeners are registered in `src/SmartMessengerServiceProvider.php` inside `boot()`.
- Current registration block: `Event::listen(...)` calls for `JobProcessed` through `WorkerStopping`.

## Lifecycle Overview
1. A job is about to be pushed: `JobQueueing`.
2. A job has been pushed: `JobQueued`.
3. A worker starts or keeps polling: `WorkerStarting`, `Looping`.
4. The worker pulls a job: `JobPopping`, `JobPopped`.
5. The worker starts handling the job: `JobProcessing`.
6. The attempt finishes: `JobAttempted`.
7. Success path: `JobProcessed`.
8. Failure or retry path: `JobExceptionOccurred`, `JobReleasedAfterException`, `JobRetryRequested`, `JobTimedOut`, `JobFailed`.
9. Queue state changes may occur independently: `QueueBusy`, `QueueFailedOver`, `QueuePaused`, `QueueResumed`, `WorkerStopping`.

## Event Listener Matrix
| Event | Listener | When It Fires | Current Package Behavior |
| --- | --- | --- | --- |
| `JobQueueing` | `JobQueueingListener` | Before a job is pushed onto a queue connection. | Logs an info message with connection and queue. |
| `JobQueued` | `JobQueuedListener` | After a job is pushed onto a queue. | Acquires a short cache lock (`queue-processor-lock`), logs, then calls `QueueManager::processQueues()`. |
| `WorkerStarting` | `WorkerStartingListener` | When a queue worker starts running. | Logs an info message with connection and queue. |
| `Looping` | `LoopingListener` | On each worker loop iteration before fetching the next job. | Logs a debug message with connection and queue. |
| `JobPopping` | `JobPoppingListener` | Right before the worker attempts to pop a job. | Logs an info message with connection. |
| `JobPopped` | `JobPoppedListener` | Right after the worker pops a job from the queue. | Logs an info message with connection. |
| `JobProcessing` | `JobProcessingListener` | Immediately before the worker handles a job. | Logs job start details: connection, queue, resolved job name, and attempt count. |
| `JobAttempted` | `JobAttemptedListener` | After a processing attempt completes. | Logs whether the attempt ended with an exception via `exceptionOccurred`. |
| `JobProcessed` | `JobProcessedListener` | After a job finishes successfully. | Inserts a record into `completed_jobs` with payload/response metadata, then logs completion. |
| `JobExceptionOccurred` | `JobExceptionOccurredListener` | When an exception is thrown while processing a job. | Logs a warning with connection and exception message. |
| `JobReleasedAfterException` | `JobReleasedAfterExceptionListener` | When Laravel releases a failed job back to the queue after an exception. | Acquires the cache lock, logs, reads retry delay from payload, sleeps for that delay plus 2 seconds, then calls `QueueManager::processQueues()`. |
| `JobRetryRequested` | `JobRetryRequestedListener` | When a retry is explicitly requested for a failed job. | Logs a warning. |
| `JobTimedOut` | `JobTimedOutListener` | When a job exceeds its allowed processing time. | Logs an error with connection. |
| `JobFailed` | `JobFailedListener` | When a job is marked as failed and will not continue successfully. | Logs an error with connection, queue, job name, attempts, and exception message. |
| `QueueBusy` | `QueueBusyListener` | When monitored queue size crosses the configured busy threshold. | Logs a warning with connection, queue, and size. |
| `QueueFailedOver` | `QueueFailedOverListener` | When Laravel fails over to another queue connection/driver. | Logs an error with connection and exception message. |
| `QueuePaused` | `QueuePausedListener` | When a queue is paused. | Logs a warning with connection and queue. |
| `QueueResumed` | `QueueResumedListener` | When a paused queue is resumed. | Logs an info message with connection and queue. |
| `WorkerStopping` | `WorkerStoppingListener` | When the queue worker is shutting down. | Logs an info message with the worker exit status. |

## Important Package-Specific Side Effects
- `JobQueuedListener` is not passive logging. It actively tries to kick off queue processing by resolving `Iquesters\Foundation\Services\QueueManager`.
- `JobProcessedListener` persists successful jobs into `completed_jobs`. This is separate from Laravel's default `failed_jobs` handling.
- `JobReleasedAfterExceptionListener` intentionally waits before restarting queue processing. This means the listener itself blocks for the retry window.

## Operational Notes
- Most listeners are observability-only and use the `Loggable` trait for `logMethodStart()`, `logMethodEnd()`, and level-specific logs.
- The cache lock key used for queue restarts is shared: `queue-processor-lock`.
- Because several listeners can trigger queue processing, lock contention is expected and already handled by early-return debug logs.
- Exact runtime ordering can vary slightly by driver and failure mode, but the sequence above reflects the intended Laravel queue lifecycle for this package.

## Related Files
- `src/SmartMessengerServiceProvider.php`
- `src/Listeners/JobQueueingListener.php`
- `src/Listeners/JobQueuedListener.php`
- `src/Listeners/WorkerStartingListener.php`
- `src/Listeners/LoopingListener.php`
- `src/Listeners/JobPoppingListener.php`
- `src/Listeners/JobPoppedListener.php`
- `src/Listeners/JobProcessingListener.php`
- `src/Listeners/JobAttemptedListener.php`
- `src/Listeners/JobProcessedListener.php`
- `src/Listeners/JobExceptionOccurredListener.php`
- `src/Listeners/JobReleasedAfterExceptionListener.php`
- `src/Listeners/JobRetryRequestedListener.php`
- `src/Listeners/JobTimedOutListener.php`
- `src/Listeners/JobFailedListener.php`
- `src/Listeners/QueueBusyListener.php`
- `src/Listeners/QueueFailedOverListener.php`
- `src/Listeners/QueuePausedListener.php`
- `src/Listeners/QueueResumedListener.php`
- `src/Listeners/WorkerStoppingListener.php`
