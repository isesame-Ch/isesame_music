<?php

declare(strict_types=1);

namespace App\Task;

use App\Utils\MyLog;
use Hyperf\Framework\Event\OnTask;
use Hyperf\Task\Annotation\Task;
use Hyperf\Utils\ApplicationContext;
use Hyperf\Utils\Coroutine;
use Swoole\Server as SwooleServer;
use Swoole\WebSocket\Server;
use Swoole\WebSocket\Server as WebSocketServer;

class MongoTask
{
    /**
     * @var Manager
     */
    public $manager;


    /**
     * @Task
     */
    public function insert(string $namespace, array $document)
    {
       $this->manager[$namespace] = $document;
       return array_count_values($this->manager[$namespace]);
    }

    /**
     * @Task
     */
    public function query(string $namespace, array $filter = [], array $options = [])
    {
        $result = $this->manager[$namespace];
        return $result;
    }

    public function handle($cid)
    {
        return [
            'worker.cid' => $cid,
            // task_enable_coroutine 为 false 时返回 -1，反之 返回对应的协程 ID
            'task.cid' => Coroutine::id(),
        ];
    }

    /**
     * @Task
     */
    public function start()
    {
        $this->server = ApplicationContext::getContainer()->get(SwooleServer::class);
        return $this->server->table->get((string)0, "music_time") ?? 0;
    }

    protected function manager()
    {
        return $this->manager;
    }
}
