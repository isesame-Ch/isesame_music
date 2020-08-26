<?php


namespace app\Services;


use App\Utils\MyLog;
use Hyperf\Redis\Redis;
use Hyperf\Utils\ApplicationContext;

class MusicService
{
    protected $server;
    protected $musicApi;
    protected $logger;

    public function __construct()
    {
        $this->debug = env('APP_DEBUG', true);
        $this->musicApi = env('MUSIC_API', 'https://cdn.zerodream.net/netease');
        $this->logger = ApplicationContext::getContainer()->get(MyLog::class);
    }

    /**
     *
     *  GetMusicPlay 获取音乐已经播放的时间
     *
     */
    public function getMusicPlay($server)
    {
        return $server->table->get((string)0, "music_play") ?? 0;
    }

    /**
     *
     *  GetMusicList 获取等待播放的音乐列表
     *
     */
    public function getMusicList()
    {
        if(USE_REDIS) {
            $container = ApplicationContext::getContainer();
            $redis = $container->get(Redis::class);
            $data = $redis->get("syncmusic-list");
            $musicList = $data ?? json_decode($data, true);
        } else {
            $musicList = json_decode($this->server->table->get(0, "music_list"), true);
        }
        if(!$musicList || empty($musicList)) {
            $musicList = [];
        }
        return $musicList;
    }

    /**
     *
     *  GetMusicShow 获取用于显示在网页上的音乐列表
     *
     */
    public function getMusicShow()
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
    public function getMusicLrcs($id)
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
    public function getPlayList($sourceList)
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
    public function getUserNickname($ip)
    {
        $data = $this->getUserNickData();
        return $data[$ip] ?? false;
    }

    /**
     *
     *  GetUserNickData 获取所有用户的昵称数据
     *
     */
    public function getUserNickData()
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
    public function getMarkName($ip)
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
    public function getMusicUrl($id)
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

    /**
     *
     *  GetSavedMusicList 获取已经保存在硬盘的音乐列表
     *
     */
    public function getSavedMusicList()
    {
        $data = @file_get_contents(BASE_PATH . "/musiclist.json");
        return empty($data) ? [] : json_decode($data, true);
    }

    /**
     *
     *  GetSavedMusicShow 获取已经保存在硬盘的音乐显示列表
     *
     */
    public function getSavedMusicShow()
    {
        $data = @file_get_contents(BASE_PATH . "/musicshow.json");
        return empty($data) ? [] : json_decode($data, true);
    }

    /**
     *
     *  GetMusicTime 获取当前正在播放的音乐的结束时间
     *
     */
    public function getMusicTime($server)
    {
        return $server->table->get(0, "music_time") ?? 0;
    }

    /**
     *
     *  GetMusicLong 获取音乐开始播放的时间
     *
     */
    public function getMusicLong($server)
    {
        return $server->table->get(0, "music_long") ?? time();
    }

    /**
     *
     *  GetAdminIp 获取管理员的 IP
     *
     */
    public function getAdminIp()
    {
        $adminIp = @file_get_contents(BASE_PATH . "/admin.ip");
        return $adminIp ?? "127.0.0.1";
    }

    /**
     *
     *  GetBannedIp 获取已经被封禁的 IP
     *
     */
    public function getBannedIp($server)
    {
        return $server->table->get(0, "banned_ips") ?? "";
    }

    /**
     *
     *  GetMusicLength 获取音乐的总长度时间
     *
     */
    public function getMusicLength($id)
    {
        return FloatVal(shell_exec(PYTHON_EXEC . " getlength.py " . BASE_PATH . "/tmp/{$id}.mp3"));
    }

    /**
     *
     *  GetArtists 获取音乐的歌手信息
     *
     */
    public function getArtists($data)
    {
        if(count($data) > 1) {
            $artists = "";
            foreach($data as $artist) {
                $artists .= $artist . ",";
            }
            $artists = $artists == "" ? "未知歌手" : mb_substr($artists, 0, mb_strlen($artists) - 1);
        } else {
            $artists = $data[0];
        }
        return $artists;
    }

    /**
     *
     *  GetLoggerLevel 获取输出日志的等级
     *
     */
    public function getLoggerLevel($level)
    {
        $levelGroup = ["DEBUG", "INFO", "WARNING", "ERROR"];
        return $levelGroup[$level] ?? "INFO";
    }

    /**
     *
     *  GetNeedSwitch 获取需要切歌的投票用户列表
     *
     */
    public function getNeedSwitch($server)
    {
        $switchList = $server->table->get(0, "needswitch");
        return is_string($switchList) ? count(explode(";", $switchList)) : 0;
    }

    /**
     *
     *  GetTotalUsers 获取当前所有在线的客户端数量
     *
     */
    public function getTotalUsers($server)
    {
        return $server->connections ? count($server->connections) : 0;
    }

    /**
     *
     *  GetRandomList 获取随机的音乐 ID
     *
     */
    public function getRandomList()
    {
        $data = @file_get_contents(BASE_PATH . "/random.txt");
        $exp = explode("\n", $data);
        if(count($exp) > 0) {
            $rand = trim($exp[mt_rand(0, count($exp) - 1)]);
        } else {
            $rand = false;
        }
        return $rand;
    }

    /**
     *
     *  SearchMusic 搜索音乐
     *
     */
    public function searchMusic($server, $data)
    {
        $this->logger->consoleLog("正在点歌：{$data['data']}", 1, true);

        $musicList  = $this->getMusicList();
        $sourceList = $this->getMusicShow();
        $this->lockSearch($server);

        // 开始搜索音乐
        $json = $this->fetchMusicApi($data['data']);

        if($json && !empty($json)) {
            if(isset($json[0]['id'])) {
                $m = $json[0];
                // 判断是否已经点过这首歌了
                if($this->isInArray($musicList, $m['id'])) {
                    $this->unlockSearch($server);
                    $server->finish(["id" => $data['id'], "action" => "msg", "data" => "这首歌已经在列表里了"]);
                } else {
                    $artists = $this->getArtists($m['artist']);
                    $musicUrl = $this->getMusicUrl($m['id']);
                    // 如果能够正确获取到音乐 URL
                    if($this->isBlackList($m['id']) || $this->isBlackList($m['name']) || $this->isBlackList($artists)) {
                        $this->unlockSearch($server);
                        $server->finish(["id" => $data['id'], "action" => "msg", "data" => "这首歌被设置不允许点播"]);
                    } elseif($musicUrl !== "") {
                        $musicId = Intval($m['id']);
                        // 开始下载音乐
                        $musicData = $this->fetchMusic($m, $musicUrl);
                        $musicImage = $this->getMusicImage($m['pic_id']);
                        // 如果音乐的文件大小不为 0
                        if(strlen($musicData) > 0) {
                            $musicTime = $this->getMusicLength($m['id']);
                            // 如果音乐的长度为 0（说明下载失败或其他原因）
                            if($musicTime == 0) {
                                $this->unlockSearch($server);
                                $server->finish(["id" => $data['id'], "action" => "msg", "data" => "歌曲下载失败，错误代码：ERROR_TIME0"]);
                            } elseif($musicTime > MAX_MUSICLENGTH) {
                                $this->unlockSearch($server);
                                $server->finish(["id" => $data['id'], "action" => "msg", "data" => "歌曲太长影响他人体验，不能超过 " . MAX_MUSICLENGTH . " 秒"]);
                            } else {
                                // 保存列表
                                $clientIp = $data['id'] ? $this->getClientIp($data['id']) : "127.0.0.1";
                                $musicList[] = [
                                    "id"      => $musicId,
                                    "name"    => $m['name'],
                                    "file"    => $musicUrl,
                                    "time"    => $musicTime,
                                    "album"   => $m['album'],
                                    "artists" => $artists,
                                    "image"   => $musicImage,
                                    "user"    => $clientIp
                                ];
                                $sourceList[] = [
                                    "id"      => $musicId,
                                    "name"    => $m['name'],
                                    "file"    => $musicUrl,
                                    "time"    => $musicTime,
                                    "album"   => $m['album'],
                                    "artists" => $artists,
                                    "image"   => $musicImage,
                                    "user"    => $clientIp
                                ];
                                $this->setMusicList($musicList, $server);
                                $this->setMusicShow($sourceList,$server);
                                // 播放列表更新
                                $playList = $this->getPlayList($sourceList);
                                // 广播给所有客户端
                                if($data['id'] && $server->connections) {
                                    foreach($server->connections as $id) {
                                        $server->push($id, json_encode([
                                            "type" => "list",
                                            "data" => $playList
                                        ]));
                                    }
                                }
                                $this->unlockSearch($server);
                                $server->finish(["id" => $data['id'], "action" => "msg", "data" => "点歌成功"]);
                            }
                        } else {
                            $this->unlockSearch($server);
                            $server->finish(["id" => $data['id'], "action" => "msg", "data" => "歌曲下载失败，错误代码：ERROR_FILE_EMPTY"]);
                        }
                    } else {
                        $this->unlockSearch($server);
                        $server->finish(["id" => $data['id'], "action" => "msg", "data" => "歌曲下载失败，错误代码：ERROR_URL_EMPTY"]);
                    }
                }
            } else {
                $this->unlockSearch($server);
                $server->finish(["id" => $data['id'], "action" => "msg", "data" => "歌曲下载失败，错误代码：ERROR_ID_EMPTY"]);
            }
        } else {
            $this->unlockSearch($server);
            $server->finish(["id" => $data['id'], "action" => "msg", "data" => "未搜索到此歌曲"]);
        }
    }

    /**
     *
     *  SetUserNickname 设置用户的昵称
     *
     */
    public function setUserNickname($ip, $name)
    {
        $data = $this->getUserNickData();
        $data[$ip] = $name;
        $this->setUserNickData($data);
    }

    /**
     *
     *  SetUserNickData 将昵称数据写入到硬盘
     *
     */
    public function setUserNickData($data)
    {
        @file_put_contents(ROOT . "/username.json", json_encode($data));
    }

    /**
     *
     *  SetBlackList 将黑名单数据写入到硬盘
     *
     */
    public function setBlackList($data)
    {
        $result = "";
        for($i = 0;$i < count($data);$i++) {
            $result .= $data[$i] . "\n";
        }
        @file_put_contents(ROOT . "/blacklist.txt", $result);
    }

    /**
     *
     *  SetLastChat 设置客户端的最后发言时间
     *
     */
    public function setLastChat($id, $time = 0)
    {
        $this->server->chats->set($id, ["last" => $time]);
    }

    /**
     *
     *  SetMusicList 设置等待播放的音乐列表
     *
     */
    public function setMusicList($data, $server)
    {
        if(USE_REDIS) {
            $container = ApplicationContext::getContainer();
            $redis = $container->get(Redis::class);
            $redis->set("syncmusic-list", json_encode($data));
        } else {
            $server->table->set(0, ["music_list" => json_encode($data)]);
        }
    }

    /**
     *
     *  SetMusicShow 设置用于网页显示的音乐列表
     *
     */
    public function setMusicShow($data, $server)
    {
        if(USE_REDIS) {
            $container = ApplicationContext::getContainer();
            $redis = $container->get(Redis::class);
            $redis->set("syncmusic-show", json_encode($data));
        } else {
            $server->table->set(0, ["music_show" => json_encode($data)]);
        }
    }

    /**
     *
     *  SetMusicTime 设置音乐播放的结束时间
     *
     */
    public function setMusicTime($data, $server)
    {
        $server->table->set(0, ["music_time" => $data]);
    }

    /**
     *
     *  SetMusicLong 设置音乐播放的开始时间
     *
     */
    public function setMusicLong($data, $server)
    {
        $server->table->set(0, ["music_long" => $data]);
    }

    /**
     *
     *  SetMusicPlay 设置音乐已经播放的时间
     *
     */
    public function setMusicPlay($data, $server)
    {
        $server->table->set(0, ["music_play" => $data]);
    }

    /**
     *
     *  SetSavedMusicList 将等待播放的音乐列表储存到硬盘
     *
     */
    public function setSavedMusicList($server)
    {
        @file_put_contents(BASE_PATH . "/musiclist.json", $server->table->get(0, "music_list"));
    }

    /**
     *
     *  SetSavedMusicShow 将用于显示在网页上的音乐列表储存到硬盘
     *
     */
    public function setSavedMusicShow($data, $server)
    {
        @file_put_contents(BASE_PATH . "/musicshow.json", $server->table->get(0, "music_show"));
    }

    /**
     *
     *  SetAdminIp 设置管理员的 IP 地址
     *
     */
    public function setAdminIp($ip)
    {
        @file_put_contents(BASE_PATH . "/admin.ip", $ip);
    }

    /**
     *
     *  SetBannedIp 设置被封禁的 IP 列表
     *
     */
    public function setBannedIp($ip, $server)
    {
        $server->table->set(0, ["banned_ips" => $ip]);
    }

    /**
     *
     *  SetNeedSwitch 设置需要投票切歌的用户列表
     *
     */
    public function setNeedSwitch($data, $server)
    {
        $server->table->set(0, ["needswitch" => $data]);
    }

    /**
     *
     *  FetchMusicApi 搜索指定关键字的音乐
     *
     */
    public function fetchMusicApi($keyWord)
    {
        $keyWord = urlencode($keyWord);
        echo $this->debug ? $this->logger->consoleLog("Http Request >> {$this->musicApi}/api.php?source=netease&types=search&name={$keyWord}&count=1&pages=1", 0) : "";
        $rawdata = @file_get_contents("{$this->musicApi}/api.php?source=netease&types=search&name={$keyWord}&count=1&pages=1");
        echo $this->debug ? $this->logger->consoleLog("Http Request << {$rawdata}", 0) : "";
        return json_decode($rawdata, true);
    }

    /**
     *
     *  FetchMusic 读取音乐文件内容
     *
     */
    public function fetchMusic($m, $download = '')
    {
        if(!file_exists(BASE_PATH . "/tmp/{$m['id']}.mp3")) {
            $this->logger->consoleLog("歌曲 {$m['name']} 不存在，下载中...", 1, true);
            $musicFile = @file_get_contents($download);
            $this->logger->consoleLog("歌曲 {$m['name']} 下载完成。", 1, true);
            @file_put_contents(BASE_PATH . "/tmp/{$m['id']}.mp3", $musicFile);
        } else {
            $musicFile = @file_get_contents(BASE_PATH . "/tmp/{$m['id']}.mp3");
        }
        return $musicFile;
    }

    /**
     *
     *  LockSearch 禁止点歌
     *
     */
    public function lockSearch($server)
    {
        $server->table->set(0, ["downloaded" => 1]);
    }

    /**
     *
     *  UnlockSearch 允许点歌
     *
     */
    public function unlockSearch($server)
    {
        $server->table->set(0, ["downloaded" => 0]);
    }

    /**
     *
     *  IsInArray 判断指定元素是否在数组中
     *
     */
    private function isInArray($array, $need, $key = 'id')
    {
        $found = false;
        foreach($array as $smi) {
            if($smi[$key] == $need) {
                $found = true;
                break;
            }
        }
        return $found;
    }

    /**
     *
     *  IsBlackList 判断是否在黑名单音乐中
     *
     */
    private function isBlackList($key)
    {
        $blackList = $this->getBlackList();
        for($i = 0;$i < count($blackList);$i++) {
            if(stristr($key, $blackList[$i])) {
                return true;
            }
        }
        return false;
    }

    /**
     *
     *  GetBlackList 获取音乐的黑名单列表
     *
     */
    public function getBlackList()
    {
        $data = @file_get_contents(BASE_PATH . "/blacklist.txt");
        $exp = explode("\n", $data);
        $result = [];
        for($i = 0;$i < count($exp);$i++) {
            $tmpData = trim($exp[$i]);
            if(!empty($tmpData)) {
                $result[] = $tmpData;
            }
        }
        return $result;
    }

    /**
     *
     *  GetMusicImage 获取音乐的专辑封面图片地址
     *
     */
    public function getMusicImage($picId)
    {
        echo $this->debug ? $this->logger->consoleLog("Http Request >> {$this->musicApi}/api.php?source=netease&types=pic&id={$picId}", 0) : "";
        $rawdata = @file_get_contents("{$this->musicApi}/api.php?source=netease&types=pic&id={$picId}");
        $imgdata = json_decode($rawdata, true);
        echo $this->debug ? $this->logger->consoleLog("Http Request << {$rawdata}", 0) : "";
        return $imgdata['url'] ?? "";
    }


}