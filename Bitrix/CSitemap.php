<?php
namespace Project\Tools;

use \Bitrix\Main\Application;
use \Bitrix\Main\Loader;
use \Bitrix\Main\IO\Directory;
use \Bitrix\Main\IO\File;
use \Bitrix\Main\Config\Option;
use \Project\Settings\CConstants;

class CSitemap
{
    // статические инфоблоки: массив тип инфоблока, код инфоблока
    private $arIBlocksStat = [
        ['blog', 'blog'],
    ];

    // статические страницы из следующих меню:
    private $arMenuStat = [
        '/.top_additional.menu.php',
    ];

    // ограничение на количество товаров в файле
    const GOODS_LIMIT = 5000;

    // директория для сохранения файлов
    const ROOT_DIR = '/upload/sitemap/';

    // имена файлов
    const MAIN_SITEMAP_NAME = 'sitemap.xml';
    const STATIC_SITEMAP_NAME = 'sitemap_stat.xml';
    const CATEGORY_SITEMAP_NAME = 'sitemap_cat.xml';
    const GOODS_SITEMAP_NAME = 'sitemap_goods_#NUM#.xml';  // #NUM# заменится номером файла, исходя из ограничения

    // частота обновления для поля changefreq
    const CHANGE_FREQ = 'daily';

    // суффикс для временных файлов
    const TEMP_FILE_POSTFIX = '.tmp';

    // формат даты для карты сайта
    const DATE_FORMAT = 'Y-m-d';

    private $arNewFiles = [];
    private $arSitemapIndex = [];
    private $iGoodsFile = 1;


    function __construct()
    {
        Loader::includeModule("iblock");

        $sDocumentRoot = Application::getDocumentRoot();

        $this->sRootDirDocument = $sDocumentRoot.self::ROOT_DIR;
        Directory::createDirectory($this->sRootDirDocument);

        $this->arCurFiles = glob($this->sRootDirDocument.'*.xml');
        $this->arCurFiles = array_flip($this->arCurFiles);

        $this->iProductIblockId = CConstants::getIBlockIdValue('catalog', 'catalog_1c');

        $this->sCurDate = date(self::DATE_FORMAT);

        // базовая часть ссылки для адресов в карте сайта
        $this->sBaseLink = 'https://' . Option::get('main', 'server_name');

    }

    private function createTempFile($sFileWay)
    {
        $sFileTempWay = $sFileWay.self::TEMP_FILE_POSTFIX;
        $this->arNewFiles[$sFileTempWay] = $sFileWay;
        if (File::isFileExists($sFileTempWay)) {
            $bResult = File::deleteFile($sFileTempWay);
            if ($bResult === false) {
                return false;
            }
        }
        $obWriter = new \XMLWriter();
        $obWriter->openURI($sFileTempWay);
        $obWriter->setIndent(true);
        $obWriter->setIndentString('    ');
        $obWriter->startDocument('1.0', 'UTF-8');
        return $obWriter;
    }

    private function openUrlSet($obWriter, $isIndex = false)
    {
        $obWriter->startElement($isIndex ? 'sitemapindex' : 'urlset');
        $obWriter->startAttribute('xmlns');
        $obWriter->text('http://www.sitemaps.org/schemas/sitemap/0.9');
    }

    private function closeElementAndFile($obWriter)
    {
        $obWriter->endElement();
        $obWriter->flush(true);
    }

    private function writeElement($obWriter, $arElement, $isIndex = false)
    {
        $obWriter->startElement($isIndex ? 'sitemap' : 'url');
        foreach($arElement as $sKey => $sValue) {
            $obWriter->startElement($sKey);
            $obWriter->text($sValue);
            $obWriter->endElement();
        }
        $obWriter->endElement();
    }

    private function writeFilesFromTemp()
    {
        foreach($this->arNewFiles as $sTmpFile => $sOriginalWay) {
            if (File::isFileExists($sOriginalWay)) {
                $bResult = File::deleteFile($sOriginalWay);
                if ($bResult === false) {
                    return false;
                }
            }
            $bResult = rename($sTmpFile, $sOriginalWay);
            if ($bResult === false) {
                return false;
            }
            if (isset($this->arCurFiles[$sOriginalWay])) {
                unset($this->arCurFiles[$sOriginalWay]);
            }
        }
        foreach($this->arCurFiles as $sWay => $iIndex) {
            $bResult = File::deleteFile($sWay);
            if ($bResult === false) {
                return false;
            }
        }

        return true;
    }

    private function statXmlFileUpdate()
    {
        $obWriter = $this->createTempFile($this->sRootDirDocument.self::STATIC_SITEMAP_NAME);
        if (!$obWriter) {
            return false;
        }
        $this->arSitemapIndex[] = self::ROOT_DIR.self::STATIC_SITEMAP_NAME;
        $this->openUrlSet($obWriter);
        $obIO = \CBXVirtualIo::GetInstance();
        foreach ($this->arMenuStat as $sMenuWay) {
            $sWay = $obIO->GetPhysicalName($obIO->CombinePath(Application::getDocumentRoot(), '/', $sMenuWay)); // от корня берем и тип меню
            if (file_exists($sWay)) {
                $aMenuLinks = [];
                include($sWay);
                foreach ($aMenuLinks as $arLink) {
                    $arElement = [
                        'loc'        => $this->sBaseLink . $arLink[1],
                        'lastmod'    => $this->sCurDate,
                        'changefreq' => self::CHANGE_FREQ,
                        'priority'   => '0.5',
                    ];
                    $this->writeElement($obWriter, $arElement);
                }
            }
        }
        $arIBlocks = [];
        foreach ($this->arIBlocksStat as $arIBlock) {
            $arIBlocks[] = $arIBlock['iIBlockId'];
        }
        $arIBlocks = array_unique($arIBlocks);
        if (count($arIBlocks) > 0) {
            $obElements = \CIBlockElement::GetList(
                ["ID" => "ASC"],
                ['IBLOCK_ID' => $arIBlocks, 'ACTIVE' => 'Y'],
                false,
                false,
                ['ID', 'CODE', 'DETAIL_PAGE_URL', 'TIMESTAMP_X']
            );
            while ($arBaseElement = $obElements->GetNext()) {
                $arElement = [
                    'loc'        => $this->sBaseLink . $arBaseElement['DETAIL_PAGE_URL'],
                    'lastmod'    => date(self::DATE_FORMAT, strtotime($arBaseElement['TIMESTAMP_X'])),
                    'changefreq' => self::CHANGE_FREQ,
                    'priority'   => '0.5',
                ];
                $this->writeElement($obWriter, $arElement);
            }
        }
        $this->closeElementAndFile($obWriter);

        return true;
    }

    private function catXmlFileUpdate()
    {
        $obWriter = $this->createTempFile($this->sRootDirDocument.self::CATEGORY_SITEMAP_NAME);
        if (!$obWriter) {
            return false;
        }
        $this->arSitemapIndex[] = self::ROOT_DIR.self::CATEGORY_SITEMAP_NAME;
        $this->openUrlSet($obWriter);

        $arElement = [
            'loc'        => $this->sBaseLink,
            'lastmod'    => $this->sCurDate,
            'changefreq' => self::CHANGE_FREQ,
            'priority'   => '0.9',
        ];
        $this->writeElement($obWriter, $arElement);

        $obSections = \CIBlockSection::GetList(
            ["ID" => "ASC"],
            ['IBLOCK_ID' => $this->iProductIblockId, 'ACTIVE' => 'Y'],
            false,
            ['ID', 'SECTION_PAGE_URL', 'TIMESTAMP_X'],
            false
        );
        while ($arSection = $obSections->GetNext()) {
            $arElement = [
                'loc'        => $this->sBaseLink . $arSection['SECTION_PAGE_URL'],
                'lastmod'    => date(self::DATE_FORMAT, strtotime($arSection['TIMESTAMP_X'])),
                'changefreq' => self::CHANGE_FREQ,
                'priority'   => '0.8',
            ];
            $this->writeElement($obWriter, $arElement);
        }
        $this->closeElementAndFile($obWriter);

        return true;
    }

    private function goodsXmlFileUpdate()
    {
        $iCurCount = 0;
        $sCurFileName = str_replace('#NUM#', $this->iGoodsFile, self::GOODS_SITEMAP_NAME);
        $obWriter = $this->createTempFile($this->sRootDirDocument.$sCurFileName);
        if (!$obWriter) {
            return false;
        }
        $this->arSitemapIndex[] = self::ROOT_DIR.$sCurFileName;
        $this->openUrlSet($obWriter);
        $obElements = \CIBlockElement::GetList(
            ["ID" => "ASC"],
            ['IBLOCK_ID' => $this->iProductIblockId, 'ACTIVE' => 'Y'],
            false,
            false,
            ['ID', 'CODE', 'DETAIL_PAGE_URL', 'TIMESTAMP_X']
        );
        while ($arBaseElement = $obElements->GetNext()) {
            if ($iCurCount >= self::GOODS_LIMIT) {
                $this->iGoodsFile++;
                $iCurCount = 0;
                $this->closeElementAndFile($obWriter);
                $sCurFileName = str_replace('#NUM#', $this->iGoodsFile, self::GOODS_SITEMAP_NAME);
                $obWriter = $this->createTempFile($this->sRootDirDocument.$sCurFileName);
                if (!$obWriter) {
                    return false;
                }
                $this->arSitemapIndex[] = self::ROOT_DIR.$sCurFileName;
                $this->openUrlSet($obWriter);
            }
            $arElement = [
                'loc'        => $this->sBaseLink . $arBaseElement['DETAIL_PAGE_URL'],
                'lastmod'    => date(self::DATE_FORMAT, strtotime($arBaseElement['TIMESTAMP_X'])),
                'changefreq' => self::CHANGE_FREQ,
                'priority'   => '0.6',
            ];
            $this->writeElement($obWriter, $arElement);
            $iCurCount++;
        }
        $this->closeElementAndFile($obWriter);

        return true;
    }


    private function createIndexSitemap()
    {
        $obWriter = $this->createTempFile($this->sRootDirDocument.self::MAIN_SITEMAP_NAME);
        if (!$obWriter) {
            return false;
        }
        $this->openUrlSet($obWriter, true);
        foreach($this->arSitemapIndex as $sFileWay) {
            $arElement = [
                'loc'        => $this->sBaseLink . $sFileWay,
                'lastmod'    => $this->sCurDate,
            ];
            $this->writeElement($obWriter, $arElement, true);
        }
        $this->closeElementAndFile($obWriter);

        return true;
    }

    public static function sitemapGeneration()
    {
        $obSitemap = new self();
        $obSitemap->statXmlFileUpdate();
        $obSitemap->catXmlFileUpdate();
        $obSitemap->goodsXmlFileUpdate();
        $obSitemap->createIndexSitemap();
        $obSitemap->writeFilesFromTemp();

        return true;
    }

    public static function sitemapGenerationShellAgent()
    {
        self::sitemapGeneration();

        return '\\'.__METHOD__.'();';
    }


}