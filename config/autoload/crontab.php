<?php
use Hyperf\Crontab\Crontab;


return [
    // 是否开启定时任务
    'enable' => true,

    // 通过配置文件定义的定时任务
    'crontab' => [
        // Callback类型定时任务（默认）
//        (new Crontab())->setName('MusicStart')->setRule('*\/2 * * * * *')->setCallback([App\Task\MusicStartTask::class, 'Start'])->setMemo('连接开始'),
    ],
];