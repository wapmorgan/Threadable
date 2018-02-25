<?php
use wapmorgan\Threadable\Worker;
use wapmorgan\Threadable\WorkersPool;

require_once dirname(__DIR__).'/vendor/autoload.php';

class CpuEatingWorker extends Worker
{
    public function onPayload(array $data)
    {
        $till = microtime(true) + 5;
        while (microtime(true) < $till) {
            // do nothing ..
        }
        return $data[0];
    }
}

class CpuEatingWorkerTest extends PHPUnit_Framework_TestCase
{
    public function test()
    {
        $worker = new CpuEatingWorker();
        try {
            $worker->start();
        } catch (Exception $e) {
            $this->fail('Worker can not start: '.$e->getMessage());
        }
        $this->assertEquals(Worker::IDLE, $worker->state);

        $payloads = [[123], [456], [789]];
        foreach ($payloads as  $payload)
            $worker->sendPayload($payload);

        $this->assertEquals(Worker::RUNNING, $worker->state);

        $payload_i = 0;
        while ($worker->state != Worker::IDLE) {
            if ($worker->state == Worker::RUNNING) {
                $result = $worker->checkForFinish();
                if ($result !== null) {
                    $this->assertEquals($payloads[$payload_i][0], $result);
                    $payload_i++;
                } else
                    sleep(1);
            }
        }

        unset($worker);
    }

    public function testParallel()
    {
        try {
            $pool = new WorkersPool('CpuEatingWorker');
        } catch (Exception $e) {
            $this->fail('Workers can not start: '.$e->getMessage());
        }
        $pool->setPoolSize(8);
        $pool->enableDataOverhead();

        for ($i = 0; $i < 12; $i++)
            $pool->sendData([$i]);

        $this->assertEquals(8, $pool->countRunningWorkers());
        $this->assertEquals(0, $pool->countIdleWorkers());

        $pool->waitToFinish();
        $this->assertEquals(0, $pool->countRunningWorkers());
        var_dump($pool->getWorkersStates());
        $this->assertEquals(8, $pool->countIdleWorkers());

        unset($pool);
    }
}
