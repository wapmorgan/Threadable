<?php
declare(ticks = 1);
namespace wapmorgan\Threadable;

use Exception;

/**
 * Used signals:
 * - USR1 - childs notify worker pool that they finished work and have data in socket
 * - USR2 - childs notify worker pool that they have been terminated
 * - CHLD - childs notify worker pool that they have been terminated (by system). Really, sometimes system send SIGCHLD, sometimes doesn't.
 * So CHLD just triggers USR2 handlers to check termination of all workers.
 */
class WorkersPool
{

    public $checkTime = 1;

    /**
     * One wait-unit - 100 msec.
     */
    const WAIT_UNIT_MILLITIME = 100;


    /**
     * @var string Worker class name
     */
    protected $class;
    /**
     * @var object Worker object
     */
    protected $object;

    protected $currentSize = 0;
    protected $newSize = 0;
    protected $workers = [];
    protected $dataOverhead = false;
    protected $overheadCounters = [];
    /**
     * Used to finish workers only when master thread terminates
     */
    protected $masterThreadId;
    /**
     * @var integer Milliseconds between checks for free workers
     */
    public $waitPeriod = 100;

    public function __construct($classOrObject)
    {
        if (is_string($classOrObject)) {
            if (!class_exists($classOrObject))
                throw new Exception('Worker class is not valid!');
            $this->class = $classOrObject;
        } else if (is_object($classOrObject)){
            if (!($classOrObject instanceof Worker))
                throw new Exception('Worker object is not a Worker child!');
            $this->object = $classOrObject->disableSelfManagment();
        } else {
            throw new Exception('Worker should be a class name or an object!');
        }

        if ($this->masterThreadId === null)
            $this->masterThreadId = getmypid();

        pcntl_signal(SIGUSR2, [$this, 'onSigUsr2']);
        pcntl_signal(SIGUSR1, [$this, 'onSigUsr1']);
        pcntl_signal(SIGCHLD, [$this, 'onSigChld']);
    }

    public function __destruct()
    {
        if (getmypid() === $this->masterThreadId) {
            // echo 'Destructing workers'.PHP_EOL;
            foreach ($this->workers as $worker) {
                if ($worker->isActive())
                    $worker->stop();
            }
        }
    }

    public function setPoolSize($newSize)
    {
        if ($this->newSize != $newSize)
            $this->newSize = $newSize;
    }

    public function countActiveWorkers()
    {
        $i = 0;
        foreach ($this->workers as $worker) {
            if (in_array($worker->state, [Worker::RUNNING, Worker::IDLE])) $i++;
        }
        return $i;
    }

    public function countRunningWorkers()
    {
        $i = 0;
        foreach ($this->workers as $worker) {
            if ($worker->state == Worker::RUNNING) $i++;
        }
        return $i;
    }

    public function countIdleWorkers()
    {
        $i = 0;
        foreach ($this->workers as $worker) {
            if ($worker->state === Worker::IDLE) $i++;
        }
        return $i;
    }

    public function enableDataOverhead()
    {
        $this->dataOverhead = true;
    }

    /**
     * @return null|boolean Null if not free workers available and $wait = false.
     */
    public function sendData($data, $wait = false)
    {
        $this->checkSize(true);
        if ($this->countIdleWorkers() === 0 && !$this->dataOverhead) {
            if (!$wait)
                return null;
            else {
                while ($this->countIdleWorkers() === 0) {
                    usleep($this->waitPeriod);
                }
            }
        }

        foreach ($this->workers as $i => $worker) {
            if ($worker->state == Worker::IDLE) {
                if ($this->overheadCounters[$i] > 0)
                    $this->overheadCounters[$i] = 0;
                return $worker->sendPayload($data);
            }
        }

        if ($this->dataOverhead) {
            asort($this->overheadCounters);
            foreach ($this->overheadCounters as $i => $counter) {
                if ($this->workers[$i]->isActive()) {
                    // echo 'Sending overhead data to '.$this->workers[$i]->getPid().PHP_EOL;
                    $this->overheadCounters[$i]++;
                    return $this->workers[$i]->sendPayload($data);
                }
            }
        }
    }

    public function onSigUsr1()
    {
        // echo 'SIGUSR1'.PHP_EOL;
        foreach ($this->workers as $worker) {
            $worker->checkForFinish();
        }
    }

    public function onSigUsr2()
    {
        // echo 'SIGUSR2'.PHP_EOL;
        foreach ($this->workers as $worker) {
            if ($worker->checkForTermination())
                echo 'Terminated '.$worker->getPid().PHP_EOL;
        }
    }

    public function onSigChld()
    {
        // echo 'SIGCHLD -> SIGUSR2'.PHP_EOL;
        $this->onSigUsr2();
    }

    protected function checkSize($waitIfNeeded = false)
    {
        if ($this->newSize == $this->currentSize)
            return true;

        // just emit new workers
        if ($this->newSize > $this->currentSize) {
            $diff = $this->newSize - $this->currentSize;
            for ($i = 0; $i < $diff; $i++) {
                $this->emitNewWorker();
            }
            $this->currentSize = $this->newSize;
            return true;
        }

        $diff = $this->currentSize - $this->newSize;
        $terminating = 0;
        $done = 0;
        $j = 0;
        // decrease worker pool
        while ($diff > $done) {
            if ($this->countIdleWorkers() > 0 || $terminating > 0) {
                $states = [];
                foreach ($this->workers as $i => $worker) {
                    $states[$worker->getPid()] = $worker->state;
                    if ($worker->state == Worker::IDLE) {
                        // echo 'Stopping worker '.$i.PHP_EOL;
                        $terminating++;
                        $worker->stop();
                    }
                    else if ($worker->state == Worker::TERMINATED) {
                        // echo 'Terminated worker '.$i.PHP_EOL;
                        $terminating--;
                        $done++;
                        unset($this->workers[$i], $this->overheadCounters[$i]);
                    }
                }
            }

            if (!$waitIfNeeded)
                break;

            sleep($this->checkTime);
        }
        $this->currentSize = count($this->workers);
    }

    protected function emitNewWorker()
    {
        // if worker is a class
        if ($this->class) {
            $class_name = $this->class;
            ($this->workers[] = new $class_name($this))->disableSelfManagment()->start();
        }
        // if object
        else {
            $worker = clone $this->object;
            ($this->workers[] = $worker)->start();
        }
        $this->overheadCounters[] = 0;
    }

    public function getWorkers()
    {
        return $this->workers;
    }

    public function waitToFinish(array $trackers = [])
    {
        // convert seconds to wait units
        foreach ($trackers as $tracker_time => $tracker_callback) {
            unset($trackers[$tracker_time]);
            $tracker_units = $tracker_time * 1000 / static::WAIT_UNIT_MILLITIME;
            $trackers[$tracker_units] = $tracker_callback;
        }

        // unit counter
        $i = 0;
        while ($this->countRunningWorkers() > 0) {
            $i++;

            foreach ($trackers as $tracker_units => $tracker_callback) {
                if ($i % $tracker_units === 0) {
                    call_user_func($tracker_callback, $this);
                }
            }

            usleep(static::WAIT_UNIT_MILLITIME * 1000);
        }
    }

    public function getOverheadCounters()
    {
        return $this->overheadCounters;
    }
}
