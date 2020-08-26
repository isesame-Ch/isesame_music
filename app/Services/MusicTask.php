<?php


namespace app\Services;


use App\Utils\MyLog;
use Hyperf\Utils\ApplicationContext;
use Hyperf\Utils\Coroutine;
use Hyperf\Task\Annotation\Task;
use Swoole\Server as SwooleServer;

class MusicTask
{
    protected $musicService;
    protected $logger;
    protected $server;

    public function __construct(MusicService $musicService, MyLog $log)
    {
        $this->musicService = $musicService;
        $this->logger = $log;
    }

    /**
     * @Task
     */
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
        // 设定死循环的目的是为了建立一个单独的线程用于执行数据更新
        while(true) {

            $musicList = $this->musicService->getMusicList();
            $musicShow = $this->musicService->getMusicShow();

            // 如果列表为空
            if(empty($musicList) || empty($musicShow)) {
                $musicList = empty($musicList) ? $this->musicService->getSavedMusicList() : $musicList;
                $musicShow = empty($musicShow) ? $this->musicService->getSavedMusicShow() : $musicShow;
            }

            // 如果音乐列表不为空
            if(!empty($musicList)) {

                $musicTime = $this->musicService->getMusicTime($this->server);

                // 如果音乐的结束时间小于当前时间，即播放完毕
                if($musicTime < time() + 3) {

                    $this->server->randomed = false;

                    // 获得下一首歌的信息
                    $musicInfo  = $musicList[0];
                    $sourceList = $musicList;

                    // 从播放列表里移除第一首，因为已经开始播放了
                    unset($musicList[0]);
                    $musicList = array_values($musicList);

                    $this->logger->consoleLog("正在播放音乐：{$musicInfo['name']}", 1, true);

                    // 储存信息
                    $this->musicService->setMusicList($musicList);
                    $this->musicService->setMusicShow($sourceList);
                    $this->musicService->setMusicTime(time() + round($musicInfo['time']), $this->server);
                    $this->musicService->setMusicLong(time(), $this->server);
                    $this->musicService->setMusicPlay(0, $this->server);
                    $this->musicService->setNeedSwitch("",$this->server);

                    // 获得播放列表
                    $playList = $this->musicService->getPlayList($sourceList);
                    $musicLrc = $this->musicService->getMusicLrcs($musicInfo['id']);

                    // 广播给所有客户端
                    if($this->server->connections) {
                        $currentURL = $this->musicService->getMusicUrl($musicInfo['id']);
                        foreach($this->server->connections as $id) {
                            $this->server->push($id, json_encode([
                                "type"    => "music",
                                "id"      => $musicInfo['id'],
                                "name"    => $musicInfo['name'],
                                "file"    => $currentURL,
                                "album"   => $musicInfo['album'],
                                "artists" => $musicInfo['artists'],
                                "image"   => $musicInfo['image'],
                                "lrcs"    => $musicLrc,
                                "user"    => $musicInfo['user']
                            ]));
                            $this->server->push($id, json_encode([
                                "type" => "list",
                                "data" => $playList
                            ]));
                        }
                    }
                }
            } else {

                // 如果列表已经空了，先获取当前音乐是否还在播放
                $musicTime = $this->musicService->getMusicTime($this->server);

                // 判断音乐的结束时间是否小于当前时间，如果是则表示已经播放完了
                if($musicTime && $musicTime < time() + 3) {

                    // 获取随机的音乐 ID
                    $rlist = $this->musicService->getRandomList();
                    if($rlist && !$this->server->randomed) {

                        // 判断是否还有人在线，如果没人就不播放了，有人才播放
                        if($this->server->connections && count($server->connections) > 0) {

                            // 开始播放随机音乐
                            $this->musicService->searchMusic($this->server, ["id" => false, "action" => "Search", "data" => $rlist]);
                            $server->randomed = true;
                        }
                    }
                }
            }

            // 记录音乐已经播放的时间
            $musicLong = $this->musicService->getMusicLong($this->server);
            if($musicLong && is_numeric($musicLong)) {
                $this->musicService->setMusicPlay(time() - $musicLong,$this->server);
            }

            // 将播放列表储存到硬盘
            $this->musicService->setSavedMusicList($musicList,$this->server);
            $this->musicService->setSavedMusicShow($musicShow,$this->server);

            // 每秒钟执行一次任务
            sleep(1);
        }
    }

    public function search($data, $server)
    {
        // 如果是搜索音乐的任务
        $this->musicService->searchMusic($server, $data);
    }
}