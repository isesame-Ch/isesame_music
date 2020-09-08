<?php


namespace App\Task;

use App\Services\MusicService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Redis\Redis;
use Hyperf\Task\Annotation\Task;
use Hyperf\Utils\ApplicationContext;
use Hyperf\WebSocketServer\Sender;

class MusicSearchTask
{
    /**
     * @Inject
     * @var MusicService
     */
    protected $musicService;

    /**
     * @Inject
     * @var Sender
     */
    protected $sender;

    /**
     * @Task
     */
    public function search($data)
    {
        $searchResult = $this->musicService->searchMusic($data);

        if ($searchResult['type'] == 'success') {
            //成功更新所有人歌单
            $redis = ApplicationContext::getContainer()->get(Redis::class);
            $connections = $redis->hKeys(MusicService::USER_SEND_KEY);
            if(count($connections) > 0) {
                foreach($connections as $id) {
                    $this->sender->push((int)$id, json_encode([
                        "type" => "list",
                        "data" => $searchResult['list']
                    ]));
                }
            }
        } else {
            $this->sender->push((int)$data['id'], json_encode([
                "type" => "msg",
                "data" => $searchResult['msg']
            ]));
        }

        return true;
    }
}