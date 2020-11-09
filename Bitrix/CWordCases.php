<?php
namespace Project\Dictionary;

use \Bitrix\Highloadblock\HighloadBlockTable as HLT;
use \Bitrix\Main\Loader;
use \Bitrix\Main\Type;
use \Bitrix\Main\Web\HttpClient;
use \Bitrix\Iblock\Model\Section as SectionModel;
use \Project\Cache\CExtCache;
use \Project\Settings\CConstants;
use \Project\Logs\CLogs;

Loader::includeModule('highloadblock');

class CWordCases
{
    private static $sEntityName = 'WordsCases';

    private static $arCasesFields = [
        'UF_NOMINATIVE',
        'UF_GENTIVE',
        'UF_DATIVE',
        'UF_ACCUSATIVE',
        'UF_INSTRUMENTAL',
        'UF_PREPOSITIONAL',
        'UF_NOMINATIVE_M',
        'UF_GENTIVE_M',
        'UF_DATIVE_M',
        'UF_ACCUSATIVE_M',
        'UF_INSTRUMENTAL_M',
        'UF_PREPOSITIONAL_M',
    ];

    private $sClassName = '';
    private $iProductIBlockId = 8;

    private static $sMorpherBase = 'https://ws3.morpher.ru';

    private static $arHttpOptions = [
        "redirect"               => true, // true, если нужно выполнять редиректы
        "redirectMax"            => 5, // Максимальное количество редиректов
        "waitResponse"           => true, // true - ждать ответа, false - отключаться после запроса
        "socketTimeout"          => 5, // Таймаут соединения, сек
        "streamTimeout"          => 5, // Таймаут чтения ответа, сек, 0 - без таймаута
        "version"                => HttpClient::HTTP_1_0, // версия HTTP (HttpClient::HTTP_1_0 или HttpClient::HTTP_1_1)
        "proxyHost"              => "", // адрес
        "proxyPort"              => "", // порт
        "proxyUser"              => "", // имя
        "proxyPassword"          => "", // пароль
        "compress"               => false, // true - принимать gzip (Accept-Encoding: gzip)
        "charset"                => "", // Кодировка тела для POST и PUT
        "disableSslVerification" => false, // true - отключить проверку ssl (с 15.5.9)
    ];

    private static $arHLFieldToMorpher = [
        'NOMINATIVE'    => 'И',
        'GENTIVE'       => 'Р',
        'DATIVE'        => 'Д',
        'ACCUSATIVE'    => 'В',
        'INSTRUMENTAL'  => 'Т',
        'PREPOSITIONAL' => 'П',
    ];

    private static $sSingularPrefix = 'singular_';

    public function __construct()
    {
        $arHLTData = HLT::getList(array('filter' => array('NAME' => self::$sEntityName)))->fetch();
        $obEntity = HLT::compileEntity($arHLTData);
        $this->sClassName = $obEntity->getDataClass();
        $this->iProductIBlockId = CConstants::getIBlockIdValue('catalog', 'catalog_1c');

        if (empty($this->sClassName)) return false;

        return true;
    }

    /**
     * Метод собирает и кэширует данные справочника падежей слов
     *
     * @return array|bool|mixed
     */
    private static function getWordsCases()
    {
        $sClassName = str_replace('\\', '/', __CLASS__);
        // дополнительный параметр для ID кэша
        $sCacheAddParam = '';

        // идентификатор кэша (обязательный и уникальный параметр)
        $sCacheId = $sClassName.'||'.__FUNCTION__;

        // массив тегов для тегированного кэша (если пустой, то тегированный кэш не будет использован)
        $arAddCacheTags = ['DICTIONARY', 'WORD_CASES'];

        // путь для сохранения кэша
        $sCachePath = '/'.$sClassName.'/'.__FUNCTION__.'/';

        // сохранять ли значения дополнительно в виртуальном кэше
        $bUseStaticCache = false;

        // соберем в массив идентификационные параметры кэша
        $arCacheIdParams = [__FUNCTION__, $sCacheId, $arAddCacheTags, $sCacheAddParam];

        $obExtCache = new CExtCache($arCacheIdParams, $sCachePath, $arAddCacheTags, $bUseStaticCache);
        $obExtCache->SetDefaultCacheTime(60 * 60 * 24);

        if ($obExtCache->InitCache()) {
            $arHLData = $obExtCache->GetVars();
        } else {
            // открываем кэшируемый участок
            $obExtCache->StartDataCache();

            $arHLTData = HLT::getList(array('filter' => array('NAME' => self::$sEntityName)))->fetch();

            if (empty($arHLTData)) {
                $obExtCache->AbortDataCache();

                return false;
            }

            $obEntity = HLT::compileEntity($arHLTData);
            $sEntityDataClass = $obEntity->getDataClass();

            $arSelect = self::$arCasesFields;
            $arSelect[] = 'UF_NAME';
            $obHLData = $sEntityDataClass::getList([
                'filter' => ['*'],
                'select' => $arSelect,
            ]);

            $arHLData = array();
            while ($arRow = $obHLData->fetch()) {
                if (!empty($arRow['UF_NAME'])) {
                    $arHLData[$arRow['UF_NAME']] = $arRow;
                }
            }

            // закрываем кэшируемый участок
            $obExtCache->EndDataCache($arHLData);
        }

        return $arHLData;
    }

    public static function getWordCases($sWord, $arCasesToGet = [])
    {
        $arResult = [];

        if (empty($sWord) || (isset($arCasesToGet) && !is_array($arCasesToGet))) return $arResult;

        $arCases = self::getWordsCases();

        if (count($arCasesToGet) > 0) {
            foreach ($arCasesToGet as $sCaseToGet) {
                $arResult[$sCaseToGet] = $arCases[$sWord][$sCaseToGet];
            }
        } else {
            $arResult = $arCases[$sWord];
        }

        return $arResult;
    }

    public function updateWordsCasesTable($bOnlySections = false)
    {
        $arExistWords = $this->getExistWords();
        $sEntityDataClass = $this->sClassName;

        $arSections = $this->getSections();
        foreach ($arSections as $arSection) {
            if (!isset($arExistWords[$arSection['NAME']])) {
                $arFields = [
                    'UF_NAME'  => $arSection['NAME'],
                    'UF_SCODE' => $arSection['CODE'],
                ];
                $sEntityDataClass::add($arFields);
            }
            if (!empty($arSection['UF_SINGULAR']) && !isset($arExistWords[$arSection['UF_SINGULAR']])) {
                $arFields = [
                    'UF_NAME'  => $arSection['UF_SINGULAR'],
                    'UF_SCODE' => self::$sSingularPrefix.$arSection['CODE'],
                ];
                $sEntityDataClass::add($arFields);
            }
        }

        if (!$bOnlySections) {
            $arProps = $this->getPropertiesValues();
            foreach ($arProps as $sPropKey => $arProp) {
                foreach ($arProp['VALUES'] as $arValue) {
                    if (isset($arExistWords[$arValue['NAME']])) {
                        continue;
                    }
                    $arFields = [
                        'UF_NAME'     => $arValue['NAME'],
                        'UF_SCODE'    => $arProp['CODE'],
                        'UF_VALUE_ID' => $arValue['XML_ID'],
                    ];
                    $sEntityDataClass::add($arFields);
                }
            }
        }

    }

    public function onlineMorpherUpdateWordsCasesTable()
    {
        $arExistWords = $this->getNonUpdatedWords();
        $sEntityDataClass = $this->sClassName;
        $iUpdated = 0;

        if (!empty($arExistWords)) {

            $sToken = CConstants::getConstantValue('MORPHER_TOKEN');
            $obHttpClient = new HttpClient(self::$arHttpOptions);

            $sLeftUrl = self::$sMorpherBase.'/get_queries_left_for_today?token='.$sToken.'&format=json';
            $iLeftResult = intval($obHttpClient->get($sLeftUrl));
            if ($iLeftResult <= 0) {
                return false;
            }

            $sUrlBase = self::$sMorpherBase.'/russian/declension?format=json&token=' . $sToken;

            foreach ($arExistWords as $arExistWord) {
                $sUrl = $sUrlBase.'&s=' . urlencode($arExistWord['UF_NAME']);
                $sResult = $obHttpClient->get($sUrl);
                $iLeftResult--;
                if (!empty($sResult)) {

                    $arUpdate = [];
                    $arUpdate['UF_UPDATE_DATETIME'] = new Type\DateTime();
                    $arResult = json_decode($sResult, true);

                    // ошибки по кодам:
                    // http://morpher.ru/ws3/#errors
                    // 1	Превышен лимит на количество запросов в сутки. Перейдите на следующий тарифный план.
                    // 3	IP заблокирован.
                    // 4	Склонение числительных в declension не поддерживается. Используйте метод spell.
                    // 5	Не найдено русских слов.
                    // 6	Не указан обязательный параметр s.
                    // 7	Необходимо оплатить услугу.
                    // 9	Данный token не найден.
                    // 10	Неверный формат токена.

                    if (!empty($arResult['code'])) {
                        $arResult['code'] = intval($arResult['code']);
                        if (in_array($arResult['code'], [1, 3, 6, 7, 9, 10])) {
                            CLogs::LogBackgroundWorkError(
                                "Ошибка при работе с Morpher! \n".
                                $arResult['message']
                            );
                            break;
                        }
                    } else {
                        foreach(self::$arHLFieldToMorpher as $sEngName => $sRusLetter) {

                            $sWord = !empty($arResult[$sRusLetter]) ? $arResult[$sRusLetter] : '';
                            if ($sEngName == 'NOMINATIVE' && empty($sWord)) {
                                $sWord = $arExistWord['UF_NAME'];
                            }
                            $sWordM = !empty($arResult['множественное'][$sRusLetter]) ? $arResult['множественное'][$sRusLetter] : '';

                            $arUpdate['UF_'.$sEngName] = $sWord;
                            $arUpdate['UF_'.$sEngName.'_M'] = $sWordM;

                        }
                    }
                    $sEntityDataClass::update($arExistWord['ID'], $arUpdate);
                    $iUpdated++;

                }
                if ($iLeftResult <= 0) {
                    break;
                }
            }

        }

        return $iUpdated;

    }

    private function getSections()
    {
        $arSections = [];

        $obSectionEntity = SectionModel::compileEntityByIblock($this->iProductIBlockId);
        $obSections = $obSectionEntity::getList(array(
            'select' => ['NAME', 'CODE', 'UF_SINGULAR'],
            'filter' => [
                '=IBLOCK_ID' => $this->iProductIBlockId,
            ],
        ));
        while ($arSection = $obSections->fetch()) {
            $arSections[] = $arSection;
        }

        return $arSections;
    }

    private function getExistWords()
    {
        $arResult = [];

        $sEntityDataClass = $this->sClassName;

        $obHLData = $sEntityDataClass::getList([
            'filter' => ['*'],
            'select' => ['UF_NAME'],
        ]);
        while ($arRow = $obHLData->fetch()) {
            $arResult[$arRow['UF_NAME']] = true;
        }

        return $arResult;
    }

    private function getNonUpdatedWords()
    {
        $arResult = [];

        $sEntityDataClass = $this->sClassName;

        $obHLData = $sEntityDataClass::getList([
            'filter' => ['=UF_UPDATE_DATETIME' => false],
            'select' => ['*'],
        ]);
        while ($arRow = $obHLData->fetch()) {
            $arResult[$arRow['ID']] = $arRow;
        }

        return $arResult;
    }

    private function getPropertiesValues()
    {
        $arResult = [];

        $arPropsCodes = CConstants::getConstantValueMulti('PROPERTIES_CASES');
        $iOffersIBlockId = CConstants::getIBlockIdValue('catalog', 'catalog_1c_tp');
        if (empty($arPropsCodes) || empty($iOffersIBlockId)) return $arResult;

        $obProperties = \Bitrix\Iblock\PropertyTable::getList(array(
            'select' => array('ID', 'PROPERTY_TYPE', 'USER_TYPE', 'USER_TYPE_SETTINGS', 'CODE'),
            'filter' => array('=IBLOCK_ID' => array($iOffersIBlockId, $this->iProductIBlockId), 'CODE' => $arPropsCodes),
        ));

        $arLists = [];
        $arDirectories = [];
        $arIdCodeMatrix = [];
        while ($arProperty = $obProperties->fetch()) {
            if ($arProperty['PROPERTY_TYPE'] == 'S' && $arProperty['USER_TYPE'] == 'directory') { // справочник
                $arUserTypeSettings = unserialize($arProperty['USER_TYPE_SETTINGS']);
                $arDirectories[$arProperty['ID']] = $arUserTypeSettings['TABLE_NAME'];
            } elseif ($arProperty['PROPERTY_TYPE'] == 'L' && empty($arProperty['USER_TYPE'])) { // список
                $arLists[$arProperty['ID']] = $arProperty['ID'];
            }

            $arIdCodeMatrix[$arProperty['ID']] = $arProperty['CODE'];
        }

        foreach ($arDirectories as $iPropId => $sTableName) {
            $arResult[$iPropId]['VALUES'] = $this->getPropertiesDirectoryData($sTableName);
            $arResult[$iPropId]['CODE'] = $arIdCodeMatrix[$iPropId];
        }

        $obListProperties = \Bitrix\Iblock\PropertyEnumerationTable::getList([
            'filter' => ['=PROPERTY_ID' => $arLists],
            'select' => ['PROPERTY_ID', 'VALUE', 'XML_ID'],
        ]);
        while ($arListProperty = $obListProperties->fetch()) {
            $arResult[$arListProperty['PROPERTY_ID']]['VALUES'][] = [
                'NAME'   => $arListProperty['VALUE'],
                'XML_ID' => $arListProperty['XML_ID'],
            ];
            $arResult[$arListProperty['PROPERTY_ID']]['CODE'] = $arIdCodeMatrix[$arListProperty['PROPERTY_ID']];
        }

        return $arResult;
    }

    private function getPropertiesDirectoryData($sTableName)
    {
        $arResult = array();

        if (empty($sTableName)) return $arResult;

        $arHLTData = HLT::getList(array(
            'filter' => array(
                '=TABLE_NAME' => $sTableName,
            ),
        ))->fetch();
        $obEntity = HLT::compileEntity($arHLTData);
        $sEntityDataClass = $obEntity->getDataClass();

        $obHLData = $sEntityDataClass::getList(array(
            'filter' => array('*'),
            'select' => array('ID', 'UF_NAME', 'UF_XML_ID'),
        ));

        while ($arRow = $obHLData->fetch()) {
            $arResult[$arRow['ID']]['NAME'] = $arRow['UF_NAME'];
            $arResult[$arRow['ID']]['XML_ID'] = $arRow['UF_XML_ID'];
        }

        return $arResult;
    }

    public static function updateWordsCasesTableShellAgent($bOnlySections = false)
    {
        $obWordCases = new self();
        $obWordCases->updateWordsCasesTable($bOnlySections);
        $obWordCases->onlineMorpherUpdateWordsCasesTable();
        CExtCache::clearCacheByTag('WORD_CASES');

        return '\\'.__METHOD__.'();';
    }
}