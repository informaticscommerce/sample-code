<?php
/**
 * @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

namespace InformaticsCommerce\ReportApi\Block;

use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;

/**
 * ReportRequest content block
 */
class ReportRequest extends Template
{
    public function __construct(
        Context $context
    )
    {
        parent::__construct($context);
    }

    public function _prepareLayout()
    {
        $this->pageConfig->getTitle()->set(__('Sales Order Status Inquiry'));

        return parent::_prepareLayout();
    }
}
