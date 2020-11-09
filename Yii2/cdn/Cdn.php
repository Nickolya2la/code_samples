<?php

namespace app\components\cdn;

use Yii;
use yii\base\UnknownClassException;

/**
 * Class Cdn
 * @package app\components\cdn
 */
class Cdn
{
    /**
     * Return CDN component
     * @param null $className
     * @param array $params
     * @return BaseCdn
     * @throws UnknownClassException
     */
    public static function factory($className = null, $params = [])
    {
        if (!$className) {
            $className =  Yii::$app->params['cdn']['active'];
        }

        if (!$params) {
            $params =  Yii::$app->params['cdn']['providers'][$className];
        }

        $classPath = '\app\components\cdn\providers\\' . $className;

        if (!class_exists($classPath)) {
            throw new UnknownClassException("CDN " . $classPath . " does not exist.");
        }

        return new $classPath($params);
    }
}