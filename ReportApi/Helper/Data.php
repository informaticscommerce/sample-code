<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace InformaticsCommerce\ReportApi\Helper;

use Exception;
use Magento\Contact\Model\ConfigInterface;
use Magento\Customer\Helper\View as CustomerViewHelper;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Framework\HTTP\Client\CurlFactory;
use Nov\Catalog\Helper\Data as CatalogHelper;
use Psr\Log\LoggerInterface as Logger;
use Magento\Store\Model\ScopeInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Framework\App\Response\Http\FileFactory;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Store\Model\StoreManagerInterface;
use SimpleXMLElement;

/**
 * ReportApi base helper
 *
 */
class Data extends \Magento\Framework\App\Helper\AbstractHelper
{
    /**
     * Sales Order Report configuration constant
     */
    const API_MODE_TEST = 'test';
    const SALES_REQUEST_IDENTIFIER = 'Sales';
    const XML_PATH_SALES_DEBUG_REQUEST_XML = 'omega_api_setting/sales/request_xml';
    const XML_PATH_SALES_MODE = 'omega_api_setting/sales/mode';
    const XML_PATH_SALES_TEST_ENDPOINT = 'omega_api_setting/sales/test_endpoint';
    const XML_PATH_SALES_PROD_ENDPOINT = 'omega_api_setting/sales/prod_endpoint';
    const XML_PATH_SALES_DEBUG_MODE = 'omega_api_setting/sales/debug';
    const REPORT_HEADER_FIELDS = ['ORDER_NUMBER', 'CUSTOMER_PO_NUMBER', 'CUSTOMER', 'CONTACT', 'ADDRESS', 'PHONE_NUMBER', 'EMAIL_ADDRESS'];

    /**
     * Customer session
     *
     * @var \Magento\Customer\Model\Session
     */
    protected $customerSession;

    /**
     * @var \Magento\Customer\Helper\View
     */
    protected $customerViewHelper;

    /**
     * @var DataPersistorInterface
     */
    protected $dataPersistor;

    /**
     * @var array
     */
    protected $postData = null;

    /**
     * @var CatalogHelper
     */
    protected $catalogHelper;

    /**
     * @var CurlFactory
     */
    protected $curlFactory;

    /**
     * @var Logger
     */
    protected $logger;

    protected $directoryList;
    protected $filesystem;
    protected $dateTime;
    protected $fileFactory;
    protected $storeManager;
    protected $fileName;

    /**
     * @param \Magento\Framework\App\Helper\Context $context
     * @param \Magento\Customer\Model\Session $customerSession
     * @param CustomerViewHelper $customerViewHelper
     */
    public function __construct(
        \Magento\Framework\App\Helper\Context $context,
        \Magento\Customer\Model\Session       $customerSession,
        CustomerViewHelper                    $customerViewHelper,
        ConfigInterface                       $configInterface,
        CatalogHelper                         $catalogHelper,
        CurlFactory                           $curlFactory,
        Logger                                $logger,
        DirectoryList                         $directoryList,
        \Magento\Framework\Filesystem         $filesystem,
        DateTime                              $dateTime,
        FileFactory                           $fileFactory,
        StoreManagerInterface                 $storeManager
    )
    {
        $this->customerSession = $customerSession;
        $this->customerViewHelper = $customerViewHelper;
        $this->configInterface = $configInterface;
        $this->catalogHelper = $catalogHelper;
        $this->curlFactory = $curlFactory;
        $this->logger = $logger;
        $this->filesystem = $filesystem;
        $this->directoryList = $directoryList;
        $this->dateTime = $dateTime;
        $this->fileFactory = $fileFactory;
        $this->storeManager = $storeManager;

        parent::__construct($context);
    }

    /**
     * Get value from POST by key
     *
     * @param string $key
     * @return string
     */
    public function getPostValue($key)
    {
        if (null === $this->postData) {
            $this->postData = (array)$this->getDataPersistor()->get('report_request');
            $this->getDataPersistor()->clear('report_request');
        }

        if (isset($this->postData[$key])) {
            return (string)$this->postData[$key];
        }

        return '';
    }

    /**
     * Get value from POST by key
     *
     * @param string $key
     * @return string
     */
    public function getFileName()
    {
        if (null === $this->postData) {
            $this->postData = (array)$this->getDataPersistor()->get('report_filename');
            $this->getDataPersistor()->clear('report_filename');
        }

        if (isset($this->postData[0])) {
            return (string)$this->postData[0];
        }

        return '';
    }

    /**
     * Get Data Persistor
     *
     * @return DataPersistorInterface
     */
    private function getDataPersistor()
    {
        if ($this->dataPersistor === null) {
            $this->dataPersistor = ObjectManager::getInstance()
                ->get(DataPersistorInterface::class);
        }

        return $this->dataPersistor;
    }

    /**
     * Prepare xml, send and get resposne via an API
     *
     * @return string|bool
     */
    public function getRealTimeSalesOrderReport($salesOrderNumber, $customerPoNumber)
    {
        $currentDataTime = $this->dateTime->date('Y-m-d_H-i-s');
        try {
            $token = $this->catalogHelper->getToken();
            $endPoint = $this->getSalesEndPointURL();

            if ($endPoint) {
                $requestXml = $this->getRequestXmlSalesOrderReport();
                $requestXmlWithParams = sprintf($requestXml, $salesOrderNumber, $customerPoNumber);

                $curl = $this->curlFactory->create();
                $headers = [
                    "Accept-Encoding: gzip,deflate",
                    "Content-Type: application/soap+xml",
                    "Authorization: " . $token
                ];

                $curl_params = [
                    CURLOPT_TIMEOUT => 300,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_MAXREDIRS => 10,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                    CURLOPT_URL => $endPoint,
                    CURLOPT_CUSTOMREQUEST => 'POST',
                    CURLOPT_ENCODING => '',
                    CURLOPT_HTTPHEADER => $headers,
                    CURLOPT_POSTFIELDS => $requestXmlWithParams
                ];

                $curl->setOptions($curl_params);
                $curl->get($endPoint);
                $bodyResponse = $curl->getBody();

                //Log request and response in logger table
                if ($this->isSalesDebugEnable()) {
                    $this->catalogHelper->logRequest(
                        self::SALES_REQUEST_IDENTIFIER,
                        $requestXmlWithParams,
                        $bodyResponse
                    );
                }

                if (!empty($bodyResponse)) {
                    $responseEncoded = $this->getResponseEncoded($bodyResponse);
                    $bodyResponse = $this->urlDecoder->decode($responseEncoded);
                    if (empty($bodyResponse)) {
                        return false;
                    }

                    $resultSaveResponseInXmlFormat = $this->saveResponseInXmlFormat($bodyResponse, $currentDataTime);
                    if (!$resultSaveResponseInXmlFormat) {
                        return false;
                    }

                    $convertedCsvString = $this->convertXmlToCsvString($bodyResponse, 'G_1');
                    if (empty($convertedCsvString)) {
                        return false;
                    }

                    $resultSaveStringInCsvFile = $this->saveStringInCsvFile($convertedCsvString, $currentDataTime);
                    if (empty($resultSaveStringInCsvFile)) {
                        return false;
                    }

                    return $resultSaveStringInCsvFile;
                }

                return false;
            }

        } catch (Exception $e) {
            $this->logger->critical($e->getMessage());
        }

        return false;
    }

    /**
     * Method to get the legacy item number request xml
     *
     * @return string
     */
    public function getRequestXmlSalesOrderReport(): string
    {
        return (string)$this->scopeConfig->getValue(
            self::XML_PATH_SALES_DEBUG_REQUEST_XML,
            ScopeInterface::SCOPE_WEBSITE
        );
    }

    /**
     * Get Response encoded
     * @param $string
     * @param string $start
     * @param string $end
     * @return mixed
     */
    private function getResponseEncoded($string, $start = '<ns2:reportBytes>', $end = '</ns2:reportBytes>')
    {
        if (strpos($string, $start) !== false) {
            $startCharCount = strpos($string, $start) + strlen($start);
            $firstSubStr = substr($string, $startCharCount, strlen($string));
            $endCharCount = strpos($firstSubStr, $end);
            if ($endCharCount == 0) {
                $endCharCount = strlen($firstSubStr);
            }
            return substr($firstSubStr, 0, $endCharCount);
        }

        return '';
    }

    /**
     * Get endpoint url
     *
     * @return string
     */
    public function getSalesEndPointURL(): string
    {
        $mode = $this->scopeConfig->getValue(self::XML_PATH_SALES_MODE);
        if ($mode == self::API_MODE_TEST) {
            return (string)$this->scopeConfig->getValue(self::XML_PATH_SALES_TEST_ENDPOINT);
        }
        return (string)$this->scopeConfig->getValue(self::XML_PATH_SALES_PROD_ENDPOINT);
    }

    /**
     * Check whether debug mode is active/inactive
     *
     * @return bool
     */
    public function isSalesDebugEnable(): bool
    {
        $debugEnabled = $this->scopeConfig->getValue(
            self::XML_PATH_SALES_DEBUG_MODE,
            ScopeInterface::SCOPE_WEBSITE
        );
        if ($debugEnabled) {
            return true;
        }
        return false;
    }

    public function getMediaUrl()
    {
        return $this->storeManager->getStore()->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_MEDIA);
    }

    public function getCustomer()
    {
        return $this->customerSession;
    }

    public function getDirectoryList()
    {
        return $this->directoryList;
    }

    function convertXmlToCsvString($xmlString, $startHeader = null)
    {
        $xml = new \SimpleXMLElement($xmlString);
        $header = false;
        $csv = '';
        foreach ($xml as $key => $value) {

            if ($startHeader && $key != $startHeader) {
                continue;
            }

            $valueObjectVars = get_object_vars($value);

            if (!$header) {
                $csv .= "Sales Order Status";
                $csv .= $this->addNextLineInCsv();
            }

            $commaFreeValues = [];
            $address = '';
            foreach ($valueObjectVars as $key => $value) {
                $trimKey = str_replace(',', '', trim($key));
                $trimValue = str_replace(',', '', trim($value));

                if (in_array($trimKey, self::REPORT_HEADER_FIELDS) && !$header) {
                    if ($trimKey === 'ADDRESS') {
                        $address = " ADDRESS :," . $trimValue . ",";
                        continue;
                    }
                    if ($trimKey === 'CUSTOMER_PO_NUMBER') {
                        $csv .= $this->addNextLineInCsv();
                    }
                    if (empty($trimValue)) {
                        $trimValue = '-';
                    }
                    $csv .= str_replace('_', ' ', $trimKey) . " :," . $trimValue . ",";
                    if ($trimKey === 'CUSTOMER_PO_NUMBER') {
                        $csv .= $address;
                    }
                }
                if ($trimKey === 'CUSTOMER' || $trimKey === 'ADDRESS') {
                    continue;
                } else {
                    $commaFreeValues[$trimKey] = $trimValue;
                }

            }
            if (!$header) {
                $csv .= $this->addNextLineInCsv();
                $csv .= $this->addNextLineInCsv();
                unset($valueObjectVars["CUSTOMER"]);
                unset($valueObjectVars["ADDRESS"]);
                $csv .= implode(array_keys($valueObjectVars), ',');
                $header = true;
            }
            $csv .= $this->addNextLineInCsv();

            $csv .= implode($commaFreeValues, ',');
        }
        return $csv;
    }

    public function addNextLineInCsv()
    {
        return str_replace('<br />', '', nl2br("\n"));
    }

    /**
     * Save response in XML format
     * create directory if not exists
     *
     * @return bool | string
     */
    public function saveResponseInXmlFormat($response, $currentDataTime)
    {
        try {
            if (!$this->resolveMediaSalesReportsDirectory()) {
                return false;
            }

            $media = $this->filesystem->getDirectoryWrite(DirectoryList::MEDIA);
            $filename = $this->getFilenameInDateTimeFormat('xml', $currentDataTime);

            $domDocument = new \DOMDocument('1.0', 'UTF-8');
            $domDocument->loadXML($response);
            $domDocument->save($media->getAbsolutePath() . $this->getMediaSalesReportPath() . $filename);

            return $filename;

        } catch (Exception $e) {
            $this->logger->critical($e->getMessage());
            exit;
        }

        return false;
    }

    /**
     * Save string in CSV file
     * create directory if not exists
     *
     * @return bool | string
     */
    public function saveStringInCsvFile($csvString, $currentDataTime)
    {
        try {
            if (!$this->resolveMediaSalesReportsDirectory()) {
                return false;
            }
            $mediaSalesReportPath = $this->getMediaSalesReportPath();
            $filename = $this->getFilenameInDateTimeFormat('csv', $currentDataTime);

            $media = $this->filesystem->getDirectoryWrite(DirectoryList::MEDIA);
            $media->writeFile($media->getAbsolutePath() . $mediaSalesReportPath . $filename, $csvString);
            return $filename;

        } catch (Exception $e) {
            $this->logger->critical($e->getMessage());
        }

        return false;
    }

    public function resolveMediaSalesReportsDirectory()
    {
        try {
            $fileDirectoryPath = $this->directoryList->getPath(DirectoryList::MEDIA) . '/sales_reports/' . $this->customerSession->getId();

            if (!is_dir($fileDirectoryPath)) {
                mkdir($fileDirectoryPath, 0777, true);
            }

            return true;

        } catch (Exception $e) {
            $this->logger->critical($e->getMessage());
        }

        return false;
    }

    public function getMediaSalesReportPath()
    {
        return 'sales_reports/' . $this->customerSession->getId() . '/';
    }

    public function getFilenameInDateTimeFormat($ext, $currentDataTime)
    {
        $this->fileName = sprintf('sales_%s.%s', $currentDataTime, $ext);
        return $this->fileName;
    }
}

