<?php

namespace app\components\cdn\providers;

use yii\base\Exception;
use app\components\cdn\BaseCdn;
use Uploadcare\Api;
use Uploadcare\File;

/**
 * Adapter class for Uploadcare CDN
 * @package app\components\cdn
 *
 * @property Api $_api
 * @property File $_file
 */
class Uploadcare extends BaseCdn
{
    public $public_key;
    public $secret_key;
    public $cdn_host;
    public $cdn_protocol;

    private $_api;
    private $_file;

    /**
     * Return current CDN object
     * @throws Exception
     */
    public function getFile()
    {
        if (!$this->_file instanceof File) {
            throw new Exception('Define file first!');
        }

        return $this->_file;
    }

    /**
     * Set current CDN object
     * @param $cdnId string
     */
    public function setFile($cdnId)
    {
        $this->_file = $this->_api->getFile($cdnId);
    }

    /**
     * @inheritdoc
     */
    public function init()
    {
        parent::init(); // TODO: Change the autogenerated stub

        if (!isset($this->public_key, $this->secret_key, $this->cdn_protocol)) {
            throw new Exception('MESSAGE_BAD_CONFIG');
        }

        $this->_api = new Api($this->public_key, $this->secret_key, null, $this->cdn_host, $this->cdn_protocol);
    }

    /**
     * @inheritdoc
     */
    public function uploadFromPath(string $filePath, $mime = null)
    {
        if (!file_exists($filePath)) {
            throw new Exception(self::MESSAGE_FILE_NOT_FOUND);
        }

        $this->setFile($this->_api->uploader->fromPath($filePath, $mime)->getUuid());
        $this->getFile()->store();

        return $this->getFile()->getUuid();
    }

    /**
     * @inheritdoc
     */
    public function uploadFromContent(string $content, $mime = null)
    {
        $this->setFile($this->_api->uploader->fromContent($content, $mime)->getUuid());
        $this->getFile()->store();

        return $this->getFile()->getUuid();
    }

    /** @inheritdoc */
    public function getUploadInfo()
    {
        return $this->getFile()->data;
    }

    /**
     * @inheritdoc
     */
    public function getId()
    {
        return $this->getFile()->getUuid();
    }

    /**
     * @inheritdoc
     */
    public function getUrl()
    {
        return $this->getFile()->getUrl();
    }

    /**
     * @inheritdoc
     */
    public function delete()
    {
        return $this->getFile()->delete();
    }
}