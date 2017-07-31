<?php
declare(ticks = 1);
namespace wapmorgan\Threadable;

use Exception;

/**
 * Used signals:
 * - USR1 - childs notify worker pool that they finished work and have data in socket
 * - USR2 - childs notify worker pool that they have been terminated
 */
class WorkerPool
{
    protected $class;
    protected $currentSize = 0;
    protected $newSize = 0;
    protected $workers = [];
    /**
     * @var integer Milliseconds between checks for free workers
     */
    public $waitPeriod = 100;

    public function __contruct($class)
    {
        if (!class_exists($class))
            throw new Exception('Worker class is not valid!');
        $this->class = $class;

        pcntl_signal(SIGUSR2, [$this, 'onSigUsr2']);
        pcntl_signal(SIGUSR1, [$this, 'onSigUsr1']);
        pcntl_signal(SIGCHLD, [$this, 'onSigChld']);
    }

    public function setPoolSize($newSize)
    {
        if ($this->newSize != $newSize)
            $this->newSize = $newSize;
    }

    public function countFreeWorkers()
    {
        $i = 0;
        foreach ($this->workers as $worker)
            if ($worker->state == Worker::IDLE) $i++;
        return $i;
    }

    public function countRunningWorkers()
    {
        $i = 0;
        foreach ($this->workers as $worker)
            if ($worker->state == Worker::RUNNING) $i++;
        return $i;
    }

    public function countIdleWorkers()
    {
        $i = 0;
        foreach ($this->workers as $worker)
            if ($worker->state == Worker::IDLE) $i++;
        return $i;
    }

    /**
     * @return null|boolean Null if not free workers available and $wait = false.
     */
    public function sendData($data, $wait = false)
    {
        $this->checkSize(true);
        if ($this->countFreeWorkers() === 0) {
            if (!$wait)
                return null;
            else {
                while ($this->countFreeWorkers() === 0) {
                    usleep($this->waitPeriod);
                }
            }
        }

        foreach ($this->workers as $worker) {
            if ($worker->state == Worker::IDLE) {
                return $worker->sendPayload($data);
            }
        }
    }

    public function onSigUsr1()
    {
        echo 'SIGUSR1'.PHP_EOL;
        foreach ($this->workers as $worker) {
            $worker->checkForFinish();
        }
    }

    public function onSigUsr2()
    {
        echo 'SIGUSR2'.PHP_EOL;
        foreach ($this->workers as $worker) {
            $worker->checkForTermination();
        }
    }

    public function onSigChld()
    {
        echo 'SIGCHLD'.PHP_EOL;
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
            return true;
        }

        // decrease worker pool
        while ($waitIfNeeded) {

        }
    }

    protected function emitNewWorker()
    {
        $class_name = $this->class;
        $this->workers = new $class_name();
    }
}
