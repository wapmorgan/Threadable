# Threadable

Easy-to-use threading library providing all basic features to perform work in background mode.

All you need to have installed:
- _pcntl_
- _posix_
- _sockets_

This library can also work in simulation mode, where no actual forking performs. All work is done in one main thread.
This mode enables if **pnctl** extension is not available or when you specify it in `Worker` constructor.

[![Latest Stable Version](https://poser.pugx.org/wapmorgan/threadable/v/stable)](https://packagist.org/packages/wapmorgan/threadable)
[![Latest Unstable Version](https://poser.pugx.org/wapmorgan/threadable/v/unstable)](https://packagist.org/packages/wapmorgan/threadable)
[![License](https://poser.pugx.org/wapmorgan/threadable/license)](https://packagist.org/packages/wapmorgan/threadable)

1. Structure
    - What is a `Worker`?
        * How to create your Worker
    - What is a `WorkersPool`?
2. Simple usage
3. How it works
    - One worker
    - Few workers with `WorkersPool`
4. API
    - `Worker` API
    - `WorkersPool` API
5. Predefined workers
    - `DownloadWorker`
6. Use cases

# Structure

## What is a Worker?
**Worker** - is a basic class for any worker. It is composed of two substances (physically, stored in one class, but providing different functionalities):

1. A `Worker` - a separate thread, doing all background work.
2. A `Worker` manager - a manipulator for the worker thread.

### How to create your Worker

The all you need it to extend `wapmorgan\Threadable\Worker` class and reimplement `onPayload($data)` public method.

For example:
```php
use wapmorgan\Threadable\Worker;
class SleepingWorker extends Worker
{
    public function onPayload($data)
    {
        echo 'I have started at '.date('r').PHP_EOL;
        sleep(3);
        echo 'I have ended at '.date('r').PHP_EOL;
        
        return true;
    }
}
```

## What is a WorkersPool?
**WorkersPool** (_wapmorgan\Threadable\WorkersPool_) - is a container for `Worker`'s, intended for handling similar tasks.
It takes care of all maintenance, payload dispatching and life-cycle of workers. Allows you change the size of the pool dynamically and other useful stuff.

# Simple usage
For example, you want to just background downloading work. Let's use `wapmorgan\Threadable\BackgroundWork` class to background it and show progress for user (or store in DB/...).

Everything you need to do:
1. Prepare payloads for `DownloadWorker`
2. Launch `BackgroundWork::doInBackground()` or `BackgroundWork::doInBackgroundParallel()` for one thread or few threads respectively.

## Stage 1. Preparing payloads

`DownloadWorker` needs an array with `source` and `target` elements. Prepare it:

```php
use wapmorgan\Threadable\BackgroundWork;
use wapmorgan\Threadable\DownloadWorker;
use wapmorgan\Threadable\Worker;

$file_sources = ['https://yandex.ru/images/today?size=1920x1080', 'http://hosting-obzo-ru.1gb.ru/hosting-obzor.ru.zip'];
$files = [];
foreach ($file_sources as $file_to_download) {
    $files[] = [
        'source' => $file_to_download,
        'size' => DownloadWorker::getRemoteFileSize($file_to_download),
        'target' => tempnam(sys_get_temp_dir(), 'thrd_test'),
    ];
}
```

## Stage 2. Launching in background

### One-thread worker
Run it in one thread with `doInBackground` function. Signature is following:

`doInBackground(Worker $worker,
    array $payloads,
    callable $payloadHandlingCallback = null,
    callable $onPayloadFinishCallback = null,
    $sleepMicroTime = 1000)`

- `$worker` - an instance of worker.
- `$payloads` - an array of all payloads.
- `$payloadHandlingCallback` - a callback that will be called every `$sleepMicrotime` microseconds with information about currently running payload.
    Signature for callback: `(Worker $worker, int $payloadI, $payloadData)`
- `$onPayloadFinishCallback` - a callback that will be called when worker ends with one payload.
    Signature for callback: `(Worker $worker, int $payloadI, $payloadData, $payloadResult)`

So, collect all information to run it:

```php
$result = BackgroundWork::doInBackground(new DownloadWorker(), $files,
    function (Worker $worker, $payloadI, $payloadData) {
        clearstatcache(true, $payloadData['target']);
        echo "\r" . '#' . ($payloadI + 1) . '. ' . basename($payloadData['source']) . ' downloading ' . round(filesize($payloadData['target']) * 100 / $payloadData['size'], 2) . '%';
    },
    function (Worker $worker, $payloadI, $payloadData, $payloadResult) {
        echo "\r" . '#' . ($payloadI + 1) . '. ' . basename($payloadData['source']) . ' successfully downloaded' . PHP_EOL;
        return true;
    }
);
if ($result)
    echo 'All files downloaded successfully'.PHP_EOL;
```

Example is in `bin/example_file_downloading_easy` file.

### Few-threads worker

To run it in few threads use `doInBackgroundParallel`. It has almost the same signature as one-thread function:

`doInBackgroundParallel(Worker $worker,
    array $payloads,
    callable $payloadHandlingCallback = null,
    callable $onPayloadFinishCallback = null,
    $sleepMicroTime = 1000,
    $poolSize = self::BY_CPU_NUMBER)`

By adjusting `$poolSize` you can select number of workers that should be used.

Example is in `bin/example_file_downloading_pool_easy` file.

# How it works

## One worker

If you just need to parallel some work and do it in another thread, you can utilize just `Worker` class without any other dependencies.

To use it correctly you need to understand the life-cycle of worker:

1. Worker starts in another thread. To do this call `start()`.
2. Worker accepts new payload and starts working on it. To do this call `sendPayload(array $data)`. Really, worker manager sends payload via local socket. Worker thread starts working on it and returns result of work on finish via the same socket.
3. Worker manager checks if worker thread has done and read result of work. To do this call `checkForFinish()`.
4. Worker stops or being killed by `stop()` or `kill()` methods respectively.
5. Worker manager checks if worker thread has finished and marks itself terminated. To do this call `checkForTermination()`.

Background work happens in **2 steps**, where worker thread runs `onPayload($data)` method of class with actual payload.

To summarize, this is an example of downloading file in another thread with real-time displaying of progress:

**Settings and structures**
```php
// Implement class-downloader
class DownloadWorker extends Worker
{
    public function onPayload($data)
    {
        echo 'Started '.$data[0].' into '.$data[2].PHP_EOL;
        copy($data[0], $data[2]);
    }
}

// supplementary function, just to avoid hand-writing of file sizes
function remote_filesize($path)
{
    $fp = fopen($path, 'r');
    $inf = stream_get_meta_data($fp);
    fclose($fp);
    foreach($inf["wrapper_data"] as $v) {
        if (stristr($v,"content-length")) {
            $v = explode(":",$v);
            return (int)trim($v[1]);
        }
    }
}

// our function to print actual status of downloads
function show_status(&$files)
{
    foreach ($files as $i => $file) {
        if (file_exists($file[2])) {
            clearstatcache(true, $file[2]);
            $downloaded_size = filesize($file[2]);
            if ($downloaded_size == $file[1]) {
                echo $file[0].' downloaded'.PHP_EOL;
                unset($files[$i]);
                unlink($file[2]);
            } else if ($downloaded_size === 0) {
                // echo $file[0].' in queue'.PHP_EOL;
            } else  {
                echo $file[0].' downloading '.round($downloaded_size * 100 / $file[1], 2).'%'.PHP_EOL;
            }
        }
    }
}

// list of files to be downloaded
$file_sources = ['https://yandex.ru/images/today?size=1920x1080', 'http://hosting-obzo-ru.1gb.ru/hosting-obzor.ru.zip'];
// process of remote file size detection and creation temp local file for this downloading
$files = [];
foreach ($file_sources as $file_to_download) {
    $file_size = remote_filesize($file_to_download);
    $output = tempnam(sys_get_temp_dir(), 'thrd_test');
    $files[] = [$file_to_download, $file_size, $output];
}
```

**Real work**
```php
// construct and start new worker
$worker = new DownloadWorker();
// or if you want to simulate forking
$worker = new DownloadWorker(true);

// add files to work queue
foreach ($files as $file) {
    echo 'Enqueuing '.$file[0].' with size '.$file[1].PHP_EOL;
    $worker->sendPayload([$file]);
}

// main worker thread loop
while ($worker->state !== Worker::TERMINATED) {
    // Worker::RUNNING state indicates that worker thread is still working over some payload
    if ($worker->state == Worker::RUNNING) {

        // prints status of all files
        show_status($files);
        // call check for finishing all tasks
        $worker->checkForFinish();
        usleep(500000);
    }
    // Worker::IDLE state indicates that worker thread does not have any work right now
    else if ($worker->state == Worker::IDLE) {
        echo 'Ended. Stopping worker...'.PHP_EOL;
        // we don't need worker anymore, just stop it
        $worker->stop();
        usleep(500000);
    }
    // Worker::TERMINATING state indicates that worker thread is going to be stopped and can't be used to process data
    else if ($worker->state == Worker::TERMINATING) {
        echo 'Wait for terminating ...'.PHP_EOL;
        // just to set Worker::TERMINATED state
        $worker->checkForTermination();
        usleep(500000);
    }
}
```
_Result:_
```sh
Enqueuing https://yandex.ru/images/today?size=1920x1080 with size 343103
Enqueuing http://hosting-obzo-ru.1gb.ru/hosting-obzor.ru.zip with size 52693477
Started https://yandex.ru/images/today?size=1920x1080 into /tmp/thrd_test0Y3i3k
Started http://hosting-obzo-ru.1gb.ru/hosting-obzor.ru.zip into /tmp/thrd_testrwwYiE
https://yandex.ru/images/today?size=1920x1080 downloaded
http://hosting-obzo-ru.1gb.ru/hosting-obzor.ru.zip downloading 28.89%
http://hosting-obzo-ru.1gb.ru/hosting-obzor.ru.zip downloading 66.06%
http://hosting-obzo-ru.1gb.ru/hosting-obzor.ru.zip downloaded
Ended. Stopping worker...
Wait for terminating ...
```

This code equipped with a lot of comments, but you can simplify this example if you don't need to re-use worker when all your work is done.
You can replace this huge loop with a smaller one:

```php
// loops works only when worker is running.
// just to show information about downloaded files
while ($worker->state == Worker::RUNNING) {
    show_status($files);
    $worker->checkForFinish();
    usleep(500000);
}
// when thread is in idle state, just stop right now (`true` as 1st argument forces it to send stop command and wait it termination).
$worker->stop(true);
```
_Result:_
```sh
Enqueuing https://yandex.ru/images/today?size=1920x1080 with size 343103
Enqueuing http://hosting-obzo-ru.1gb.ru/hosting-obzor.ru.zip with size 52693477
Started https://yandex.ru/images/today?size=1920x1080 into /tmp/thrd_testbGsRBp
Started http://hosting-obzo-ru.1gb.ru/hosting-obzor.ru.zip into /tmp/thrd_testv0E5Qy
https://yandex.ru/images/today?size=1920x1080 downloaded
http://hosting-obzo-ru.1gb.ru/hosting-obzor.ru.zip downloading 17.4%
http://hosting-obzo-ru.1gb.ru/hosting-obzor.ru.zip downloading 36.82%
http://hosting-obzo-ru.1gb.ru/hosting-obzor.ru.zip downloading 55.95%
http://hosting-obzo-ru.1gb.ru/hosting-obzor.ru.zip downloading 76%
http://hosting-obzo-ru.1gb.ru/hosting-obzor.ru.zip downloading 95.05%
http://hosting-obzo-ru.1gb.ru/hosting-obzor.ru.zip downloaded
```

## Few workers with WorkersPool

But what if you need do few jobs simultaneously? You can create few instances of your worker, but it will be pain in the a$$ to manipulate and synchronize them.
In this case you can use `WorkersPool`, which takes care of following this:

1. Start new workers at the beginning.
2. Dispatch your payload when you call **sendData** to any idle worker*.
3. Create new workers or delete redundant workers when you change **poolSize**.
4. Accept result of workers when they done and marks them as idle.
5. Monitor all worker threads and count idle, running, active (idle or running) workers. Provides interfaces to acquire this information.
6. Stop all workers when `WorkersPool` object is being destructed (via `unset()` or when script execution is going down).
7. *Can work in _dataOverhead_-mode. This mode enables sending extra payload to workers even when are already working on any task. If in this mode you sent few payloads to worker, it will not switch to **Worker::IDLE** state until all passed payloads have been processed.
8. Provide interface to appoint progress trackers and run them periodically until all threads become in `Worker::IDLE` state.

Rich feature-set, right?! Let's rewrite our downloader with 2 threads to speed-up downloading.

The **Settings and structures** block of code remains the same, but for demonstating purposes let's use two big files:
```php
// ...
$file_sources = ['http://hosting-obzo-ru.1gb.ru/hosting-obzor.ru.zip', 'http://soft.eurodir.ru/test-speed-100Mb.bin'];
// ...
```
We need to update only code working with threads.

```php
// create pool with downloading-workers
$pool = new WorkersPool('DownloadWorker');
/**
 * Also, you can create pool out of object:
 * $pool = new WorkersPool(new DownloadWorker());
 * This is useful, when you open shared sources within worker constructor so all workers can use them.
 */
// use only 2 workers (this is enough for our work)
$pool->setPoolSize(2);

// dispatch payload to workers. Notice! WorkersPool uses sendData() method instead of sendPayload().
foreach ($files as $file) {
    echo 'Enqueuing '.$file[0].' with size '.$file[1].PHP_EOL;
    $pool->sendData($file);
}

// register tracker, which should be launched every 0.5 seconds.
// This method will hold the execution until all workers finish their work and go in Worker::IDLE state
$pool->waitToFinish([
    '0.5' => function ($pool) use (&$files) {
        show_status($files);
    }]
);
```
_Result:_
```sh
Enqueuing http://hosting-obzo-ru.1gb.ru/hosting-obzor.ru.zip with size 52693477
Enqueuing http://soft.eurodir.ru/test-speed-100Mb.bin with size 102854656
Started http://hosting-obzo-ru.1gb.ru/hosting-obzor.ru.zip into /tmp/thrd_testchcHBK
Started http://soft.eurodir.ru/test-speed-100Mb.bin into /tmp/thrd_testt6dyJa
http://hosting-obzo-ru.1gb.ru/hosting-obzor.ru.zip downloading 23.26%
http://soft.eurodir.ru/test-speed-100Mb.bin downloading 1.3%
http://hosting-obzo-ru.1gb.ru/hosting-obzor.ru.zip downloading 47.08%
http://soft.eurodir.ru/test-speed-100Mb.bin downloading 3.08%
http://hosting-obzo-ru.1gb.ru/hosting-obzor.ru.zip downloading 72.62%
http://soft.eurodir.ru/test-speed-100Mb.bin downloading 5.66%
http://hosting-obzo-ru.1gb.ru/hosting-obzor.ru.zip downloading 98.7%
http://soft.eurodir.ru/test-speed-100Mb.bin downloading 8.05%
http://hosting-obzo-ru.1gb.ru/hosting-obzor.ru.zip downloaded
http://soft.eurodir.ru/test-speed-100Mb.bin downloading 19.15%
http://soft.eurodir.ru/test-speed-100Mb.bin downloading 31.31%
http://soft.eurodir.ru/test-speed-100Mb.bin downloading 43.69%
http://soft.eurodir.ru/test-speed-100Mb.bin downloading 56.87%
http://soft.eurodir.ru/test-speed-100Mb.bin downloading 71.95%
http://soft.eurodir.ru/test-speed-100Mb.bin downloading 87.56%
```

As you can see, we got few improvements:

1. Our code became smaller and clearer.
2. We can run as many workers as we need.
3. We don't take care of worker termination anymore. Let WorkersPool work for us.

# API
## Worker API

- `sendPayload($data): int` - sends payload to worker and returns serial id for payload.
- `checkForFinish(): array|null` - checks if worker sent result of payload and returns it in this case.
- `checkForTermination(): boolean|null` - returns true if worker process has died.
- `stop($wait = false): boolean` - sends stop command to worker thread. It uses _SIGTERM_ signal to allow worker thread finish work correctly and don't lose any data. If `$wait = true`, holds the execution until the worker is down.
- `kill($wait = false): boolean` - sends stop command to worker thread. It uses _SIGKILL_ signal and not recommended except special cases, because it simply kills the worker thread and it loses all data being processed in that moment. If `$wait = true`, holds the execution until the worker is down.

Information:
- `isActive(): boolean` - true if worker is in `Worker::RUNNING` or `Worker::IDLE` states.
- `isRunning(): boolean` - true if worker is in `Worker::RUNNING` state.
- `isIdle(): boolean` - true if worker is in `Worker::IDLE` state.
- `getPid(): int` - returns process id of worker.
- `getCurrentPayload(): int` - returns serial number of last done payload.

**Warning about worker re-using!** You can't restart a worker that has been terminated (with `stop()` or `kill()`), you need to create new worker and start it with `start()`.

## WorkersPool API

- `countIdleWorkers(): integer` - returns number of workers that are in `Worker::IDLE` state.
- `countRunningWorkers(): integer` - returns number of workers that are in `Worker::RUNNING` state.
- `countActiveWorkers(): integer` - returns number of workers that are either in `Worker::RUNNING` or `Worker::IDLE` states.
- `getRunningWorkers(): Worker[]` - returns workers that are in `Worker::RUNNING` state.
- `enableDataOverhead()` / `disableDataOverhead()` - enables/disables _dataOverhead_-mode.
- `sendData($data, $wait = false): null|boolean` - dispatches payload to any free worker. Behavior depends on _dataOverhead_ feature status:
    - When _dataOverhead_ is disabled and `$wait = false` (by default), this method returns `null` when no free workers available or `boolean` with status of dispatching (`true/false`).
    - When _dataOverhead_ is disabled (by default) and `$wait = true`, this method will hold the execution of the script until any worker became free, dispatch your payload to it and return the status of dispatching (`true/false`).
    - When _dataOverhead_ is enabled, this method will dispatch your payload to any free worker. If there's not free workers, it will put new tasks in workers internal queues, which will be processed. This method uses fair distribution between all workers (so you can be sure that 24 tasks will be distributed between 6 workers as 4 per worker).

- `waitToFinish(array $trackers = null)` - holds the execution of script untill all workers go into `IDLE` state.

# Predefined workers
## DownloadWorker

As you've seen in examples, we created a downloading worker. But there is no need for this, we could use predefined `DownloadWorker` which does the same.

- Full path: `wapmorgan\Threadable\DownloadWorker`
- Description: downloads remote file and saves it on local server.
- Payload (array):
    - `source` - remote file url
    - `target` - local file path

## ExtractWorker

Zip-archives extractor.

- Full path: `wapmorgan\Threadable\ExtractWorker`
- Description: extracts given zip archive to a folder
- Payload (array):
    - `archive` - archive filename
    - `output` - output directory

# Use cases

Examples of programs that can be built with `Threadable`:

- Media converters / encoders
- Data importers / exporters
- Bots for social networks / messengers
- Parsers / Scanners / Analyzers
- Servers (_don't recommend unless you want to reinvent the wheel_)
- ...
