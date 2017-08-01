# Threadable

Easy-to-use threading library providing all basic features to run your code in parallel mode.
All you need to have installed: _pcntl_ and _posix_ extensions.

[![Composer package](http://xn--e1adiijbgl.xn--p1acf/badge/wapmorgan/threadable)](https://packagist.org/packages/wapmorgan/threadable)
[![Latest Stable Version](https://poser.pugx.org/wapmorgan/threadable/v/stable)](https://packagist.org/packages/wapmorgan/threadable)
[![Latest Unstable Version](https://poser.pugx.org/wapmorgan/threadable/v/unstable)](https://packagist.org/packages/wapmorgan/threadable)
[![License](https://poser.pugx.org/wapmorgan/threadable/license)](https://packagist.org/packages/wapmorgan/threadable)

1. Structure
    - What is a `Threadable`?
    - What is a `Worker`?
        * How to create your Worker
    - What is a `WorkersPool`?
2. How to use
    - One worker
    - Few workers with `WorkerPool`
3. API
    - `Worker` secrets and important methods
    - `WorkersPool` features

# Structure
## What is a Threadable?
**Threadable** - is a trait for adding fork'ing functionality to any class. It's used in `Worker` class.

## What is a Worker?
**Worker** - is a basic class for any worker. It is composed of two substances (physically, stored in one class, but providing different functionalities):

1. A `Worker` - a separate thread, running main worker code.
2. A `Worker` - manipulation manager for the first item.

### How to create your Worker

The all you need it to inherit `Worker` class (full name is _wapmorgan\Threadable\Worker_) and redefine `onPayload(...$args)` public method.

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
    }
}
```

## What is a WorkersPool?
**WorkersPool** (_wapmorgan\Threadable\WorkersPool_) - is a container for `Worker`'s, intended for handling similar tasks.
It takes care of all maintenance, payload dispatching and life-cycle of workers. Allows you change the size of the pool dynamically and other useful stuff.

# How to use

## One worker

If you just need to parallel some work and do it in another thread, you can utilize just `Worker` class without any other dependencies. 

To use it correctly you need to understand the life-cycle of worker:

1. Worker starts in another thread. To do this call `start()`.
2. Worker accepts new payload and starts working on it. Really, manager of worker send payload via local socket. Worker thread starts working on it and returns result of work on finish via the same socket. To do this call `sendPayload($data)`.
3. Worker manager checks if worker thread has done and read result of work. To do this call `checkForFinish()`.
4. Worker stops or being killed by `stop()` or `kill()` methods respectively.
5. Worker manager checks if worker thread has finished and marks itself terminated. To do this call `checkForTermination()`.

Background work happens in **2 step**, where worker thread runs `onPayload($data)` method of class with actual payload.

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
$worker->start();
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

## Few workers with WorkerPool

But what if you need do few jobs simultaneously? You can create few instances of your worker, but it will be pain in the a$$ to manipulate and synchronize them.
In this case you can use `WorkerPool`, which takes care of following this:

1. Start new workers at the beginning.
2. Dispatch your payload when you call **sendData** to any idle worker*.
3. Create new workers or delete redundant workers when you change **poolSize**.
4. Accept result of workers when they done and marks them as idle.
5. Monitor all worker threads and count idle, running, active (idle or running) workers. Provides interfaces to acquire this information.
6. Stop all workers when `WorkerPool` object is being destructed (via `unset()` or when script execution is going down).
7. *Can work in _dataOverhead_-mode. This mode enables sending extra payload to workers even when are already working on any task. If in this mode you sent few payloads to worker, it will not switch to **Worker::IDLE** state until all passed payloads have been processed.
8. Provide interface to appoint progress trackers and run them periodically until all threads become in `Worker::IDLE` state.

Rich feature-set, right?! Let's rewrite our downloader with 2 threads to speed-up downloading.

The **Settings and structures** block of code remains the same. We need to update only code working with threads.

```php
// create pool with downloading-workers
$pool = new WorkerPool('DownloadWorker');
// use only 2 workers (this is enough for our work)
$pool->setPoolSize(2);

// dispatch payload to workers. Notice! WorkerPool uses sendData() method instance of sendPayload().
foreach ($files as $file) {
    echo 'Enqueuing '.$file[0].' with size '.$file[1].PHP_EOL;
    $pool->sendData([$file]);
}

// register tracker, which should be launched every 0.5 seconds.
// This method will hold the execution until all workers finish their work and go in Worker::IDLE state 
$pool->waitToFinish([
    '0.5' => function ($pool) use (&$files) {
        show_status($files);
    }]
);
``` 

As you can see, we got few improvements:

1. Our code became smaller and clearer.
2. We can run as many workers as we need.
3. We don't take care of worker termination anymore. Let WorkerPool do it for us.

## API
### Worker secrets and important methods

- `stop($wait = false)` - sends stop command to worker thread. It uses _SIGTERM_ signal to allow worker thread finish work correctly and don't lose any data. If `$wait = true`, holds the execution until the worker is down.
- `kill($wait = false)` - sends stop command to worker thread. It uses _SIGKILL_ signal and not recommended except special cases, because it simply kills the worker thread and it loses all data being processed in that moment. If `$wait = true`, holds the execution until the worker is down.

**Warning about worker re-using!** You can't restart a worker that has been terminated (with `stop()` or `kill()`), you need to create new worker and start it with `start()`.

### WorkersPool features

- `countIdleWorkers(): integer` - returns number of workers that are in `Worker::IDLE` state.
- `countRunningWorkers(): integer` - returns number of workers that are in `Worker::RUNNING` state.
- `countActiveWorkers(): integer` - returns number of workers that are either in `Worker::RUNNING` or `Worker::IDLE` states.
- `enableDataOverhead()` - enables _dataOverhead_-mode.
- `sendData($data, $wait = false): null|boolean` - dispatches payload to any free worker. Behavior depends on _dataOverhead_ feature status:
    - When _dataOverhead_ is disabled and `$wait = false` (by default), this method returns `null` when no free workers available or `boolean` with status of dispatching (`true/false`).
    - When _dataOverhead_ is disabled (by default) and `$wait = true`, this method will hold the execution of the script until any worker became free, dispatch your payload to it and return the status of dispatching (`true/false`). 
    - When _dataOverhead_ is enabled, this method will dispatch your payload to any free worker. If there's not free workers, it will put new tasks in workers internal queues, which will be processed. This method uses fair distribution between all workers (so you can be sure that 24 tasks will be distributed between 6 workers as 4 per worker).         

- `waitToFinish(array $trackers = null)`
