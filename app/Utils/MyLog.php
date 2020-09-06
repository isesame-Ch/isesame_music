<?php

namespace App\Utils;

class MyLog
{
    /**
     * GetLoggerLevel 获取输出日志的等级
     * @param $level
     * @return string
     */
    public function getLoggerLevel($level)
    {
        $levelGroup = ["DEBUG", "INFO", "WARNING", "ERROR", "console"];
        return $levelGroup[$level] ?? "INFO";
    }

    /**
     * ConsoleLog 控制台输出日志
     * @param $data
     * @param int $level
     * @param false $directOutput
     * @return string
     */
    public function consoleLog($data, $level = 1, $directOutput = false)
    {
        $msgData = "[" . date("Y-m-d H:i:s") . " " . $this->getLoggerLevel($level) . "] {$data}" . PHP_EOL;
        if($directOutput) {
            echo $msgData;
            if ($level == 5) {
                file_put_contents(BASE_PATH."/logs",$msgData,FILE_APPEND);
            }
        } else {
            if ($level == 5) {
                file_put_contents(BASE_PATH."/logs",$msgData,FILE_APPEND);
            }
            return $msgData;
        }
        return true;
    }
}