<?php
use wapmorgan\Threadable\Worker;

require_once dirname(__DIR__).'/vendor/autoload.php';

class SimpleWorker extends Worker
{
    public function onPayload(array $data)
    {
        sleep($data['timeout']);
        return $data['timeout'] * 2;
    }
}

class WorkerTest extends PHPUnit_Framework_TestCase
{
    public function testSimpleWorker()
    {

        $worker = new SimpleWorker();
        try {
            $worker->start();
        } catch (Exception $e) {
            $this->fail('Worker can not start: '.$e->getMessage());
        }
        $this->assertEquals(Worker::IDLE, $worker->state);

        $sleep_time = 3;
        $worker->sendPayload(['timeout' => $sleep_time]);
        $this->assertEquals(Worker::RUNNING, $worker->state);
        sleep($sleep_time + 1);
        $this->assertEquals($sleep_time * 2, $worker->checkForFinish());
        $this->assertEquals(Worker::IDLE, $worker->state);

        $sleep_time = 1;
        $worker->sendPayload(['timeout' => $sleep_time]);
        $this->assertEquals(Worker::RUNNING, $worker->state);
        sleep($sleep_time + 1);
        $this->assertEquals($sleep_time * 2, $worker->checkForFinish());
        $this->assertEquals(Worker::IDLE, $worker->state);

        unset($worker);
    }
}
