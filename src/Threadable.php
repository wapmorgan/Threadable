<?php
namespace wapmorgan\Threadable;

use Exception;

trait Threadable
{
    /**
     * Forks current process.
     * The parent thread will be returned to calling code.
     * The child thread will execute callback and exit after that.
     * @return integer The ID of child process
     */
    public function fork($callback, array $params = [])
    {
        $res = pcntl_fork();
        if ($res < 0)
            throw new Exception('Can\'t fork');

        if ($res === 0) {
            call_user_func_array($callback, $params);
            exit;
        } else {
            return $res;
        }
    }
}
