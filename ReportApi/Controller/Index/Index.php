<?php
/**
 * @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */
namespace InformaticsCommerce\ReportApi\Controller\Index;

use Magento\Customer\Model\Session;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\View\Result\PageFactory;
use Magento\Framework\App\Request\DataPersistorInterface;

class Index extends Action
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
     * @var DataPersistorInterface
     */
    protected $dataPersistor;

    public function __construct(
        Context $context,
        PageFactory $resultPageFactory,
        Session $customerSession,
        DataPersistorInterface $dataPersistor
    )
    {
        $this->_resultPageFactory = $resultPageFactory;
        $this->_customerSession = $customerSession;
        $this->dataPersistor = $dataPersistor;

        parent::__construct($context);
    }

    /**
     * Check customer authentication for some actions
     *
     * @param RequestInterface $request
     * @return ResponseInterface
     */
    public function dispatch(RequestInterface $request)
    {
        if (!$this->_customerSession->authenticate()) {
            $this->_actionFlag->set('', 'no-dispatch', true);
        }
        return parent::dispatch($request);
    }

    public function execute()
    {
        $this->_view->loadLayout();
        $this->_view->getLayout()->initMessages();
        $this->_view->renderLayout();
    }
}
