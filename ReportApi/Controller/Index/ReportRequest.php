<?php
/**
 * @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

namespace InformaticsCommerce\ReportApi\Controller\Index;

use Magento\Customer\Model\Session;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\View\Result\PageFactory;
use Magento\Framework\App\Request\DataPersistorInterface;

class ReportRequest extends Action
{
    /**
     * @var PageFactory|PageFactory
     */
    protected $_resultPageFactory;

    /**
     * Customer session
     *
     * @var Session
     */
    protected $_customerSession;

    /**
     * @var RequestInterface
     */
    protected $request;

    /**
     * @var DataPersistorInterface
     */
    protected $dataPersistor;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    protected $logger;

    /**
     * @var \InformaticsCommerce\ReportApi\Helper\Data
     */
    protected $helperReportApi;

    public function __construct(
        Context $context,
        PageFactory $resultPageFactory,
        Session $customerSession,
        RequestInterface $request,
        \Psr\Log\LoggerInterface $logger,
        DataPersistorInterface $dataPersistor,
        \InformaticsCommerce\ReportApi\Helper\Data $helperReportApi
    )
    {
        $this->_resultPageFactory = $resultPageFactory;
        $this->_customerSession = $customerSession;
        $this->request = $request;
        $this->logger = $logger;
        $this->dataPersistor = $dataPersistor;
        $this->helperReportApi = $helperReportApi;

        parent::__construct($context);
    }

    public function execute()
    {
        $this->dataPersistor->clear('report_filename');
        $params = $this->request->getParams();

        if (empty($params) || (empty($params['sales_order_number']) && empty($params['customer_po_number']))) {
            $this->messageManager->addErrorMessage(
                __('Required input paramaters are missing.')
            );

            $this->dataPersistor->set('report_request', $params);
            return $this->resultRedirectFactory->create()->setPath('report_api');
        }

        try {
            $result = $this->helperReportApi->getRealTimeSalesOrderReport($params['sales_order_number'], $params['customer_po_number']);
            if ($result) {
                $this->messageManager->addSuccessMessage(
                    __('Succussfully generated a sales report.')
                );
                $this->dataPersistor->clear('report_request');
                $this->dataPersistor->set('report_filename', $result);
            } else {
                $this->messageManager->addErrorMessage(
                    __('Unable to get a sales report. Please try again')
                );
            }

        } catch (\Exception $e) {
            $this->logger->critical($e);
            $this->messageManager->addErrorMessage(
                __('An error occurred while processing your form. Please try again later. ' . $e->getMessage())
            );
            $this->dataPersistor->set('report_request', $params);
        }

        return $this->resultRedirectFactory->create()->setPath('report_api');
    }
}
