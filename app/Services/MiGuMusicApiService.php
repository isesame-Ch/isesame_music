<?php


namespace App\Services;


use App\Helper;
use Hyperf\Utils\ApplicationContext;

class MiGuMusicApiService implements MusicApiInterface
{
    protected $musicApi;

    public function __construct()
    {
        $this->musicApi = 'https://pd.musicapp.migu.cn';
    }

    /**
     * @inheritDoc
     */
    public function searchMusicInfo($keyWord)
    {
        $keyWord = urlencode($keyWord);
        $url = "{$this->musicApi}/MIGUM3.0/v1.0/content/search_all.do?&ua=Android_migu&version=5.0.1&text={$keyWord}&pageNo=1&pageSize=1&searchSwitch={\"song\":1,\"album\":0,\"singer\":0,\"tagSong\":0,\"mvSong\":0,\"songlist\":0,\"bestShow\":1}";
        echo $keyWord.PHP_EOL;
        $rawdata = @file_get_contents("{$url}");
        $data = json_decode($rawdata, true);
        $return = [];
        if (!empty($data)) {
            if (!isset($data['songResultData'])) {
                return [];
            }
            $data = $data['songResultData']['result'][0];
            $return = [
                'id' => $data['contentId'],
                'name' => $data['name'],
                'artists' => $data['singers'],
                'lyricUrl' => $data['lyricUrl'],
                'imgUrl' => $data['imgItems'][0]['img'],
                'album' => $data['albums'][0]
            ];
        }

        return $return;
    }

    /**
     * @inheritDoc
     */
    public function getMusicUrl($id)
    {
        $url = "https://app.pd.nf.migu.cn/MIGUM2.0/v1.0/content/sub/listenSong.do?toneFlag=HQ&netType=00&userId=15548614588710179085069&ua=Android_migu&version=5.1&copyrightId=0&contentId={$id}&resourceType=2&channel=0";
        $helper = ApplicationContext::getContainer()->get(Helper::class);
        $musicUrl = $helper->get_redirect_url($url);
        return $musicUrl;

    }

    /**
     * @param mixed ...$data
     * @return false|mixed|string
     */
    public function getMusicLrcs(...$data)
    {
        $id = $data[0];
        $url = $data[1];
        if(!file_exists(BASE_PATH . "/tmp/{$id}.lrc")) {
            $musicLrcs = @file_get_contents($url);
            if(strlen($musicLrcs) > 0) {
                @file_put_contents(BASE_PATH . "/tmp/{$id}.lrc", $musicLrcs);
            }
        } else {
            $musicLrcs = @file_get_contents(BASE_PATH . "/tmp/{$id}.lrc");
        }
        return $musicLrcs;
    }

    /**
     * @inheritDoc
     */
    public function getMusicImage($url)
    {
        return $url;
    }

    /**
     * @inheritDoc
     */
    public function getArtistsInfo($data)
    {
        if(count($data) > 1) {
            $artists = "";
            foreach($data as $artist) {
                $artists .= $artist['name'] . ",";
            }
            $artists = $artists == "" ? "未知歌手" : mb_substr($artists, 0, mb_strlen($artists) - 1);
        } else {
            $artists = $data[0]['name'];
        }
        return $artists;
    }
}