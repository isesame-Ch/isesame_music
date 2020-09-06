<?php


namespace App\Task;

use App\Services\MusicService;
use App\Utils\MyLog;
use Hyperf\Redis\Redis;
use Hyperf\Utils\ApplicationContext;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Crontab\Annotation\Crontab;
use Hyperf\Utils\Parallel;
use Hyperf\WebSocketServer\Sender;


/**
 * @Crontab(name="MusicStart", rule="*\/5 * * * * *", callback="Start", memo="连接开始")
 */
class MusicStartTask
{
    /**
     * @Inject
     * @var \Hyperf\Contract\StdoutLoggerInterface
     */
    private $logger;

    /**
     * @Inject
     * @var MusicService
     */
    protected $musicService;

    /**
     * @Inject
     * @var Sender
     */
    private $sender;

    public function Start()
    {
        $redis = ApplicationContext::getContainer()->get(Redis::class);
        $redis->set('randomed', 0);

        // 获取等待播放的音乐列表
        $musicList = $this->musicService->getMusicList();
        // 获取用于显示在网页上的音乐列表
        $musicShow = $this->musicService->getMusicShow();

        // 如果列表为空
        if(empty($musicList) || empty($musicShow)) {
            $musicList = empty($musicList) ? $this->musicService->getSavedMusicList() : $musicList;
            $musicShow = empty($musicShow) ? $this->musicService->getSavedMusicShow() : $musicShow;
        }

        // 如果音乐列表不为空,还在播放则不管
        // 否则将新的歌单列表信息广播给客户端，并播放下一首歌
        if(!empty($musicList)) {
            $musicTime = $this->musicService->getMusicTime();

            // 如果音乐的结束时间小于当前时间，即播放完毕
            if($musicTime < time() + 3) {
                $redis->set('randomed', 0);
                // 获得下一首歌的信息
                $musicInfo  = $musicList[0];
                $sourceList = $musicList;

                // 从播放列表里移除第一首，因为已经开始播放了
                unset($musicList[0]);
                $musicList = array_values($musicList);

                $this->logger->info("正在播放音乐：{$musicInfo['name']}");

                // 储存信息
                $this->musicService->setMusicList($musicList);
                $this->musicService->setMusicShow($sourceList);
                $this->musicService->setMusicTime(time() + round($musicInfo['time']));
                $this->musicService->setMusicLong(time());
                $this->musicService->setMusicPlay(0);
                $this->musicService->setNeedSwitch([]);

                // 获得播放列表
                $playList = $this->musicService->getPlayList($sourceList);
                $musicLrc = $this->musicService->getMusicLrcs($musicInfo['id']);


                // 广播给所有客户端
                $connections = $redis->hKeys(MusicService::USER_SEND_KEY);
                if(count($connections) > 0) {
                    $currentURL = $musicInfo['file'] ?? $this->musicService->getMusicUrl($musicInfo['id']);
                    foreach($connections as $id) {
                        $this->sender->push((int)$id, json_encode([
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
                        $this->sender->push((int)$id, json_encode([
                            "type" => "list",
                            "data" => $playList
                        ]));
                    }
                }
            }
        }  else {
            // 播放完了就放随机列表，还在播则不管
            // 如果列表已经空了，先获取当前音乐是否还在播放
            $musicTime = $this->musicService->getMusicTime();

            $this->logger->info("music_time : $musicTime");

            // 判断音乐的结束时间是否小于当前时间，如果是则表示已经播放完了
            if($musicTime && $musicTime < time() + 3) {

                // 获取随机的音乐 ID
                // 播放随机音乐列表
                $rlist = $this->musicService->getRandomList();
                $randomed = $redis->get('randomed');
                if($rlist && !$randomed) {
                    $countUser = $this->musicService->getTotalUsers();
                    // 判断是否还有人在线，如果没人就不播放了，有人才播放
                    if($countUser > 0) {

                        // 开始播放随机音乐
                        $searchResult = $this->musicService->searchMusic(["id" => false, "data" => $rlist]);

                        if ($searchResult['type'] == 'success') {
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
                            $this->logger->warning($searchResult['msg']);
                        }
                        $redis->set('randomed',1);
                    }
                }
            }
        }

        // 记录音乐已经播放的时间
        $musicLong = $this->musicService->getMusicLong();
        if($musicLong && is_numeric($musicLong)) {
            $this->musicService->setMusicPlay(time() - $musicLong);
        }

        // 将播放列表储存到硬盘
        $this->musicService->setSavedMusicList();
        $this->musicService->setSavedMusicShow();

        return true;
    }
}