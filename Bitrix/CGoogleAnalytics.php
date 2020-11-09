<?

namespace Project\Analytics;

use \Bitrix\Main\Loader;
use \Bitrix\Main\Config\Option;
use \Bitrix\Main\SystemException;
use \Bitrix\Sale;
use \Bitrix\Iblock\SectionTable;
use \Bitrix\Highloadblock\HighloadBlockTable as HLT;
use \Project\Settings\CConstants as Constants;
use \Project\Shops\CShopsData;
use \Project\Logs\CLogs;

/**
 * Класс для работы с Google Analytics через POST запросы
 *
 * Class CGoogleAnalytics
 * @package Project\Analytics
 */
class CGoogleAnalytics
{
    const SELECT_ITEMS = 10; // количество выбираемых записей за 1 включение агента
    const SELECT_TIME_TO_DO = 10; // ограничение по времени для выполняемых записей в минутах
    const GOOGLE_URL = 'https://www.google-analytics.com/collect'; // адрес для отправки запроса
    const USER_AGENT = 'Mozilla/4.0 (compatible; MSIE 6.0; Windows NT 5.1; .NET CLR 1.1.4322)'; // User Agent для отправки запроса
    const CONNECTION_TIMEOUT = 10; // таймаут для отправки запроса в секундах
    const LOG_FILE = 'GA_POST.txt'; // файл логов
    const COOKIE_ONE_CLICK_GACID = 'ONE_CLICK_GACID'; // имя куки для сохранения айди юзера гугл аналитики

    /**
     * Оболочка-агент метода sendPostData.
     * Метод отсылает данные о выкупленных заказах в GA через POST запрос
     *
     * @param int $iTimeToNextWork время до следующего запуска агента. 600 секунд по умолчанию
     * @return string строка вызова метода
     */
    public static function sendPostDataShellAgent($iTimeToNextWork = 600)
    {
        try {
            $obGA = new self();
            $obGA->sendPostData();
        } catch (SystemException $obException) {
            CLogs::LogError(
                "Ошибка при отправке данных через POST в GA!\n".
                "Ошибка: ".$obException->getMessage()
            );
        }

        return '\\'.__METHOD__.'('.$iTimeToNextWork.');';
    }

    private static function writeToLog($sData)
    {
        \Project_log_array($sData, self::LOG_FILE, false);
    }

    /**
     * Метод отсылает данные о выкупленных заказах в GA через POST запрос
     */
    private static function sendPostData()
    {

        // выполняем только на продакшене, на всякий случай, чтобы никакая статистика не дублировалась
        if (defined('CONST_ENVIROMENT') && CONST_ENVIROMENT != 'prod') {
            return false;
        }

        if (!Loader::includeModule('iblock')) {
            CLogs::LogError(
                "Cant include module SALE!"
            );
            return false;
        }

        if (!Loader::includeModule('sale')) {
            CLogs::LogError(
                "Cant include module SALE!"
            );
            return false;
        }

        $objDateTime = new \Bitrix\Main\Type\DateTime();
        $objDateTime->add(self::SELECT_TIME_TO_DO.' minutes');

        $arSendData = [];

        $obSendOrderItemToGA = CSendOrderItemsToGATable::getList([
            'filter' => [
                '=TIME_SUCCESS' => 0,
                '<=TIMESTAMP'   => $objDateTime,
            ],
            'select' => ['ID', 'ORDER_ID', 'BASKET_ID', 'GA_CLIENT_ID'],
            'order'  => ['ID' => 'ASC'],
            'limit'  => self::SELECT_ITEMS,
        ]);
        while ($arSendOrderItemToGA = $obSendOrderItemToGA->fetch()) {
            CSendOrderItemsToGATable::update($arSendOrderItemToGA['ID'], ['TIMESTAMP' => new \Bitrix\Main\Type\DateTime()]);
            $obOrder = Sale\Order::load($arSendOrderItemToGA['ORDER_ID']);
            $arSendOrderItemToGA['ORDER'] = $obOrder->getFields()->getValues();
            $arSendOrderItemToGA['ORDER']['PROPS'] = [];
            foreach ($obOrder->getPropertyCollection() as $obOrderPropertyItem) {
                $arSendOrderItemToGA['ORDER']['PROPS'][$obOrderPropertyItem->getField('CODE')] = $obOrderPropertyItem->getField('VALUE');
            }
            if (!empty($arSendOrderItemToGA['BASKET_ID'])) {
                $arItem = self::checkDataForTakeAway($arSendOrderItemToGA, $obOrder);
                if (!empty($arItem)) {
                    $arSendData[$arSendOrderItemToGA['ID']] = $arItem;
                }
            } elseif (!empty($arSendOrderItemToGA['GA_CLIENT_ID']) && $arSendOrderItemToGA['BASKET_ID'] == 0) {
                $arItem = self::checkDataForPurchase($arSendOrderItemToGA, $obOrder);
                if (!empty($arItem)) {
                    $arSendData[$arSendOrderItemToGA['ID']] = $arItem;
                }
            }
        }
        foreach ($arSendData as $iId => $arSendDataItem) {
            self::sendDataToGoogle($iId, $arSendDataItem);
        }
    }

    private static function checkDataForTakeAway(&$arSendOrderItemToGA, &$obOrder)
    {
        $arResult = false;

        // проверка на заполненное свойство заказа "Google client_id"
        if ($arSendOrderItemToGA['ORDER']['PROPS']['ProjectGACLIENTID'] != '') {
            if ($obBasket = $obOrder->getBasket()) {
                $arBasketItems = $obBasket->getBasketItems();
                $bItemFound = false;
                foreach ($arBasketItems as $obBasketItem) {
                    $arBasetItemProps = [];
                    foreach ($obBasketItem->getPropertyCollection() as $obPropertyItem) {
                        $arBasetItemProps[$obPropertyItem->getField('CODE')] = $obPropertyItem->getField('VALUE');
                    }
                    // находим нужный товар по свойству НомерПозицииКорзины
                    if (!empty($arBasetItemProps['НомерПозицииКорзины']) && $arBasetItemProps['НомерПозицииКорзины'] == $arSendOrderItemToGA['BASKET_ID']) {
                        // проверка на наличие в товаре свойства TOVAR_PRODAN равное 1
                        if (!empty($arBasetItemProps['TOVAR_PRODAN']) && $arBasetItemProps['TOVAR_PRODAN'] == 1) {
                            $arSendOrderItemToGA['ITEM'] = $obBasketItem->getFields()->getValues();
                            $arResult = self::makeDataForTakeAway($arSendOrderItemToGA);
                            $bItemFound = true;
                        } else {
                            CSendOrderItemsToGATable::delete($arSendOrderItemToGA['ID']);
                            self::writeToLog(
                                "У заказа ".$arSendOrderItemToGA['ORDER_ID']." товар с НомерПозицииКорзины=".$arSendOrderItemToGA['BASKET_ID']." имеет значение TOVAR_PRODAN=".$arBasetItemProps['TOVAR_PRODAN']
                            );
                        }
                        break;
                    }
                }
                if (!$bItemFound) {
                    CSendOrderItemsToGATable::delete($arSendOrderItemToGA['ID']);
                    self::writeToLog(
                        "У заказа ".$arSendOrderItemToGA['ORDER_ID']." не найден товар с НомерПозицииКорзины=".$arSendOrderItemToGA['BASKET_ID']
                    );
                }
            } else {
                CSendOrderItemsToGATable::delete($arSendOrderItemToGA['ID']);
                self::writeToLog(
                    "У заказа ".$arSendOrderItemToGA['ORDER_ID']." не получена корзина"
                );
            }
        } else {
            CSendOrderItemsToGATable::delete($arSendOrderItemToGA['ID']);
            self::writeToLog(
                "У заказа ".$arSendOrderItemToGA['ORDER_ID']." не установлено свойство Google client_id"
            );
        }

        return $arResult;
    }

    private static function makeDataForTakeAway(&$arSendOrderItemToGA)
    {
        // инфа по данным запроса: https://link
        $arSendParams = [
            'v'   => '1',
            't'   => 'event',
            'tid' => Constants::getConstantValue('GA_CODE_ID'),    // константа 'Код Google Analytics для отправки данных через POST'
            'cid' => $arSendOrderItemToGA['ORDER']['PROPS']['ProjectGACLIENTID'],    // client_id из заказа
            'uid' => (string)$arSendOrderItemToGA['ORDER']['USER_ID'], // ID пользователя в битриксе user_id
            'ds'  => 'bitrix',
            'ec'  => 'ecommerce',
            'ea'  => 'take_away',
            'el'  => \Project_formatOrderNumber($arSendOrderItemToGA['ORDER']['ACCOUNT_NUMBER']),  // номер заказа, к которому относится выкуп
            'dh'  => Option::get("main", "server_name"),   // константа "URL сайта" из настроек главного модуля = site.ru
            'ev'  => (string)round($arSendOrderItemToGA['ITEM']['PRICE'] * $arSendOrderItemToGA['ITEM']['QUANTITY']),    // на какую сумму был выкуп, целочисленное значение
        ];

        return $arSendParams;
    }

    private static function checkDataForPurchase(&$arSendOrderItemToGA, &$obOrder)
    {
        $arResult = false;

        $arSendOrderItemToGA['ORDER']['SHOP_NAME'] = '';
        if (!empty($arSendOrderItemToGA['ORDER']['PROPS']['PARTNER_GUID'])) {
            $obShopsData = new CShopsData();
            $arShopsData = $obShopsData->getShopsData(
                CShopsData::getShopsIdByXmlId($arSendOrderItemToGA['ORDER']['PROPS']['PARTNER_GUID'])
            );
            if (!empty($arShopsData['NAME'])) {
                $arSendOrderItemToGA['ORDER']['SHOP_NAME'] = $arShopsData['NAME'];
            }
        }
        if ($obBasket = $obOrder->getBasket()) {
            $arBasketItems = $obBasket->getBasketItems();
            $arOfferIds = [];
            foreach ($arBasketItems as $obBasketItem) {
                $arFields = $obBasketItem->getFields()->getValues();
                $arSendOrderItemToGA['ITEMS'][$arFields['PRODUCT_ID']] = $arFields;
                $arOfferIds[] = $arFields['PRODUCT_ID'];
            }
            if (!empty($arSendOrderItemToGA['ITEMS'])) {
                $obProducts = \CIBlockElement::GetList(
                    [],
                    [
                        'ID' => $arOfferIds,
                        'IBLOCK_ID' => Constants::getIBlockIdValue('catalog', 'catalog_1c_tp'),
                    ],
                    false,
                    false,
                    [
                        'ID',
                        'IBLOCK_ID',
                        'PROPERTY_CML2_LINK.NAME',
                        'PROPERTY_CML2_LINK.PROPERTY_CML2_ARTICLE',
                        'PROPERTY_DLINA',
                        'PROPERTY_RAZMER',
                        'PROPERTY_CML2_LINK.IBLOCK_SECTION_ID',
                    ]
                );
                while ($arProduct = $obProducts->Fetch()) {
                    $arProduct['SECTION'] = self::getSectionById($arProduct['PROPERTY_CML2_LINK_IBLOCK_SECTION_ID']);
                    $arSendOrderItemToGA['ITEMS'][$arProduct['ID']]['CATALOG_DATA'] = $arProduct;
                }
                $arResult = self::makeDataForPurchase($arSendOrderItemToGA);
            } else {
                CSendOrderItemsToGATable::delete($arSendOrderItemToGA['ID']);
                self::writeToLog(
                    "У заказа ".$arSendOrderItemToGA['ORDER_ID']." не найдены товары"
                );
            }
        } else {
            CSendOrderItemsToGATable::delete($arSendOrderItemToGA['ID']);
            self::writeToLog(
                "У заказа ".$arSendOrderItemToGA['ORDER_ID']." не получена корзина"
            );
        }

        return $arResult;
    }

    private static function makeDataForPurchase(&$arSendOrderItemToGA)
    {

        $sOrderNumber = \Project_formatOrderNumber($arSendOrderItemToGA['ORDER']['ACCOUNT_NUMBER']);
        $sOrderNumber = preg_replace('/^\d+\-/ui', '11-', $sOrderNumber);

        // инфа по данным запроса: https://link
        $arSendParams = [
            'v'   => '1',
            't'   => 'event',
            'tid' => Constants::getConstantValue('GA_CODE_ID'),    // константа 'Код Google Analytics для отправки данных через POST'
            'cid' => $arSendOrderItemToGA['GA_CLIENT_ID'],    // client_id из куки
            'ec'  => 'ecommerce',
            'ea'  => 'transaction',
            'ds'  => 'bitrix',
            'dh'  => Option::get("main", "server_name"),   // константа "URL сайта" из настроек главного модуля = site.ru
            'pa'  => 'purchase',
            'ti'  => $sOrderNumber, // номер транзакции, желательно чтобы у него был формат 11-...
            'ta'  => $arSendOrderItemToGA['ORDER']['SHOP_NAME'],  // магазин
            'tr'  => (string)round($arSendOrderItemToGA['ORDER']['PRICE'], 2),    // сумма заказа
        ];
        $iItem = 1;
        foreach($arSendOrderItemToGA['ITEMS'] as $arItem) {
            $sProduct = 'pr'.$iItem;
            $arSendParams[$sProduct.'nm'] = $arItem['CATALOG_DATA']['PROPERTY_CML2_LINK_NAME'];   // название товара
            $arSendParams[$sProduct.'ca'] = (string)$arItem['CATALOG_DATA']['SECTION']['NAME'];   // категория
            $arSendParams[$sProduct.'id'] = $arItem['CATALOG_DATA']['PROPERTY_CML2_LINK_PROPERTY_CML2_ARTICLE_VALUE'];   // артикул
            $sVariant = '';
            if (!empty($arItem['CATALOG_DATA']['PROPERTY_RAZMER_VALUE'])) {
                $sVariant = self::getSizeByXmlId($arItem['CATALOG_DATA']['PROPERTY_RAZMER_VALUE'], 'RAZMER');
            } elseif (!empty($arItem['CATALOG_DATA']['PROPERTY_DLINA_VALUE'])) {
                $sVariant = self::getSizeByXmlId($arItem['CATALOG_DATA']['PROPERTY_DLINA_VALUE'], 'DLINA');
            }
            $arSendParams[$sProduct.'va'] = $sVariant;   // вариант-размер
            $arSendParams[$sProduct.'pr'] = (string)round($arItem['PRICE'], 2);   // цена товара
            $arSendParams[$sProduct.'qt'] = (string)intval($arItem['QUANTITY']);   // количество товаров этого типа
            $iItem++;
        }

        return $arSendParams;
    }

    private static function getSectionById($iSectionId)
    {
        $iIblockId = Constants::getIBlockIdValue('catalog', 'catalog_1c_erp_prod');

        $arSection = SectionTable::getRow([
            'filter' => [
                '=IBLOCK_ID' => $iIblockId,
                '=ID'        => $iSectionId,
            ],
            'select' => [
                'ID',
                'NAME',
            ],
        ]);

        return $arSection;
    }

    private static function getSizeByXmlId($sGuid, $sEntity)
    {
        $sResult = '';
        $arHlData = HLT::getList(['filter' => ['=NAME' => $sEntity]])->fetch();
        $obEntity = HLT::compileEntity($arHlData);
        $sEntityDataClass = $obEntity->getDataClass();

        $obResult = $sEntityDataClass::query()
            ->setFilter(['=UF_XML_ID' => $sGuid])
            ->setSelect(['UF_NAME'])
            ->setCacheTtl(24 * 60 * 60)
            ->exec();
        if ($arRow = $obResult->fetch()) {
            $sResult = $arRow['UF_NAME'];
        }
        unset($arHlData);

        return $sResult;
    }

    private static function sendDataToGoogle($iItemId, &$arSendParams)
    {

        $arSendParamsString = [];
        foreach ($arSendParams as $k => $v) {
            $k = urldecode($k);
            $v = urldecode($v);
            if ($v != '') {
                $sSendParamsString[] = $k.'='.$v;
            } else {
                $sSendParamsString[] = $k;
            }
        }
        $sSendParamsString = implode('&', $sSendParamsString);

        if (function_exists('curl_init') && $curl = curl_init()) {

            $iStartTime = microtime(1);

            curl_setopt($curl, CURLOPT_URL, self::GOOGLE_URL);
            curl_setopt($curl, CURLOPT_USERAGENT, self::USER_AGENT);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, self::CONNECTION_TIMEOUT);
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $sSendParamsString);
            $sData = curl_exec($curl);
            $nHttpcode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);

            if ($nHttpcode >= 200 && $nHttpcode < 300) {
                $iTime = microtime(1) - $iStartTime;
                CSendOrderItemsToGATable::update($iItemId, ['TIME_SUCCESS' => $iTime, 'SENDED_DATA' => $sSendParamsString]);
                self::writeToLog(
                    "[+] Успешно отправлены данные с кодом ".$nHttpcode." за ".$iTime." секунд\n".
                    "Запрос: ".$sSendParamsString."\n".
                    "Запрос массив: ".print_r($arSendParams, true)
                );
                return true;
            } else {
                self::writeToLog(
                    "Ошибочный код http: ".$nHttpcode."\n".
                    "Запрос: ".$sSendParamsString."\n".
                    "Запрос массив: ".print_r($arSendParams, true)."\n".
                    "Ответ: ".$sData
                );
            }

        } else {
            CLogs::LogError(
                "Не установлена библиотека CURL!"
            );
            self::writeToLog("Не установлена библиотека CURL!");
        }

        return false;

    }

    public static function addDataToDb($arFilter, $arData) {
        $obSendOrderItemToGA = CSendOrderItemsToGATable::getList([
            'filter' => $arFilter,
            'select' => ['ID'],
            'limit'  => 1,
        ]);
        if (!$arSendOrderItemToGA = $obSendOrderItemToGA->fetch()) {
            $obResult = CSendOrderItemsToGATable::add($arData);
            if ($obResult->isSuccess()) {
                return true;
            } else {
                CLogs::LogError(
                    "Ошибка добавления заказа в очередь на отправку в GA!\n".
                    "Данные для добавления: ".var_export($arData, true)."\n".
                    "Ошибки: ".implode("\n", $obResult->getErrorMessages())
                );
            }
        }
        return false;
    }
}