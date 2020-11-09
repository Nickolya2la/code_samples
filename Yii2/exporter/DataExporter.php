<?php
namespace app\components\exporter;

use League\Csv\CharsetConverter;
use \yii\base\Component;
use \SimpleXMLElement;
use yii\base\Exception;
use League\Csv\Writer;

/**
 * Class DataExporter
 * @package app\components\exporter
 */
class DataExporter extends Component
{
    const EXPORT_FORMAT_XML = 'xml';
    const EXPORT_FORMAT_JSON = 'json';
    const EXPORT_FORMAT_CSV = 'csv';

    /**
     * Return supported export data formats
     * @return array
     */
    public static function getFormats()
    {
        return [
            self::EXPORT_FORMAT_XML => 'XML',
            self::EXPORT_FORMAT_JSON => 'JSON',
            self::EXPORT_FORMAT_CSV => 'CSV',
        ];
    }

    /**
     * Convert array to csv
     * @param array $array
     * @param $itemFields array List of item columns (fields)
     * @return string
     */
    static function _array2csv(array &$array, $itemFields)
    {
        $writer = Writer::createFromString('');
        $writer->setOutputBOM(Writer::BOM_UTF16_LE);
        $writer->setDelimiter("\t");

        CharsetConverter::addTo($writer, 'UTF-8', 'UTF-16LE');

        $writer->insertOne($itemFields);
        $writer->insertAll($array);

        $content = $writer->getContent();

        return $content;
    }

    /**
     * Convert array to csv
     * @param array $array
     * @param $itemFields array List of item columns (fields)
     * @return string
     */
    static function array2csv(array &$array, $itemFields)
    {
        ob_start();
        $df = fopen("php://output", 'w');
        fprintf($df, chr(0xEF).chr(0xBB).chr(0xBF));
        fputcsv($df, $itemFields);
        foreach ($array as $row) {
            fputcsv($df, $row);
        }
        fclose($df);
        return ob_get_clean();
    }

    /**
     * Convert array to json
     * @param array $array
     * @param integer $options
     * @return string
     */
    static function array2json(array $array, $options = 0)
    {
        return json_encode($array, $options);
    }

    /**
     * Convert array to xml
     * @param array $array
     * @param $itemLabel string
     * @return mixed
     */
    function array2xml(array $array, $itemLabel)
    {
        $node = new SimpleXMLElement('<root/>');

        if (empty($array)) {
            return $node->asXML();
        }

        foreach ($array as $item) {

            if (!is_array($item)) {
                continue;
            }

            $itemNode = $node->addChild($itemLabel);

            foreach ($item as $field => $value) {
                $itemNode->addChild($field, $value);
            }
        }

        return $node->asXML();
    }

    /**
     * Export passed array to file in one of allowed $format
     * @param array $array
     * @param $pathToFile string
     * @param $format string
     * @param $itemLabel string label for exported items elements
     * @param $itemFields array List of item columns (fields)
     * @param $overwrite bool Overwrite file if exist
     * @return bool
     * @throws Exception
     */
    public function export(array $array, $format, $itemLabel = 'item', $itemFields, $overwrite = false)
    {
        if (!in_array($format, [
            static::EXPORT_FORMAT_CSV,
            static::EXPORT_FORMAT_JSON,
            static::EXPORT_FORMAT_XML
        ]))
        {
            throw new Exception('Unknown output format!');
        }

        $fileContent = '';


        switch ($format) {
            case self::EXPORT_FORMAT_CSV:
                $fileContent = static::array2csv($array, $itemFields);
            break;

            case self::EXPORT_FORMAT_XML:
                $fileContent = static::array2xml($array, $itemLabel);
            break;

            case self::EXPORT_FORMAT_JSON:
                $fileContent = static::array2json($array);
            break;
        }


        return $fileContent;
    }
}