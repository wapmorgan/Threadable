<?php
namespace wapmorgan\Threadable;

use Exception;

class Worker
{
    use Threadable;

    const RUNNING = 1;
    const IDLE = 2;
    const TERMINATING = 3;
    const TERMINATED = 4;

    // parent properties
    public $state = self::IDLE;
    protected $pid;
    protected $toChild;

    // child properties
    protected $parentPid;
    protected $toParent;
    protected $termAccepted = false;

    public function start()
    {
        $sockets = [];
        socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $sockets);
        $pid = getmypid();

        $this->pid = $this->fork([$this, 'onWorkerStart'], [$sockets[1], $pid]);
        $this->toChild = $sockets[0];
    }

    public function stop($wait = false)
    {
        if (!is_dir('/proc/'.$this->pid))
            return null;

        $this->state = self::TERMINATING;

        posix_kill($this->pid, SIGTERM);

        if ($wait) {
            pcntl_waitpid($this->pid, $status);
            $this->state = self::TERMINATED;
        } else {
            if (!is_dir('/proc/'.$this->pid))
                $this->state = self::TERMINATED;
        }
    }

    public function kill($wait = false)
    {
        if (!is_dir('/proc/'.$this->pid))
            return null;

        $this->state = self::TERMINATING;

        posix_kill($this->pid, SIGKILL);

        if ($wait) {
            pcntl_waitpid($this->pid, $status);
            $this->state = self::TERMINATED;
        } else {

            if (!is_dir('/proc/'.$this->pid))
                $this->state = self::TERMINATED;

        }
    }

    public function isRunning()
    {
        return $this->state == self::RUNNING;
    }

    public function checkForFinish()
    {
        if (strlen($msg_size_bytes = socket_read($this->toChild, 4)) > 0) {
            $data = unpack('N', $msg_size_bytes);
            $msg_size = $data[1];

            $msg = socket_read($this->toChild, $msg_size);
            $data = unserialize($msg);

            // mark as idle
            $this->state = self::IDLE;
        }
    }

    public function checkForTermination()
    {
        $pid = pcntl_waitpid($this->pid, $status, WNOHANG);
        var_dump($pid);
        if ($pid == $this->pid) {
            $this->state = self::TERMINATED;
        }
    }

    public function sendPayload($data)
    {
        $this->state = self::RUNNING;

        $data = serialize($data);
        // write payload to socket
        socket_write($this->toChild, pack('N', strlen($data)));
        socket_write($this->toChild, $data);
    }

    public function getPid()
    {
        return $this->pid;
    }

    ///
    /// Child methods below only
    ///

    protected function onWorkerStart($socket, $parentPid)
    {
        // set signal handler
        pcntl_signal(SIGTERM, [$this, 'onSigTerm']);

        $this->toParent = $socket;
        $this->parentPid = $parentPid;
        socket_set_block($this->toParent);
        while (!$this->termAccepted && strlen($msg_size_bytes = socket_read($this->toParent, 4)) > 0) {
            $data = unpack('N', $msg_size_bytes);
            $msg_size = $data[1];

            $msg = socket_read($this->toParent, $msg_size);
            $data = unserialize($msg);

            // launch worker to do it's work
            $result = call_user_func_array([$this, 'onPayload'], $data);
            $data = serialize($result);

            // write result to socket
            socket_write($this->toParent, pack('N', strlen($data)));
            socket_write($this->toParent, $data);

            // send SIGUSR1 to parent to indicate that's we've finished
            posix_kill($this->parentPid, SIGUSR1);
        }

        // notify we ended
        posix_kill($this->parentPid, SIGUSR2);
    }

    public function onPayload($abc)
    {
        echo 'I\'m just a worker with pid '.getmypid().'. Got payload: '.$abc.PHP_EOL;
        return 123;
    }

    public function onSigTerm()
    {
        $this->termAccepted = true;
    }
}
