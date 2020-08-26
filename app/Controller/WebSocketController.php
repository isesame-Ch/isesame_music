<?php
declare(strict_types=1);

namespace App\Controller;

use app\Services\MusicTask;
use app\Task\AnnotationTask;
use App\Task\MethodTask;
use App\Task\MongoTask;
use App\Utils\MyLog;
use Hyperf\Contract\OnCloseInterface;
use Hyperf\Contract\OnMessageInterface;
use Hyperf\Contract\OnOpenInterface;
use Hyperf\Redis\Redis;
use Hyperf\Task\Task;
use Hyperf\Task\TaskExecutor;
use Hyperf\Utils\ApplicationContext;
use Hyperf\Utils\Coroutine;
use Swoole\Http\Request;
use Swoole\Server;
use Swoole\Websocket\Frame;
use Swoole\WebSocket\Server as WebSocketServer;
use Hyperf\Framework\Event\OnTask;

class WebSocketController implements OnMessageInterface, OnOpenInterface, OnCloseInterface
{
    protected $getIpMethod = true;
    protected $debug;
    protected $server;
    protected $musicApi;
    protected $logger;

    public function __construct()
    {
        $this->debug = env('APP_DEBUG', true);
        $this->musicApi = env('MUSIC_API', 'https://cdn.zerodream.net/netease');
        $container = ApplicationContext::getContainer();
        $this->logger = $container->get(MyLog::class);
    }

    public function onMessage($server, Frame $frame): void
    {
        $server->push($frame->fd, 'Recv: ' . $frame->data);
    }

    public function onClose($server, int $fd, int $reactorId): void
    {
        var_dump('closed');
    }

    public function onOpen($server, Request $request): void
    {
        $this->server = $server;

        // 当第一个客户端连接到服务器的时候就触发 Task 去处理事件
        $count = [];
        if(!$this->server->started) {
            $client = ApplicationContext::getContainer()->get(MethodTask::class);
            $count = $client->start();

            /*$container = ApplicationContext::getContainer();
            $exec = $container->get(TaskExecutor::class);
            $result = $exec->execute(new Task([MusicTask::class, 'handle'], [Coroutine::id()]));
//            $result = $exec->execute(new Task([MusicTask::class, 'start'],[1234]));*/
            $this->server->started = true;
        }
//        var_dump();

        $ids = $this->server->connection_list(0);

        $server->push($request->fd, json_encode([
            'count' => $count,
            'ids' => $ids,
            "type" => "list",
            "data" => [],
        ]));

        // 获取客户端的 IP 地址
        if($this->getIpMethod) {
            $clientIp = $request->header['x-real-ip'] ?? "127.0.0.1";
        } else {
            $clientIp = $this->server->getClientInfo($request->fd)['remote_ip'] ?? "127.0.0.1";
        }

        // 将客户端 IP 储存到表中
        $this->server->chats->set((string)$request->fd, ["ip" => $clientIp]);

        $this->logger->consoleLog("客户端 {$request->fd} [{$clientIp}] 已连接到服务器", 5, true);

        $server->push($request->fd, json_encode([
            "type" => "msg",
            "data" => "你已经成功连接到服务器！"
        ]));

        $musicPlay = $this->getMusicPlay();
        $musicList = $this->getMusicShow();

        // 如果当前列表中有音乐可播放
        if($musicList && !empty($musicList)) {

            // 获取音乐的信息和歌词
            $musicInfo = $musicList[0];
            $lrcs      = $this->getMusicLrcs($musicInfo['id']);

            // 推送给客户端
            $server->push($request->fd, json_encode([
                "type"    => "music",
                "id"      => $musicInfo['id'],
                "name"    => $musicInfo['name'],
                "file"    => $this->getMusicUrl($musicInfo['id']),
                "album"   => $musicInfo['album'],
                "artists" => $musicInfo['artists'],
                "image"   => $musicInfo['image'],
                "current" => $musicPlay + 1,
                "lrcs"    => $lrcs,
                "user"    => $musicInfo['user']
            ]));

            // 播放列表更新
            $playList = $this->getPlayList($musicList);
            $server->push($request->fd, json_encode([
                "type" => "list",
                "data" => $playList
            ]));
        }
    }


    /**
     *
     *  GetMusicPlay 获取音乐已经播放的时间
     *
     */
    private function getMusicPlay()
    {
        return $this->server->table->get((string)0, "music_play") ?? 0;
    }

    /**
     *
     *  GetMusicShow 获取用于显示在网页上的音乐列表
     *
     */
    private function getMusicShow()
    {
        if(USE_REDIS) {
            $container = ApplicationContext::getContainer();
            $redis = $container->get(Redis::class);
            $data = $redis->get("syncmusic-show");
            $sourceList = $data ?? json_decode($data, true);
        } else {
            $sourceList = json_decode($this->server->table->get(0, "music_show"), true);
        }
        if(!$sourceList || empty($sourceList)) {
            $sourceList = [];
        }
        return $sourceList;
    }

    /**
     *
     *  GetMusicLrcs 获取音乐的歌词
     *
     */
    private function getMusicLrcs($id)
    {
        if(!file_exists(BASE_PATH . "/tmp/{$id}.lrc")) {
            echo $this->debug ? $this->logger->consoleLog("Http Request >> https://music.163.com/api/song/lyric?os=pc&lv=-1&id={$id}", 0) : "";
            $musicLrcs = @file_get_contents("https://music.163.com/api/song/lyric?os=pc&lv=-1&id={$id}");
            echo $this->debug ? $this->logger->consoleLog("Http Request << " . substr($musicLrcs, 0, 256), 0) : "";
            if(strlen($musicLrcs) > 0) {
                @file_put_contents(ROOT . "/tmp/{$id}.lrc", $musicLrcs);
            }
        } else {
            $musicLrcs = @file_get_contents(ROOT . "/tmp/{$id}.lrc");
        }
        $lrcs = "[00:01.00]暂无歌词";
        $lrc = json_decode($musicLrcs, true);
        if($lrc) {
            if(isset($lrc['lrc'])) {
                $lrcs = $lrc['lrc']['lyric'];
            } else {
                $lrcs = "[00:01.00]暂无歌词";
            }
        }
        return $lrcs;
    }

    /**
     *
     *  GetPlayList 获取格式化过的播放列表
     *
     */
    private function getPlayList($sourceList)
    {
        // 播放列表更新
        $playList = <<<EOF
<tr>
	<th>ID</th>
	<th>歌名</th>
	<th>歌手</th>
	<th>专辑</th>
	<th>点歌人</th>
</tr>
EOF;
        foreach($sourceList as $mid => $mi) {
            $userNick = $this->getUserNickname($mi['user']) ?? "匿名用户";
            $user = "{$userNick} (" . $this->getMarkName($mi['user']) . ")";
            $musicName = (mb_strlen($mi['name']) > 32) ? mb_substr($mi['name'], 0, 30) . "..." : $mi['name'];
            $playList .= <<<EOF
<tr>
	<td>{$mid}</td>
	<td>{$musicName}</td>
	<td>{$mi['artists']}</td>
	<td>{$mi['album']}</td>
	<td>{$user}</td>
</tr>
EOF;
        }
        return $playList;
    }

    /**
     *
     *  GetUserNickname 获取用户的昵称
     *
     */
    private function getUserNickname($ip)
    {
        $data = $this->getUserNickData();
        return $data[$ip] ?? false;
    }

    /**
     *
     *  GetUserNickData 获取所有用户的昵称数据
     *
     */
    private function getUserNickData()
    {
        $data = @file_get_contents(BASE_PATH . "/username.json");
        $json = json_decode($data, true);
        return $json ?? [];
    }

    /**
     *
     *  GetMaskName 获取和谐过的客户端 IP 地址
     *
     */
    private function getMarkName($ip)
    {
        $username = $ip ?? "127.0.0.1";
        $uexp = explode(".", $username);
        if(count($uexp) >= 4) {
            $username = "{$uexp[0]}.{$uexp[1]}." . str_repeat("*", strlen($uexp[2])) . "." . str_repeat("*", strlen($uexp[3]));
        } else {
            $username = "Unknown";
        }
        return $username;
    }

    /**
     *
     *  GetMusicUrl 获取音乐的下载地址
     *
     */
    private function getMusicUrl($id)
    {
        echo $this->debug ? $this->logger->consoleLog("Http Request >> {$this->musicApi}/api.php?source=netease&types=url&id={$id}", 0) : "";
        $rawdata = @file_get_contents("{$this->musicApi}/api.php?source=netease&types=url&id={$id}");
        $json    = json_decode($rawdata, true);
        echo $this->debug ? $this->logger->consoleLog("Http Request << {$rawdata}", 0) : "";
        if($json && isset($json["url"])) {
            return str_replace("http://", "https://", $json["url"]);
        } else {
            return "";
        }
    }
}
