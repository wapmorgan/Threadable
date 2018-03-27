<?php
declare(ticks = 1);
namespace wapmorgan\Threadable;

use Exception;

/**
 * Used signals:
 * - USR1 - children notify worker pool that they finished work and have data in socket
 * - USR2 - children notify worker pool that they have been terminated
 * - CHLD - children notify worker pool that they have been terminated (by system). Really, sometimes system send SIGCHLD, sometimes doesn't.
 * So CHLD just triggers USR2 handlers to check termination of all workers.
 */
class WorkersPool
{
    /**
     * One wait-unit - 100 msec.
     */
    const WAIT_UNIT_MILLITIME = 100;

    /** @var int Timeout for resize operations */
    public $checkTime = 1;

    /** @var integer Milliseconds between checks for free workers */
    public $waitPeriod = 100;

    /** @var string Worker class name */
    protected $class;

    /** @var object Worker object */
    protected $object;
    /** @var int Current size of pool. Used in setPollSize() */
    protected $currentSize = 0;

    /** @var int New size of pool. Used in setPollSize() */
    protected $newSize = 0;

    /** @var Worker[] */
    protected $workers = [];

    /** @var bool */
    protected $dataOverhead = false;

    /** @var array */
    protected $overheadCounters = [];

    /** @var int Id of master thread process. Contains id of process, in which WorkersPool object was created */
    protected $masterThreadId;

    /** @var null|callable Callback that runs when one of workers finish working on payload */
    protected $payloadFinishCallback;

    /**
     * WorkersPool constructor.
     * @param $classOrObject
     * @throws Exception
     */
    public function __construct($classOrObject)
    {
        if (is_string($classOrObject)) {
            if (!class_exists($classOrObject))
                throw new Exception('Worker class is not valid!');
            $this->class = $classOrObject;
        } else if (is_object($classOrObject)){
            if (!($classOrObject instanceof Worker))
                throw new Exception('Worker object is not a Worker child!');
            $this->object = $classOrObject->disableSelfManagement();
        } else {
            throw new Exception('Worker should be a class name or an object!');
        }

        if ($this->masterThreadId === null)
            $this->masterThreadId = getmypid();

        pcntl_signal(SIGUSR2, [$this, 'onSigUsr2']);
        pcntl_signal(SIGUSR1, [$this, 'onSigUsr1']);
        pcntl_signal(SIGCHLD, [$this, 'onSigChld']);
    }

    /**
     * Destruct workers if still active
     */
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

    /**
     * @param int $newSize
     */
    public function setPoolSize($newSize)
    {
        if ($this->newSize != $newSize)
            $this->newSize = $newSize;
    }

    /**
     * @return int
     */
    public function countActiveWorkers()
    {
        $i = 0;
        foreach ($this->workers as $worker) {
            if (in_array($worker->state, [Worker::RUNNING, Worker::IDLE])) $i++;
        }
        return $i;
    }

    /**
     * @return int
     */
    public function countRunningWorkers()
    {
        $i = 0;
        foreach ($this->workers as $worker) {
            if ($worker->state == Worker::RUNNING) $i++;
        }
        return $i;
    }

    /**
     * @return int
     */
    public function countIdleWorkers()
    {
        $i = 0;
        foreach ($this->workers as $worker) {
            if ($worker->state === Worker::IDLE) $i++;
        }
        return $i;
    }

	/**
	 * @return Worker[]
	 */
	public function getRunningWorkers()
	{
		$workers = [];
		foreach ($this->workers as $worker) {
			if ($worker->state == Worker::RUNNING)
				$workers[] = $worker;
		}
		return $workers;
	}

    /**
     * @return $this
     */
    public function enableDataOverhead()
    {
        $this->dataOverhead = true;
        return $this;
    }

    /**
     * @return $this
     */
    public function disableDataOverhead()
    {
        $this->dataOverhead = false;
        return $this;
    }

    /**
     * @param $data
     * @param bool $wait
     * @return array|null Returns array with Worker object and number of payload in worker's queue
     * @throws Exception
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
                return [$worker, $worker->sendPayload($data)];
            }
        }

        if ($this->dataOverhead) {
            asort($this->overheadCounters);
            foreach ($this->overheadCounters as $i => $counter) {
                if ($this->workers[$i]->isActive()) {
                    // echo 'Sending overhead data to '.$this->workers[$i]->getPid().PHP_EOL;
                    $this->overheadCounters[$i]++;
                    return [$this->workers[$i], $this->workers[$i]->sendPayload($data)];
                }
            }
        }
    }

    /**
     * @param null|callable $callback Callable should have signature (payload_i, payload_result)
     * @return $this
     * @throws Exception
     */
    public function registerOnPayloadFinishCallback($callback)
    {
        if ($callback !== null && !is_callable($callback))
            throw new Exception('Can not use '.print_r($callback, true).' as callback');
        $this->payloadFinishCallback = $callback;
        return $this;
    }

    /**
     *
     */
    public function onSigUsr1()
    {
        // echo 'SIGUSR1'.PHP_EOL;
        foreach ($this->workers as $i => $worker) {
            if (($payload_result = $worker->checkForFinish()) !== null) {
                if ($this->payloadFinishCallback !== null)
                    call_user_func($this->payloadFinishCallback, $worker, $payload_result[0], $payload_result[1]);
            }
        }
    }

    /**
     *
     */
    public function onSigUsr2()
    {
        // echo 'SIGUSR2'.PHP_EOL;
        foreach ($this->workers as $worker) {
            if ($worker->checkForTermination())
                echo 'Terminated '.$worker->getPid().PHP_EOL;
        }
    }

    /**
     *
     */
    public function onSigChld()
    {
        // echo 'SIGCHLD -> SIGUSR2'.PHP_EOL;
        $this->onSigUsr2();
    }

    /**
     * @param bool $waitIfNeeded
     * @return bool
     * @throws Exception
     */
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

    /**
     * @throws Exception
     */
    protected function emitNewWorker()
    {
        // if worker is a class
        if ($this->class) {
            $class_name = $this->class;
            ($this->workers[] = new $class_name($this))->disableSelfManagement()->start();
        }
        // if object
        else {
            $worker = clone $this->object;
            ($this->workers[] = $worker)->start();
        }
        $this->overheadCounters[] = 0;
    }

    /**
     * @return Worker[]
     */
    public function getWorkers()
    {
        return $this->workers;
    }

    /**
     * @param array $trackers
     */
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

    /**
     * @return array
     */
    public function getOverheadCounters()
    {
        return $this->overheadCounters;
    }

    /**
     * @return array
     */
    public function getWorkersStates()
    {
        $state = [];
        foreach ($this->workers as $worker)
            $state[] = $worker->state;
        return $state;
    }
}
