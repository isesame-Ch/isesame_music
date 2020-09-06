<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://doc.hyperf.io
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */
namespace App\Controller;

use App\Helper;
use App\Services\MiGuMusicApiService;
use App\Services\MusicService;
use App\Task\MongoTask;
use Hyperf\Utils\ApplicationContext;

class IndexController extends AbstractController
{
    public function index()
    {
        $user = $this->request->input('user', 'Hyperf');
        $method = $this->request->getMethod();
        $migu = new MiGuMusicApiService();
//        $result = $migu->searchMusicInfo($this->request->input('k'));
//        var_dump($file);
//        $url = $migu->getMusicUrl($result['id']);;
//        $musicService = new MusicService();
//        var_dump($url);
//        $result = $musicService->fetchMusic($result, "{$url}");

//        $url = "https://app.pd.nf.migu.cn/MIGUM2.0/v1.0/content/sub/listenSong.do?toneFlag=HQ&netType=00&userId=15548614588710179085069&ua=Android_migu&version=5.1&copyrightId=0&contentId=600902000006889366&resourceType=2&channel=0";
        $url = "http://freetyst.nf.migu.cn/public/product9th/product41/2020/08/1013/2009%E5%B9%B406%E6%9C%8826%E6%97%A5%E5%8D%9A%E5%B0%94%E6%99%AE%E6%96%AF/%E6%A0%87%E6%B8%85%E9%AB%98%E6%B8%85/MP3_320_16_Stero/60054701923133602.mp3?channelid=03&k=390f2ff507c65bf1&t=1599073881&msisdn=3591f30e-b475-4677-9591-e13ff2e5b07b";
//        $file_url_header = get_headers($url,1);
//        var_dump($file_url_header['Location']);
        $app = ApplicationContext::getContainer()->get(Helper::class);
        $file_url = $app->get_redirect_url($url);
        var_dump($file_url);

        $file = @file_get_contents($file_url);
        @file_put_contents(BASE_PATH . "/tmp/l123.mp3", $file);

//        var_dump($url == $url2);

        return [
            'method' => $method,
            'message' => "Hello {$user}.",
            'data' => $file_url
        ];
    }

    private function get_redirect_url($url){
        $redirect_url = null;

        $url_parts = @parse_url($url);
        if (!$url_parts) return false;
        if (!isset($url_parts['host'])) return false; //can't process relative URLs
        if (!isset($url_parts['path'])) $url_parts['path'] = '/';

        $sock = fsockopen($url_parts['host'], (isset($url_parts['port']) ? (int)$url_parts['port'] : 80), $errno, $errstr, 30);
        if (!$sock) return false;

        $request = "HEAD " . $url_parts['path'] . (isset($url_parts['query']) ?'?'.$url_parts['query'] : '') . " HTTP/1.1\r\n";
        $request .= 'Host: ' . $url_parts['host'] . "\r\n";
        $request .= "Connection: Close\r\n\r\n";
        fwrite($sock, $request);
        $response = '';
        while(!feof($sock)) $response .= fread($sock, 8192);
        fclose($sock);
        var_dump($response);

        if (preg_match('/^Location: (.+?)$/m', $response, $matches)){
            return trim($matches[1]);
        } else {
            return $url;
        }
    }
}
