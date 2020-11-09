<?php

namespace app\components\cdn;

use yii\base\Exception;
use yii\base\Model;

/**
 * Class BaseCdn
 * @package app\components\cdn
 */
abstract class BaseCdn extends Model
{
    const MIME_JPEG = 'image/jpeg';
    const MIME_PNG  = 'image/png';
    const MIME_GIF  = 'image/gif';
    const MIME_ICO  = 'image/x-icon';
    const MIME_SVG  = 'image/svg+xml';
    const MIME_CSS  = 'text/css';
    const MIME_JS = 'application/javascript';

    const MESSAGE_BAD_CONFIG        = 'Bad CDN-api config!';
    const MESSAGE_FILE_NOT_FOUND    = 'Uploaded file does not exist!';

    /**
     * Upload file to CDN
     * @param $filePath
     * @param $mime string  Uploaded file mime-type
     * @return string|integer CDN object ID
     * @throws Exception
     */
    abstract public function uploadFromPath(string $filePath, $mime = null);


    /**
     * Upload content to CDN
     * @param string $content
     * @param null|string $mime
     * @return mixed
     * @throws Exception
     */
    abstract public function uploadFromContent(string $content, $mime = null);

    /**
     * Set CDN object by CDN object id
     * @param $cdnId
     * @throws Exception
     */
    abstract public function setFile($cdnId);

    /**
     * Return uploaded file cdn info
     * @return array
     * @throws Exception
     */
    abstract public function getUploadInfo();

    /**
     * Get CDN object ID
     * @return string|integer
     * @throws Exception
     */
    abstract public function getId();

    /**
     * Get CDN object url
     * @return string|bool
     * @throws Exception
     */
    abstract public function getUrl();

    /**
     * Delete CDN object
     * @return mixed
     * @throws Exception
     */
    abstract public function delete();

    /**
     * Get CND object url by id
     * @param $cdnId string CDN file id
     * @param $cdnId
     * @return string
     * @throws Exception
     */
    public function getUrlById($cdnId)
    {
        $this->setFile($cdnId);

        return $this->getUrl();
    }

    /**
     * Delete CDN object by id
     * @param $cdnId
     * @return mixed
     * @throws Exception
     */
    public function deleteById($cdnId)
    {
        $this->setFile($cdnId);

        return $this->delete();
    }
}