<?php
declare(strict_types=1);

namespace App\Controller;

use App\Services\MusicService;
use App\Services\MusicTask;
use App\Task\AnnotationTask;
use App\Task\MethodTask;
use App\Task\MongoTask;
use App\Task\MusicSearchTask;
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
use Hyperf\Di\Annotation\Inject;

class WebSocketController implements OnMessageInterface, OnOpenInterface, OnCloseInterface
{
    protected $getIpMethod = false;
    protected $debug;
    protected $server;
    protected $musicApi;
    protected $logger;

    /**
     * @var MusicService
     */
    protected $musicService;

    public function __construct(MusicService $musicService)
    {
        $this->debug = env('APP_DEBUG', true);
        $this->musicApi = env('MUSIC_API', 'https://cdn.zerodream.net/netease');
        $container = ApplicationContext::getContainer();
        $this->logger = $container->get(MyLog::class);
        $this->musicService = $musicService;
    }

    public function onMessage($server, Frame $frame): void
    {
        $this->logger->consoleLog($frame->data, 1,true);

        $redis = ApplicationContext::getContainer()->get(Redis::class);
        $clients = $redis->hKeys(MusicService::USER_SEND_KEY);
        $clientIp = $this->musicService->getClientIp($frame->fd);
        $adminIp = $this->musicService->getAdminIp();

        // 判断客户端是否已被封禁
        if($this->musicService->isBanned($clientIp)) {
            $server->push($frame->fd, json_encode([
                "type" => "msg",
                "data" => "你没有权限发言"
            ]));
        } else {
            // 把客户端 IP 地址的 C 段和 D 段打码作为用户名显示
            $username = $this->musicService->getMarkName($clientIp);

            // 解析客户端发过来的消息
            $message = $frame->data;
            $json = json_decode($message, true);

            if($json && isset($json['type'])) {
                switch ($json['type']) {
                    case 'msg':
                        // 获取客户端最后发言的时间戳
                        $lastChat = $this->musicService->getLastChat($frame->fd);

                        //防止客户端刷屏
                        if($lastChat && time() - $lastChat <= env('MIN_CHATWAIT',3)) {
                            $server->push($frame->fd, json_encode([
                                "type" => "msg",
                                "data" => "发言太快，请稍后再发送"
                            ]));
                        } else {
                            // 储存用户的最后发言时间
                            $this->musicService->setLastChat($frame->fd, time());
                            $this->logger->consoleLog("客户端 {$frame->fd} 发送消息：{$json['data']}", 1, true);

                            if($json['data'] == "切歌") {
                                // 如果是切歌的命令，先判断是否是管理员
                                if ($this->musicService->isAdmin($clientIp)) {

                                    // 执行切歌操作，这里的 time + 1 是为了防止 bug
                                    $this->musicService->setMusicTime(time() + 1);
                                    $this->musicService->setMusicPlay(0);
                                    $this->musicService->setMusicLong(time());

                                    $server->push($frame->fd, json_encode([
                                        "type" => "msg",
                                        "data" => "成功切歌"
                                    ]));
                                } else {
                                    $server->push($frame->fd, json_encode([
                                        "type" => "msg",
                                        "data" => "你没有权限这么做"
                                    ]));
                                }

                            } elseif ($json['data'] == '投票切歌') {
                                // 由所有用户投票切掉当前歌曲
                                $needSwitch = $this->musicService->getNeedSwitch();
                                $totalUsers = $this->musicService->getTotalUsers();

                                // 判断用户是否已经投过票
                                if (in_array($clientIp, $needSwitch)) {
                                    $server->push($frame->fd, json_encode([
                                        "type" => "msg",
                                        "data" => "你已经投票过了"
                                    ]));
                                } else {
                                    $countNeedSwitch = count($needSwitch);
                                    // 如果是第一次投票
                                    if($countNeedSwitch == 0) {

                                        // 广播给所有客户端
                                        foreach($clients as $id) {
                                            $server->push((int)$id, json_encode([
                                                "type" => "msg",
                                                "data" => "有人希望切歌，支持请输入 “投票切歌”"
                                            ]));
                                        }
                                    }
                                    // 投票数+1
                                    $this->musicService->setNeedSwitch(array_push($needSwitch,$clientIp));
                                    $server->push($frame->fd, json_encode([
                                        "type" => "msg",
                                        "data" => "投票成功"
                                    ]));
                                    $countNeedSwitch++;

                                    // 判断投票的用户数是否超过在线用户数的 50%
                                    if($countNeedSwitch / $totalUsers >= 0.5) {

                                        // 执行切歌操作
                                        $this->musicService->setMusicTime(time() + 1);
                                        $this->musicService->setMusicPlay(0);
                                        $this->musicService->setMusicLong(time());
                                        $this->musicService->setNeedSwitch([]);

                                        $server->push($frame->fd, json_encode([
                                            "type" => "msg",
                                            "data" => "成功切歌"
                                        ]));
                                    } else {
                                        // 广播给所有客户端
                                        foreach($clients as $id) {
                                            $server->push((int)$id, json_encode([
                                                "type" => "msg",
                                                "data" => "当前投票人数：{$countNeedSwitch}/{$totalUsers}"
                                            ]));
                                        }
                                    }

                                    // 广播给所有客户端
                                    $userNickName = $this->musicService->getUserNickname($clientIp);
                                    foreach($clients as $id) {
                                        $showUserName = $this->musicService->getClientIp($id) == $adminIp ? $clientIp : $username;
                                        if($userNickName) {
                                            $showUserName = "{$userNickName} ({$showUserName})";
                                        }
                                        $server->push((int)$id, json_encode([
                                            "type" => "chat",
                                            "user" => htmlspecialchars($showUserName),
                                            "time" => date("Y-m-d H:i:s"),
                                            "data" => htmlspecialchars($json['data'])
                                        ]));
                                    }
                                }

                            }  elseif ($json['data'] == "禁言列表") {

                                // 查看已禁言的用户列表，先判断是否是管理员
                                if($this->musicService->isAdmin($clientIp)) {
                                    $server->push($frame->fd, json_encode([
                                        "type" => "chat",
                                        "user" => "System",
                                        "time" => date("Y-m-d H:i:s"),
                                        "data" => htmlspecialchars("禁言 IP 列表：" . implode(';', $this->musicService->getBannedIp()))
                                    ]));
                                } else {
                                    $server->push($frame->fd, json_encode([
                                        "type" => "msg",
                                        "data" => "你没有权限这么做"
                                    ]));
                                }

                            } elseif(mb_substr($json['data'], 0, 3) == "禁言 " && mb_strlen($json['data']) > 3) {

                                // 如果是禁言客户端的命令，先判断是否是管理员
                                if($this->musicService->isAdmin($clientIp)) {
                                    $banName = trim(mb_substr($json['data'], 3));
                                    if(!empty($banName)) {

                                        // 判断是否已经被禁言
                                        if($this->musicService->isBanned($banName)) {
                                            $server->push($frame->fd, json_encode([
                                                "type" => "msg",
                                                "data" => "这个 IP 已经被禁言了"
                                            ]));
                                        } else {
                                            $oldBannedIp = $this->musicService->getBannedIp();
                                            $this->musicService->setBannedIp(array_push($oldBannedIp, $banName));
                                            $server->push($frame->fd, json_encode([
                                                "type" => "msg",
                                                "data" => "成功禁止此 IP 点歌和发言"
                                            ]));
                                        }
                                    } else {
                                        $server->push($frame->fd, json_encode([
                                            "type" => "msg",
                                            "data" => "禁言的 IP 不能为空！"
                                        ]));
                                    }
                                } else {
                                    $server->push($frame->fd, json_encode([
                                        "type" => "msg",
                                        "data" => "你没有权限这么做"
                                    ]));
                                }

                            } elseif(mb_substr($json['data'], 0, 3) == "解禁 " && mb_strlen($json['data']) > 3) {

                                // 如果是解禁客户端的命令，先判断是否是管理员
                                if($this->musicService->isAdmin($clientIp)) {
                                    $banName = trim(mb_substr($json['data'], 3));
                                    if(!empty($banName)) {

                                        // 如果用户没有被封禁
                                        if(!$this->musicService->isBanned($banName)) {
                                            $server->push($frame->fd, json_encode([
                                                "type" => "msg",
                                                "data" => "这个 IP 没有被禁言"
                                            ]));
                                        } else {
                                            $bannedIp = $this->musicService->getBannedIp();
                                            $banKey = array_search($bannedIp, $banName);
                                            array_splice($bannedIp,$banKey);
                                            $this->musicService->setBannedIp($bannedIp);
                                            $server->push($frame->fd, json_encode([
                                                "type" => "msg",
                                                "data" => "成功解禁此 IP 的禁言"
                                            ]));
                                        }
                                    } else {
                                        $server->push($frame->fd, json_encode([
                                            "type" => "msg",
                                            "data" => "解禁的 IP 不能为空！"
                                        ]));
                                    }
                                } else {
                                    $server->push($frame->fd, json_encode([
                                        "type" => "msg",
                                        "data" => "你没有权限这么做"
                                    ]));
                                }

                            }  elseif(mb_substr($json['data'], 0, 3) == "换歌 " && mb_strlen($json['data']) > 3) {

                                // 如果是交换歌曲顺序的命令，先判断是否是管理员
                                if ($this->musicService->isAdmin($clientIp)) {
                                    $switchMusic = trim(mb_substr($json['data'], 3));
                                    if (!empty($switchMusic)) {
                                        $switchMusic = Intval($switchMusic);

                                        // 不可以切换正在播放的歌曲
                                        if ($switchMusic == 0) {
                                            $server->push($frame->fd, json_encode([
                                                "type" => "msg",
                                                "data" => "正在播放的音乐不能切换"
                                            ]));
                                        } else {
                                            // 取得列表
                                            $musicList = $this->musicService->getMusicList();
                                            $sourceList = $this->musicService->getMusicShow();

                                            // 储存并交换两首音乐
                                            $waitSwitch = $musicList[$switchMusic - 1];
                                            $needSwitch = $musicList[0];
                                            $musicList[0] = $waitSwitch;
                                            $sourceList[1] = $waitSwitch;
                                            $musicList[$switchMusic - 1] = $needSwitch;
                                            $sourceList[$switchMusic] = $needSwitch;

                                            // 播放列表更新
                                            $playList = $this->musicService->getPlayList($sourceList);
                                            $this->musicService->setMusicList($musicList);
                                            $this->musicService->setMusicShow($sourceList);

                                            // 广播给所有客户端
                                            foreach($clients as $id) {
                                                $server->push((int)$id, json_encode([
                                                    "type" => "list",
                                                    "data" => $playList
                                                ]));
                                            }

                                            // 发送通知
                                            $server->push($frame->fd, json_encode([
                                                "type" => "msg",
                                                "data" => "音乐切换成功"
                                            ]));
                                        }
                                    } else {
                                        $server->push($frame->fd, json_encode([
                                            "type" => "msg",
                                            "data" => "要切换的歌曲不能为空"
                                        ]));
                                    }
                                } else {
                                    $server->push($frame->fd, json_encode([
                                        "type" => "msg",
                                        "data" => "你没有权限这么做"
                                    ]));
                                }

                            } elseif(mb_substr($json['data'], 0, 5) == "删除音乐 " && mb_strlen($json['data']) > 5) {

                                // 如果是删除某首音乐的命令
                                $deleteMusic = trim(mb_substr($json['data'], 5));
                                $deleteMusic = Intval($deleteMusic);


                                // 判断操作者是否是管理员
                                if($this->musicService->isAdmin($clientIp)) {
                                    if (empty($deleteMusic)) {
                                        $server->push($frame->fd, json_encode([
                                            "type" => "msg",
                                            "data" => "要切换的歌曲不能为空"
                                        ]));
                                    }

                                    // 如果正在播放的音乐是第一首
                                    if($deleteMusic <= 0) {
                                        $server->push($frame->fd, json_encode([
                                            "type" => "msg",
                                            "data" => "正在播放的音乐不能删除"
                                        ]));
                                    } else {

                                        // 获取播放列表
                                        $musicList  = $this->musicService->getMusicList();
                                        $sourceList = $this->musicService->getMusicShow();

                                        // 从列表中删除这首歌
                                        unset($musicList[$deleteMusic - 1]);
                                        unset($sourceList[$deleteMusic]);

                                        // 重新整理列表
                                        $musicList = array_values($musicList);
                                        $sourceList = array_values($sourceList);

                                        // 播放列表更新
                                        $playList = $this->musicService->getPlayList($sourceList);
                                        $this->musicService->setMusicList($musicList);
                                        $this->musicService->setMusicShow($sourceList);

                                        // 广播给所有客户端
                                        foreach($clients as $id) {
                                            $server->push((int)$id, json_encode([
                                                "type" => "list",
                                                "data" => $playList
                                            ]));
                                        }

                                        // 发送通知
                                        $server->push($frame->fd, json_encode([
                                            "type" => "msg",
                                            "data" => "音乐删除成功"
                                        ]));
                                    }
                                } else {
                                    $server->push($frame->fd, json_encode([
                                        "type" => "msg",
                                        "data" => "你没有权限这么做"
                                    ]));
                                }

                            } elseif(mb_substr($json['data'], 0, 5) == "房管登录 " && mb_strlen($json['data']) > 5) {

                                // 如果是房管登录操作
                                $userPass = trim(mb_substr($json['data'], 5));

                                // 判断密码是否正确
                                if($userPass == env('ADMIN_PASS','123456789')) {
                                    $this->musicService->setAdminIp($clientIp);
                                    $server->push($frame->fd, json_encode([
                                        "type" => "msg",
                                        "data" => "房管登录成功"
                                    ]));
                                } else {
                                    $server->push($frame->fd, json_encode([
                                        "type" => "msg",
                                        "data" => "房管密码错误"
                                    ]));
                                }

                            } elseif(mb_substr($json['data'], 0, 5) == "加黑名单 " && mb_strlen($json['data']) > 5) {

                                // 如果是房管登录操作
                                $blackList = trim(mb_substr($json['data'], 5));

                                // 判断密码是否正确
                                if($this->musicService->isAdmin($clientIp)) {
                                    $this->musicService->addBlackList($blackList);
                                    $server->push($frame->fd, json_encode([
                                        "type" => "msg",
                                        "data" => "已增加新的黑名单"
                                    ]));
                                } else {
                                    $server->push($frame->fd, json_encode([
                                        "type" => "msg",
                                        "data" => "你没有权限这么做"
                                    ]));
                                }

                            } elseif(mb_substr($json['data'], 0, 3) == "点歌 " && mb_strlen($json['data']) > 3) {

                                // 如果是点歌命令
                                $musicName = trim(mb_substr($json['data'], 3));
                                if(!empty($musicName)) {

                                    // 判断是否已经有人在点歌中
                                    if(count($this->musicService->getUserMusic($clientIp)) > env('MAX_USERMUSIC',5)) {
                                        $server->push($frame->fd, json_encode([
                                            "type" => "msg",
                                            "data" => "你已经点了很多歌了，请先听完再点"
                                        ]));
                                    } elseif($this->musicService->isLockedSearch()) {
                                        $server->push($frame->fd, json_encode([
                                            "type" => "msg",
                                            "data" => "当前有人正在点歌，请稍后再试"
                                        ]));
                                    } else {
                                        if(mb_strlen($json['data']) > env('MAX_CHATLENGTH',200)) {
                                            $server->push($frame->fd, json_encode([
                                                "type" => "msg",
                                                "data" => "消息过长，最多 " . env('MAX_CHATLENGTH',200) . " 字符"
                                            ]));
                                        } else {

                                            // 提交任务给服务器
                                            $task = ApplicationContext::getContainer()->get(MusicSearchTask::class);
                                            if (mb_substr($json['data'], 3, 3) == "咪咕 ") {
                                                $musicName = trim(mb_substr($json['data'], 6));
                                                $task->search(["id" => $frame->fd, "data" => $musicName, 'channel' => 'MIGU']);
                                            } else {
                                                $task->search(["id" => $frame->fd, "data" => $musicName]);
                                            }

                                            // 广播给所有客户端
                                            $userNickName = $this->musicService->getUserNickname($clientIp);
                                            foreach($clients as $id) {
                                                $showUserName = $this->musicService->getClientIp($id) == $adminIp ? $clientIp : $username;
                                                if($userNickName) {
                                                    $showUserName = "{$userNickName} ({$showUserName})";
                                                }
                                                $server->push((int)$id, json_encode([
                                                    "type" => "chat",
                                                    "user" => htmlspecialchars($showUserName),
                                                    "time" => date("Y-m-d H:i:s"),
                                                    "data" => htmlspecialchars($json['data'])
                                                ]));
                                            }
                                        }
                                    }
                                } else {
                                    $server->push($frame->fd, json_encode([
                                        "type" => "msg",
                                        "data" => "歌曲名不能为空！"
                                    ]));
                                }

                            } else {

                                // 默认消息内容，即普通聊天，广播给所有客户端
                                if(mb_strlen($json['data']) > env('MAX_CHATLENGTH',200)) {
                                    $server->push($frame->fd, json_encode([
                                        "type" => "msg",
                                        "data" => "消息过长，最多 " . env('MAX_CHATLENGTH',200) . " 字符"
                                    ]));
                                } else {
                                    if($this->musicService->isAdmin($clientIp)) {
                                        $username = "管理员";
                                    }
                                    $userNickName = $this->musicService->getUserNickname($clientIp);
                                    foreach($clients as $id) {
                                        $showUserName = $this->musicService->isAdmin($this->musicService->getClientIp($id)) ? $clientIp : $username;
                                        if($userNickName) {
                                            $showUserName = "{$userNickName} ({$showUserName})";
                                        }
                                        $server->push((int)$id, json_encode([
                                            "type" => "chat",
                                            "user" => htmlspecialchars($showUserName),
                                            "time" => date("Y-m-d H:i:s"),
                                            "data" => htmlspecialchars($json['data'])
                                        ]));
                                    }
                                }
                            }
                        }
                        break;
                    case "heartbeat":
                        // 处理客户端发过来心跳包的操作，返回在线人数给客户端
                        $server->push($frame->fd, json_encode([
                            "type" => "online",
                            "data" => count($clients)
                        ]));
                        break;
                    default:
                        // 如果客户端发过来未知的消息类型
                        $this->logger->consoleLog("客户端 {$frame->fd} 发送了未知消息：{$message}", 2, true);

                }
            }
        }
    }

    public function onClose($server, int $fd, int $reactorId): void
    {
        $redis = ApplicationContext::getContainer()->get(Redis::class);
        $redis->hDel(MusicService::USER_SEND_KEY, $fd);
        var_dump('closed');
    }

    public function onOpen($server, Request $request): void
    {

        // 获取客户端的 IP 地址
        if ($this->getIpMethod) {
            $clientIp = $request->header['x-real-ip'] ?? "127.0.0.1";
        } else {
            $clientIp = $request->server['remote_addr'] ?? "127.0.0.1";
        }

        // 将客户端 IP 储存到表中
        $this->musicService->setUserIp($request->fd, $clientIp);

        $this->logger->consoleLog("客户端 {$request->fd} [{$clientIp}] 已连接到服务器", 1, true);

        $server->push($request->fd, json_encode([
            "type" => "msg",
            "data" => "你已经成功连接到服务器！"
        ]));

        $musicPlay = $this->musicService->getMusicPlay();
        $musicList = $this->musicService->getMusicShow();

        // 如果当前列表中有音乐可播放
        if ($musicList && !empty($musicList)) {

            // 获取音乐的信息和歌词
            $musicInfo = $musicList[0];
            $lrcs = $this->musicService->getMusicLrcs($musicInfo['id']);
            $currentURL = $musicInfo['file'] ?? $this->musicService->getMusicUrl($musicInfo['id']);

            // 推送给客户端
            $server->push($request->fd, json_encode([
                "type" => "music",
                "id" => $musicInfo['id'],
                "name" => $musicInfo['name'],
                "file" => $currentURL,
                "album" => $musicInfo['album'],
                "artists" => $musicInfo['artists'],
                "image" => $musicInfo['image'],
                "current" => $musicPlay + 1,
                "lrcs" => $lrcs,
                "user" => $musicInfo['user']
            ]));

            // 播放列表更新
            $playList = $this->musicService->getPlayList($musicList);
            $server->push($request->fd, json_encode([
                "type" => "list",
                "data" => $playList
            ]));
        }
    }
}