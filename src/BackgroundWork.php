<?php
namespace wapmorgan\Threadable;

class BackgroundWork
{
	const BY_CPU_NUMBER = -1;

    /**
     * @param Worker $worker
     * @param array $payloads An array of all payloads to worker
     * @param null $payloadHandlingCallback
     * @param callable|null $onPayloadFinishCallback Callback that should be called every time one payload is fully handled with worker output.
     *                      Also, this callback should return either true or false to indicate that work done right.
     *                      This affects result of doInBackground() call.
     * @param int $sleepMicroTime Time between checks for worker state (in milliseconds)
     * @return bool True if all payloads successfully handled.
     * @throws \Exception
     */
    public static function doInBackground(Worker $worker, array $payloads, $payloadHandlingCallback = null,
                                          $onPayloadFinishCallback = null, $sleepMicroTime = 1000)
    {
        $worker->start();
        foreach ($payloads as $i => $payload)
            $worker->sendPayload($payload);

        $result = true;

        while ($worker->isRunning()) {
            // worker done a job
            if (($payload_result = $worker->checkForFinish()) !== null) {
                if ($onPayloadFinishCallback !== null)
                    $result = call_user_func($onPayloadFinishCallback, $worker, key($payloads), current($payloads), $payload_result[1]) && $result;
                else
                    $result = (boolean)$payload_result[1] && $result;
                next($payloads);
            } else
                call_user_func($payloadHandlingCallback, $worker, key($payloads), current($payloads));

            usleep($sleepMicroTime);
        }

        $worker->stop(true);

        return $result;
    }

    /**
     * @param Worker $worker
     * @param array $payloads An array of all payloads to worker
     * @param null $payloadHandlingCallback Callback that will be called every $sleepMicrotime seconds for every running worker at that monent.
     *                        Should have signature: callback(Worker $worker, $payloadNumber, $payload)
     * @param callable|null $onPayloadFinishCallback Callback that should be called every time one payload is fully handled with worker output.
     *                      Also, this callback should return either true or false to indicate that work done right.
     *                      This affects result of doInBackground() call.
     * @param int $sleepMicroTime Time between checks for worker state (in milliseconds)
     * @param int $poolSize Size of worker's pool. If one of BackgroundWork constants, will be calculated automatically.
     * @return bool True if all payloads successfully handled.
     * @throws \Exception
     */
    public static function doInBackgroundParallel(Worker $worker, array $payloads, $payloadHandlingCallback = null,
                                          $onPayloadFinishCallback = null, $sleepMicroTime = 1000, $poolSize = self::BY_CPU_NUMBER)
    {
        $workers_pool = new WorkersPool($worker);

        if ($poolSize === self::BY_CPU_NUMBER) {
        	if (($poolSize = self::getNumberOfCpuCores()) === false)
        		$poolSize = 4;
		}

        $workers_pool->setPoolSize($poolSize);
        $workers_pool->enableDataOverhead();

        // [payload_i][worker_pid] => payload_i_for_worker
        $dispatched_payloads = [];
        $current_payloads = [];

        $result = true;

        $workers_pool->registerOnPayloadFinishCallback(function (Worker $worker, $payloadI, $payloadResult)
        use ($current_payloads, $onPayloadFinishCallback, $payloads, &$result, &$dispatched_payloads) {
            $worker_payload = $dispatched_payloads[$worker->getPid()][$payloadI];
            $result = call_user_func($onPayloadFinishCallback, $worker, $worker_payload, $payloads[$worker_payload], $payloadResult) && $result;
            unset($current_payloads[$worker->getPid()]);
        });

		// if not all payloads dispatched and there's idle worker -> dispatch
		if (count($dispatched_payloads) < count($payloads)/* && $workers_pool->countIdleWorkers() > 0*/) {
			foreach ($payloads as $i => $payload) {
				$dispatch_result = $workers_pool->sendData($payload);
				$dispatched_payloads[$dispatch_result[0]->getPid()][$dispatch_result[1]] = $i;
			}
		}

		// launch callback with running workers and their's payloads
		while ($workers_pool->countRunningWorkers() > 0) {
			if ($payloadHandlingCallback !== null) {
				foreach ($workers_pool->getRunningWorkers() as $runningWorker) {
                    $worker_payload = $dispatched_payloads[$runningWorker->getPid()][$runningWorker->getCurrentPayload()];
					call_user_func($payloadHandlingCallback, $runningWorker, $worker_payload, $payloads[$worker_payload]);
				}
			}

            usleep($sleepMicroTime);
        }

        return $result;
    }

	/**
	 * @return int|boolean Number of cpu cores or false
	 */
	protected static function getNumberOfCpuCores()
	{
		if (!file_exists('/proc/cpuinfo'))
			return false;

		exec('grep \'model name\' /proc/cpuinfo | wc -l', $output, $commandResult);
		if ($commandResult !== 0)
			return false;
		$cores = (int)trim($output[0]);
		return $cores > 0 ? $cores : false;
	}
}