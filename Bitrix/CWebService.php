<?

namespace Project\Exchange\Sales;

use \Bitrix\Main\Loader;
use \Bitrix\Main\Application;
use \Bitrix\Main\Type\DateTime;
use \Bitrix\Highloadblock\HighloadBlockTable as HLT;
use \Project\Orm\Sales\COrderTable;
use \Project\Orm\Sales\CProductTable;
use \Project\Orm\Sales\CHistoryTable;
use \Project\Exchange\Dictionary\CDictionary;

Loader::includeModule('webservice');

class CSalesWebService extends \IWebService
{

    const LOG_FILE = 'Sales_ws.txt';

    const STATUS_ENTITY_NAME = 'SalesProductStatus';

    private $arOrders = [];
    private $arProducts = [];
    private $arStatuses = [];

    private static function GetEndPoint($bWithRequest = false)
    {

        $obContext = Application::getInstance()->getContext();
        $obRequest = $obContext->getRequest();
        $obServer = $obContext->getServer();
        $sScheme = $obRequest->isHttps() ? 'https' : 'http';
        $sServerName = $obServer->getServerName();
        $sRequestPage = '/';
        if ($bWithRequest) {
            $sRequestPage = $obRequest->getRequestedPage();
        }
        return $sScheme.'://'.$sServerName.$sRequestPage;

    }

    // метод GetWebServiceDesc возвращает описание сервиса и его методов
    function GetWebServiceDesc()
    {
        $sServerName = (defined("SITE_SERVER_NAME") && strLen(SITE_SERVER_NAME) > 0) ? SITE_SERVER_NAME : \COption::GetOptionString('main', 'server_name');

        $wsdesc = new \CWebServiceDesc();
        $wsdesc->wsname = "project.webservice.data_sales"; // название сервиса
        $wsdesc->wsclassname = __CLASS__; // название класса
        $wsdesc->wsdlauto = true;
        $wsdesc->wsendpoint = self::GetEndPoint(true);
        $wsdesc->wstargetns = $sServerName.'/ComissionSales';

        $wsdesc->classTypes = [];

        $wsdesc->structTypes = [];
        $wsdesc->structTypes["StatusRecord"] = [
            "OrderGUID"          => [
                "varType" => "string",
            ],
            "ProductGUID"        => [
                "varType" => "string",
            ],
            "CharacteristicGUID" => [
                "varType" => "string",
            ],
            "RecordDateTime"     => [
                "varType" => "string",
            ],
            "StatusGUID"         => [
                "varType" => "string",
            ],
            "CurrentStatusGUID"  => [
                "varType" => "string",
            ],
            "IsCanceled"         => [
                "varType" => "boolean",
                "strict"  => "no",
            ],
        ];
        $wsdesc->structTypes["ResponseRecord"] = [
            "OrderGUID"          => [
                "varType" => "string",
            ],
            "ProductGUID"        => [
                "varType" => "string",
            ],
            "CharacteristicGUID" => [
                "varType" => "string",
            ],
            "RecordDateTime"     => [
                "varType" => "string",
            ],
            "IsError"            => [
                "varType" => "boolean",
            ],
            "ErrorText"          => [
                "varType" => "string",
                "strict"  => "no",
            ],
        ];

        $wsdesc->structTypes["OrderRecord"] = [
            "OrderGUID"      => [
                "varType" => "string",
            ],
            "Version1C"      => [
                "varType" => "string",
            ],
            "OrderNumber"    => [
                "varType" => "string",
            ],
            "OrderDateTime"  => [
                "varType" => "string",
            ],
            "PartnerGUID"    => [
                "varType" => "string",
            ],
            "ContragentGUID" => [
                "varType" => "string",
            ],
            "AgreementGUID"  => [
                "varType" => "string",
            ],
            "GoodsList"      => [
                "varType" => "GoodsList",
                "arrType" => "GoodsLine",
                "strict"  => "no",
            ],
        ];
        $wsdesc->structTypes["GoodsLine"] = [
            "Good"           => [
                "varType" => "string",
            ],
            "Characteristic" => [
                "varType" => "string",
            ],
            "Name"           => [
                "varType" => "string",
            ],
            "Price"          => [
                "varType" => "integer",
            ],
            "StatusGUID"     => [
                "varType" => "string",
            ],
            "ParamsList"     => [
                "varType" => "ParamsList",
                "arrType" => "ParamLine",
                "strict"  => "no",
            ],
        ];
        $wsdesc->structTypes["ParamLine"] = [
            "Name"  => [
                "varType" => "string",
            ],
            "Value" => [
                "varType" => "string",
                "strict"  => "no",
            ],
        ];
        $wsdesc->structTypes["OrderResponseRecord"] = [
            "OrderGUID"     => [
                "varType" => "string",
            ],
            "OrderDateTime" => [
                "varType" => "string",
            ],
            "IsError"       => [
                "varType" => "boolean",
            ],
            "ErrorText"     => [
                "varType" => "string",
                "strict"  => "no",
            ],
        ];

        $wsdesc->structTypes["AgreementRecord"] = [
            "AgreementGUID"     => [
                "varType" => "string",
            ],
            "Number"            => [
                "varType" => "string",
            ],
            "AgreementDateTime" => [
                "varType" => "string",
            ],
        ];
        $wsdesc->structTypes["AgreementsResponseList"] = [
            "AgreementGUID" => [
                "varType" => "string",
            ],
            "IsError"       => [
                "varType" => "boolean",
            ],
            "ErrorText"     => [
                "varType" => "string",
                "strict"  => "no",
            ],
        ];

        $wsdesc->classes = [];
        $wsdesc->classes[__CLASS__] = [
            "SetComissionSalesStatus" => [
                "type"        => "public",
                "input"       => [
                    "StatusList" => [
                        "varType" => "StatusList",
                        "arrType" => "StatusRecord",
                    ],
                ],
                "output"      => [
                    "ResponseList" => [
                        "varType" => "ResponseList",
                        "arrType" => "ResponseRecord",
                        "strict"  => "no",
                    ],
                ],
                "httpauth"    => "Y",
                "description" => "",
            ],
            "SetComissionSalesOrders" => [
                "type"        => "public",
                "input"       => [
                    "AgreementsList" => [
                        "varType" => "AgreementsList",
                        "arrType" => "AgreementRecord",
                        "strict"  => "no",
                    ],
                    "OrdersList"     => [
                        "varType" => "OrdersList",
                        "arrType" => "OrderRecord",
                        "strict"  => "no",
                    ],
                ],
                "output"      => [
                    "AgreementsResponseList" => [
                        "varType" => "AgreementsResponseList",
                        "arrType" => "AgreementResponseRecord",
                        "strict"  => "no",
                    ],
                    "OrdersResponseList"     => [
                        "varType" => "OrdersResponseList",
                        "arrType" => "OrderResponseRecord",
                        "strict"  => "no",
                    ],
                ],
                "httpauth"    => "Y",
                "description" => "",
            ],
        ];

        return $wsdesc;
    }

    function SetComissionSalesStatus($arStatusesList)
    {

        \Project_log_array('$arStatusesList = '.var_export($arStatusesList, true), self::LOG_FILE, false);

        $arResult = [];

        $arOrdersGuids = [];
        $arProductGuids = [];
        $arStatusesGuids = [];

        foreach ($arStatusesList as $iStatus => $arStatus) {
            if (!empty($arStatus['OrderGUID'])) {
                $arOrdersGuids[$arStatus['OrderGUID']]++;
            }
            if (!empty($arStatus['ProductGUID'])) {
                $arProductGuids[$arStatus['ProductGUID']]++;
            }
            if (!empty($arStatus['StatusGUID'])) {
                $arStatusesGuids[$arStatus['StatusGUID']]++;
            }
            if (!empty($arStatus['CurrentStatusGUID'])) {
                $arStatusesGuids[$arStatus['CurrentStatusGUID']]++;
            }
        }

        $this->arOrders = $this->getExistsOrders(array_keys($arOrdersGuids));
        unset($arOrdersGuids);

        $this->arProducts = $this->getExistsProducts(array_keys($arProductGuids));
        unset($arProductGuids);

        $this->arStatuses = $this->getExistsStatuses(array_keys($arStatusesGuids));
        unset($arStatusesGuids);
        if (!is_array($this->arStatuses)) {
            return $this->arStatuses;
        }

        foreach ($arStatusesList as $iStatus => $arStatus) {

            $arErrors = $this->checkFields($arStatus);
            $this->updateStatus($arStatus, $arErrors);
            $this->updateHistory($arStatus, $arErrors);

            $arCurResult = [
                'OrderGUID'          => (string)$arStatus['OrderGUID'],
                'ProductGUID'        => (string)$arStatus['ProductGUID'],
                'CharacteristicGUID' => (string)$arStatus['CharacteristicGUID'],
                'RecordDateTime'     => (string)$arStatus['RecordDateTime'],
                'IsError'            => false,
                'ErrorText'          => '',
            ];
            if (!empty($arErrors)) {
                $arCurResult['IsError'] = true;
                $arCurResult['ErrorText'] = implode('; ', $arErrors);
            }
            $arResult[] = $arCurResult;
        }

        \Project_log_array('$arResult = '.var_export($arResult, true), self::LOG_FILE, false);

        return $arResult;
    }

    private static function checkEmptyGuidField($sGuid)
    {
        if (empty($sGuid) || $sGuid == '00000000-0000-0000-0000-000000000000') {
            return true;
        }
        return false;
    }

    private static function getExistsOrders($arOrdersGuids)
    {
        $arResult = [];
        if (!empty($arOrdersGuids)) {
            $obOrder = COrderTable::getList([
                'filter' => ['=EXTERNAL_ID' => $arOrdersGuids],
                'select' => ['ID', 'EXTERNAL_ID'],
            ]);
            while ($arOrder = $obOrder->fetch()) {
                $arResult[$arOrder['EXTERNAL_ID']] = $arOrder['ID'];
            }
        }
        return $arResult;
    }

    private static function getExistsProducts($arProductGuids)
    {
        $arResult = [];
        if (!empty($arProductGuids)) {
            $obProduct = CProductTable::getList([
                'filter' => ['=EXTERNAL_ID' => $arProductGuids],
                'select' => ['ID', 'EXTERNAL_ID', 'PRODUCT_EXTERNAL_ID', 'ORDER_ID', 'STATUS_PRODUCT_EXTERNAL_ID'],
            ]);
            while ($arProduct = $obProduct->fetch()) {
                $arResult[$arProduct['EXTERNAL_ID']] = $arProduct;
            }
        }
        return $arResult;
    }

    private static function getExistsStatuses($arStatusesGuids)
    {
        $arResult = [];
        if (!empty($arStatusesGuids)) {

            if (!Loader::includeModule('highloadblock')) {
                return new \CSOAPFault('Server Error', 'Cant include module HLBLOCK');
            }

            $arHLTData = HLT::getList([
                'filter' => [
                    'NAME' => self::STATUS_ENTITY_NAME,
                ],
            ])->fetch();
            if (empty($arHLTData)) {
                return new \CSOAPFault('Server Error', 'Не найден Highload-блок '.self::STATUS_ENTITY_NAME);
            }

            $obEntity = HLT::compileEntity($arHLTData);
            $sEntityDataClass = $obEntity->getDataClass();

            $arSelectParams = [
                'filter' => ['=UF_XML_ID' => $arStatusesGuids],
                'select' => ['ID', 'UF_XML_ID'],
            ];

            $obRowsDb = $sEntityDataClass::getList($arSelectParams);
            if ($obRowsDb->getSelectedRowsCount() != count($arStatusesGuids)) {
                // если статусы не найдены, пробуем обновить базу данных
                CDictionary::getFromErp(self::STATUS_ENTITY_NAME, true);
                $obRowsDb = $sEntityDataClass::getList($arSelectParams);
            }
            while ($arRowDb = $obRowsDb->fetch()) {
                $arResult[$arRowDb['UF_XML_ID']] = $arRowDb['ID'];
            }
        }
        return $arResult;
    }

    private function checkFields(&$arStatus)
    {
        $arErrors = [];

        if (empty($arStatus['OrderGUID'])) {
            $arErrors[] = 'Не передан GUID заказа';
        } elseif (empty($this->arOrders[$arStatus['OrderGUID']])) {
            $arErrors[] = 'Не найден заказ '.$arStatus['OrderGUID'];
        }

        if (empty($arStatus['ProductGUID'])) {
            $arErrors[] = 'Не передан GUID товара';
        } elseif (empty($this->arProducts[$arStatus['ProductGUID']])) {
            $arErrors[] = 'Не найден товар '.$arStatus['ProductGUID'];
        } elseif ($this->arProducts[$arStatus['ProductGUID']]['ORDER_ID'] != $this->arOrders[$arStatus['OrderGUID']]) {
            $arErrors[] = 'Не найден товар '.$arStatus['ProductGUID'].' в заказе '.$arStatus['OrderGUID'];
        }

        if (empty($arStatus['CharacteristicGUID'])) {
            $arErrors[] = 'Не передан GUID характеристики';
        } elseif (
            !empty($this->arProducts[$arStatus['ProductGUID']])
            &&
            $arStatus['CharacteristicGUID'] != $this->arProducts[$arStatus['ProductGUID']]['PRODUCT_EXTERNAL_ID']
            &&
            !$this->checkEmptyGuidField($this->arProducts[$arStatus['ProductGUID']]['PRODUCT_EXTERNAL_ID'])
        ) {
            $arErrors[] = 'Не найден товар '.$arStatus['ProductGUID'].' с характеристикой '.$arStatus['CharacteristicGUID'];
        }

        if (empty($arStatus['RecordDateTime'])) {
            $arErrors[] = 'Не переданы дата и время записи';
        }

        if (empty($arStatus['StatusGUID'])) {
            $arErrors[] = 'Не передан GUID статуса';
        } elseif (empty($this->arStatuses[$arStatus['StatusGUID']])) {
            $arErrors[] = 'Не найден статус '.$arStatus['StatusGUID'];
        }

        if (empty($arStatus['CurrentStatusGUID'])) {
            $arErrors[] = 'Не передан GUID текущего статуса';
        } elseif (empty($this->arStatuses[$arStatus['CurrentStatusGUID']])) {
            $arErrors[] = 'Не найден статус '.$arStatus['CurrentStatusGUID'];
        }

        return $arErrors;
    }

    private function updateStatus(&$arStatus, &$arErrors)
    {
        if (!empty($arErrors)) {
            return;
        }
        $arCurProduct = &$this->arProducts[$arStatus['ProductGUID']];
        $arProductUpdate = [];
        if ($arStatus['CharacteristicGUID'] != $arCurProduct['PRODUCT_EXTERNAL_ID'] && $this->checkEmptyGuidField($arCurProduct['PRODUCT_EXTERNAL_ID'])) {
            $arProductUpdate['PRODUCT_EXTERNAL_ID'] = $arStatus['CharacteristicGUID'];
        }
        if ($arStatus['CurrentStatusGUID'] != $arCurProduct['STATUS_PRODUCT_EXTERNAL_ID']) {
            $arProductUpdate['STATUS_PRODUCT_EXTERNAL_ID'] = $arStatus['CurrentStatusGUID'];
        }
        if (!empty($arProductUpdate)) {
            $obResult = CProductTable::update($arCurProduct['ID'], $arProductUpdate);
            if ($obResult->isSuccess()) {
                foreach ($arProductUpdate as $sKey => $sValue) {
                    $arCurProduct[$sKey] = $sValue;
                }
            } else {
                $arErrors[] = 'Ошибка обновления статуса комиссионного товара: '.implode(', ', $obResult->getErrorMessages());
            }
        }
    }

    private function updateHistory(&$arStatus, &$arErrors)
    {
        if (!empty($arErrors)) {
            return;
        }
        $arCurProduct = &$this->arProducts[$arStatus['ProductGUID']];
        $arHistoryFields = [
            'PRODUCT_ID'            => $arCurProduct['ID'],
            'PRODUCT_STATUS_XML_ID' => $arStatus['StatusGUID'],
            'DATETIME'              => DateTime::createFromTimestamp(\strtotime($arStatus['RecordDateTime'])),
        ];
        $arFilter = [];
        foreach ($arHistoryFields as $sKey => $sValue) {
            $arFilter['='.$sKey] = $sValue;
        }
        $arRow = CHistoryTable::getRow([
            'filter' => $arFilter,
            'select' => ['ID'],
        ]);
        if ($arStatus['IsCanceled'] == false) {
            if (empty($arRow)) {
                $obResult = CHistoryTable::add($arHistoryFields);
                if (!$obResult->isSuccess()) {
                    $arErrors[] = 'Ошибка добавления статуса в историю: '.implode(', ', $obResult->getErrorMessages());
                }
            }
        } else {
            if (!empty($arRow)) {
                $obResult = CHistoryTable::delete($arRow['ID']);
                if (!$obResult->isSuccess()) {
                    $arErrors[] = 'Ошибка удаления статуса из истории: '.implode(', ', $obResult->getErrorMessages());
                }
            }
        }
    }

    function SetComissionSalesOrders($arAgreementsList, $arOrdersList)
    {

        \Project_log_array('$arAgreementsList = '.var_export($arAgreementsList, true), self::LOG_FILE, false);

        \Project_log_array('$arOrdersList = '.var_export($arOrdersList, true), self::LOG_FILE, false);

        $arResult = [];
        $arResult['AgreementsList'] = [];
        $arResult['OrdersList'] = [];

        foreach ($arAgreementsList as $iAgreement => $arAgreement) {

            $arCurResult = [
                'OrderGUID' => (string)$arAgreement['AgreementGUID'],
                'IsError'   => false,
                'ErrorText' => '',
            ];
            $arResult['AgreementsList'][] = $arCurResult;
        }

        foreach ($arOrdersList as $iOrder => $arOrder) {

            $arCurResult = [
                'AgreementGUID' => (string)$arOrder['OrderGUID'],
                'OrderDateTime' => (string)$arOrder['OrderDateTime'],
                'IsError'       => false,
                'ErrorText'     => '',
            ];
            $arResult['OrdersList'][] = $arCurResult;
        }

        \Project_log_array('$arResult = '.var_export($arResult, true), self::LOG_FILE, false);

        return $arResult;
    }
}
