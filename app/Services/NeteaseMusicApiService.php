<?php


namespace App\Services;


class NeteaseMusicApiService implements MusicApiInterface
{
    protected $musicApi;

    public function __construct()
    {
        $this->musicApi = 'https://cdn.zerodream.net/netease';
    }

    /**
     * @inheritDoc
     */
    public function searchMusicInfo($keyWord)
    {
        $keyWord = urlencode($keyWord);
        $url = "{$this->musicApi}/api.php?source=netease&types=search&name={$keyWord}&count=1&pages=1";
        echo $keyWord.PHP_EOL;
        $rawdata = @file_get_contents("{$url}");
        return json_decode($rawdata, true);
    }

    /**
     * @inheritDoc
     */
    public function getMusicUrl($id)
    {
        $rawdata = @file_get_contents("{$this->musicApi}/api.php?source=netease&types=url&id={$id}");
        $json    = json_decode($rawdata, true);
        if($json && isset($json["url"])) {
            return str_replace("http://", "https://", $json["url"]);
        } else {
            return "";
        }
    }

    /**
     * @inheritDoc
     */
    public function getMusicLrcs(...$data)
    {
        $id = $data[0];
        if(!file_exists(BASE_PATH . "/tmp/{$id}.lrc")) {
            $musicLrcs = @file_get_contents("https://music.163.com/api/song/lyric?os=pc&lv=-1&id={$id}");
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
    public function getMusicImage($picId)
    {
        $rawdata = @file_get_contents("{$this->musicApi}/api.php?source=netease&types=pic&id={$picId}");
        $imgdata = json_decode($rawdata, true);
        return $imgdata['url'] ?? "";
    }

    /**
     * @inheritDoc
     */
    public function getArtistsInfo($data)
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
}