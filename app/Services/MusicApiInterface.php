<?php


namespace App\Services;


interface MusicApiInterface
{

    /**
     * @return mixed
     */
    public function searchMusicInfo($keyWord);

    /**
     * @return mixed
     */
    public function getMusicUrl($id);

    /**
     * @return mixed
     */
    public function getMusicLrcs(...$data);

    /**
     * @return mixed
     */
    public function getMusicImage($picId);

    /**
     * @return mixed
     */
    public function getArtistsInfo($data);
}