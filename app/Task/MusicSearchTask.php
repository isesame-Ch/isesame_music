<?php


namespace App\Task;

use App\Services\MusicService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Task\Annotation\Task;
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
            $this->sender->push((int)$data['id'], json_encode([
                "type" => "list",
                "data" => $searchResult['list']
            ]));
        } else {
            $this->sender->push((int)$data['id'], json_encode([
                "type" => "msg",
                "data" => $searchResult['msg']
            ]));
        }

        return true;
    }
}