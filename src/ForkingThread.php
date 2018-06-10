<?php
/**
 * Created by PhpStorm.
 * User: wapmo
 * Date: 10.06.2018
 * Time: 16:20
 */

namespace wapmorgan\Threadable;


use Exception;

class ForkingThread
{
	/**
	 * @return bool
	 */
	public static function supportsForking()
	{
		return function_exists('pcntl_fork');
	}

	/**
	 * Forks current process.
	 * The parent thread will be returned to calling code.
	 * The child thread will execute callback and exit after that.
	 * @param $callback
	 * @param array $params
	 * @return integer The ID of child process
	 * @throws Exception
	 */
	public function forkThread($callback, array $params = [])
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