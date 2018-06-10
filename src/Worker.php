<?php
namespace wapmorgan\Threadable;

/**
 * Class providing basic thread functionality
 */
class Worker extends ForkingThread
{
    const RUNNING = 1;
    const IDLE = 2;
    const TERMINATING = 3;
    const TERMINATED = 4;

	/**
	 * @var bool If multi-threading simulation enabled
	 * Automatically enables when pcntl extension is not supported
	 */
    protected $simulation = false;
	/**
	 * @var array Buffer for payload result when simulation is enabled
	 */
	protected $simulationBuffer = [];

	/**
	 * @var bool Whether child should send signals when it's done
	 */
    protected $useSignals = false;

	/**
	 * @var bool Whether child should stop thread when destructing object
	 */
    protected $selfManaged = true;

    // parent properties
    /** @var int Child (worker) process state */
    public $state = self::IDLE;

    /** @var int Child (worker) process id */
    protected $pid;

    /** @var resource Socket to child (worker) process */
    protected $toChild;

    /** @var int Counter of remaining jobs */
    protected $remainingPayloadsCounter = 0;

    /** @var int Count of sent jobs */
    protected $sentPayloadsCounter = 0;

    /** @var int Count of handled jobs */
    protected $reportedPayloadsCounter = 0;

    // child properties
    /** @var int Id of parent process */
    protected $parentPid;
    /** @var resource Socket to parent process */
    protected $toParent;

    /** @var bool Indicator that SIGTERM has been received */
    protected $termAccepted = false;

    /** @var int Time in microseconds between checks for new payload from parent */
    public $checkMicroTime = 10000;

	/**
	 * Configures worker
	 * @param bool $simulation
	 */
    public function __construct($simulation = false)
    {
    	if ($simulation || !self::supportsForking())
    		$this->simulation = true;
    }

	/**
	 * @return bool
	 */
	public function isSimulated()
	{
		return $this->simulation;
	}

    /**
     * Disables self-management. Use only if you manage workers via WorkersPool.
     */
    public function disableSelfManagement()
    {
        $this->useSignals = true;
        $this->selfManaged = false;
        return $this;
    }

    public function __destruct()
    {
        // this is Worker manager thread
        if ($this->selfManaged && $this->pid !== null && $this->isActive())
            $this->stop(true);
    }

    /**
     * Starts a worker and begins listening for incoming payload.
	 * This method should not be invoked when new object is constructing
	 * because a Worker can be cloned before starting working.
     * @throws \Exception
     */
    public function start()
    {
    	if (!$this->simulation) {
			$sockets = [];
			socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $sockets);
			$pid = getmypid();

			$this->pid = $this->forkThread([$this, 'onWorkerStart'], [$sockets[1], $pid]);
			$this->toChild = $sockets[0];
			socket_set_nonblock($this->toChild);
		}
    }

    /**
     * Stops a worker and ends the process
     * @param boolean $wait If true, this method will hold execution till the process end
     * @return boolean Null, if worker is not active. False, if signal can't be sent to worker.
     * True, if signal sent OR signal sent and worker terminated (if $wait = true).
     */
    public function stop($wait = false)
    {
    	if ($this->simulation) {
			$this->state = self::TERMINATED;
			return true;
		}

        if (!is_dir('/proc/'.$this->pid))
            return null;

        $this->state = self::TERMINATING;

        if (!posix_kill($this->pid, SIGTERM))
            return false;

        if ($wait) {
            pcntl_waitpid($this->pid, $status);
            $this->state = self::TERMINATED;
        } else {
            if (!is_dir('/proc/'.$this->pid))
                $this->state = self::TERMINATED;
        }
        return true;
    }

    /**
     * Stops a worker and kills the process
     * @param boolean $wait If true, this method will hold execution till the process end
     * @return boolean Null, if worker is not active. False, if signal can't be sent to worker.
     * True, if signal sent OR signal sent and worker terminated (if $wait = true).
     */
    public function kill($wait = false)
    {
		if ($this->simulation) {
			$this->state = self::TERMINATED;
			return true;
		}

        if (!is_dir('/proc/'.$this->pid))
            return null;

        $this->state = self::TERMINATING;

        if (!posix_kill($this->pid, SIGKILL))
            return false;

        if ($wait) {
            pcntl_waitpid($this->pid, $status);
            $this->state = self::TERMINATED;
        } else {
            if (!is_dir('/proc/'.$this->pid))
                $this->state = self::TERMINATED;
        }
        return true;
    }

    /**
     * @return boolean Returns whether worker is running right now.
     */
    public function isRunning()
    {
        return $this->state === self::RUNNING;
    }

    /**
     * @return boolean Returns whether worker is in idle state.
     */
    public function isIdle()
    {
        return $this->state === self::IDLE;
    }

    /**
     * @return boolean Returns whether worker is active right now (running or idle).
     */
    public function isActive()
    {
        return in_array($this->state, [self::RUNNING, self::IDLE], true);
    }

    /**
     * @return array|mixed|null
     */
    public function checkForFinish()
    {
    	if ($this->simulation) {
    		if ($this->reportedPayloadsCounter < count($this->simulationBuffer)) {
				$payload_result = $this->simulationBuffer[$this->reportedPayloadsCounter];
				return [$this->reportedPayloadsCounter++, $payload_result];
			}
			return null;
		}

        if (strlen($msg_size_bytes = socket_read($this->toChild, 4)) === 4) {
            $data = unpack('N', $msg_size_bytes);
            $msg_size = $data[1];

            $msg = socket_read($this->toChild, $msg_size);
            $data = unserialize($msg);
            $this->remainingPayloadsCounter--;

            // mark as idle only if this last payload
            if ($this->remainingPayloadsCounter === 0)
                $this->state = self::IDLE;

            return [$this->reportedPayloadsCounter++, $data];
        }
        return null;
    }

    /**
     * @return null|boolean
     */
    public function checkForTermination()
    {
    	if ($this->simulation) {
    		return null;
		}

        $pid = pcntl_waitpid($this->pid, $status, WNOHANG);
        if ($pid === $this->pid) {
            $this->state = self::TERMINATED;
            socket_close($this->toChild);
            return true;
        }
        return null;
    }

	/**
	 * Sends payload to worker
	 * @param mixed $data
	 * @return int Serial number of payload
	 * @throws \Exception
	 */
    public function sendPayload($data)
    {
    	// if not forked yet
		if (!$this->simulation && $this->pid === null) {
			$this->start();
		}

        $this->remainingPayloadsCounter++;
        $this->state = self::RUNNING;

        if ($this->simulation) {
			$result = $this->sentPayloadsCounter++;
			$this->simulationBuffer[$result] = $this->onPayload($data);
			$this->state = self::IDLE;
			return $result;
		}

		$data = serialize($data);
		// write payload to socket
		socket_write($this->toChild, pack('N', strlen($data)));
		socket_write($this->toChild, $data);

        return $this->sentPayloadsCounter++;
    }

    /**
     * @return integer The process ID of the worker
     */
    public function getPid()
    {
        return $this->pid;
    }

	/**
	 * @return int
	 */
	public function getCurrentPayload()
	{
		return $this->reportedPayloadsCounter;
	}

    ///
    /// Child methods below only
    ///

    /**
     * The first method that is being called when the worker starts.
     * @param resource $socket Socket to communicate with master process and transport payload / result.
     * @param integer $parentPid The process ID of the parent
     */
    protected function onWorkerStart($socket, $parentPid)
    {
        declare(ticks = 1);
        // set signal handler
        pcntl_signal(SIGTERM, [$this, 'onSigTerm']);

        $this->toParent = $socket;
        $this->parentPid = $parentPid;
        socket_set_nonblock($this->toParent);
        $i = 0;
        while (!$this->termAccepted) {
            // echo ($i++).' try'.PHP_EOL;
            // sleep for sometime
            if (strlen($msg_size_bytes = socket_read($this->toParent, 4)) != 4) {
                usleep($this->checkMicroTime);
                continue;
            }
            $data = unpack('N', $msg_size_bytes);
            $msg_size = $data[1];

            $msg = socket_read($this->toParent, $msg_size);
            $data = unserialize($msg);

            // echo '[Payload] Msg('.$msg_size.'): '.$msg.PHP_EOL;

            // launch worker to do it's work
            $result = call_user_func([$this, 'onPayload'], $data);
            $data = serialize($result);

            // write result to socket
            socket_write($this->toParent, pack('N', strlen($data)));
            // echo '[Finish] Msg('.strlen($data).'): '.$data.PHP_EOL;
            socket_write($this->toParent, $data);

            // send SIGUSR1 to parent to indicate that's we've finished
            if ($this->useSignals)
                posix_kill($this->parentPid, SIGUSR1);
        }
        socket_close($this->toParent);
        // notify we ended
        if ($this->useSignals)
            posix_kill($this->parentPid, SIGUSR2);
    }

    /**
     * The main handler and work executor in worker. Accepts all payload and should do all the work to process it.
     */
    public function onPayload($payload)
    {
        echo 'I\'m just a worker with pid '.getmypid().'. Got payload: '.print_r($payload, true).PHP_EOL;
        return true;
    }


    /**
     * SIGTERM Handler to gracefully stop worker without losing any data.
     */
    public function onSigTerm()
    {
        $this->termAccepted = true;
    }
}
