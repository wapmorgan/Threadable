<?php
namespace wapmorgan\Threadable;


class BackgroundWork
{
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
                    $result = call_user_func($onPayloadFinishCallback, key($payloads), current($payloads), $payload_result[1]) && $result;
                else
                    $result = (boolean)$payload_result[1] && $result;
                next($payloads);
            } else
                call_user_func($payloadHandlingCallback, key($payloads), current($payloads));

            usleep($sleepMicroTime);
        }

        $worker->stop(true);

        return $result;
    }

//    /**
//     * @param Worker $worker
//     * @param array $payloads An array of all payloads to worker
//     * @param null $payloadHandlingCallback
//     * @param callable|null $onPayloadFinishCallback Callback that should be called every time one payload is fully handled with worker output.
//     *                      Also, this callback should return either true or false to indicate that work done right.
//     *                      This affects result of doInBackground() call.
//     * @param int $sleepMicroTime Time between checks for worker state (in milliseconds)
//     * @return bool True if all payloads successfully handled.
//     * @throws \Exception
//     */
//    public static function doInBackgroundParallel(Worker $worker, array $payloads, $payloadHandlingCallback = null,
//                                          $onPayloadFinishCallback = null, $sleepMicroTime = 1000)
//    {
//        $workers_pool = new WorkersPool($worker);
//        $workers_pool->setPoolSize(4);
//
//        $dispatched_payloads = [];
//        $current_payloads = [];
//
//        $result = true;
//        $last_payload = 0;
//
//        $workers_pool->registerOnPayloadFinishCallback(function (Worker $worker, array $payloadResult)
//        use ($current_payloads, $onPayloadFinishCallback, $payloads, &$result) {
//            $result = call_user_func($onPayloadFinishCallback, $current_payloads[$worker->getPid()], $payloads[$current_payloads[$worker->getPid()]], $payloadResult) && $result;
//            unset($current_payloads[$worker->getPid()]);
//        });
//
//        while (count($dispatched_payloads) < count($payloads) && $workers_pool->countRunningWorkers() > 0) {
//
//            if (count($dispatched_payloads) < count($payloads) && $workers_pool->countIdleWorkers() > 0) {
//                for ($i = $last_payload; $i < count($payloads); $i++) {
//                    if (($dispatch_result = $workers_pool->sendData($payloads[$i])) === null)
//                        continue;
//                    else {
//                        $dispatched_payloads[$i] = $dispatch_result;
//                        $current_payloads[$worker->getPid()] = $i;
//                        $last_payload = $i;
//                    }
//                }
//            }
//
//            call_user_func($payloadHandlingCallback, key($payloads), current($payloads));
//
//            usleep($sleepMicroTime);
//        }
//
//        $workers_pool->waitToFinish();
//
//        return $result;
//    }
}