<?php
/**
 * Saferpay Ecommerce Magento Payment Extension
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade Saferpay Business to
 * newer versions in the future. If you wish to customize Magento for your
 * needs please refer to http://www.magentocommerce.com for more information.
 *
 * @copyright Copyright (c) 2011 Openstream Internet Solutions (http://www.openstream.ch)
 * @license   http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

class Saferpay_Ecommerce_ProcessController extends Mage_Core_Controller_Front_Action
{
    protected $_payment;

	public function successAction()
    {
        $request = $this->getRequest();
        if ($this->_getPayment()->_processPayment('success', $request)) {
            $this->_redirect('checkout/onepage/success');
        } else {
            $this->_redirect('checkout/cart');
        }
	}

	public function notifyAction()
    {
        $request = $this->getRequest();
        $this->_getPayment()->_processPayment('notify', $request);
        $this->getResponse()->setBody('Hello Saferpay!');
	}

	public function backAction()
    {
        $order_id = $this->getRequest()->getParam('id', '');
        $this->_getPayment()->_abortPayment('canceled', $order_id);
        $this->_redirect('checkout/cart');
	}

	public function failAction()
    {
        $order_id = $this->getRequest()->getParam('id', '');
        $this->_getPayment()->_abortPayment('failed', $order_id);
        $this->_redirect('checkout/cart');
	}

    /**
     * Return an instance of the Saferpay payment method. In order to do so the
     * value saved on the customer session id checked.
     *
     * @return Saferpay_Ecommerce_Model_Abstract
     */
    protected function _getPayment()
    {
        if (is_null($this->_payment))
        {
            if ($methodCode = Mage::getSingleton('checkout/session')->getSaferpayPaymentMethod()) {
                $model = Mage::getStoreConfig('payment/' . $methodCode . '/model');
                $this->_payment = Mage::getModel($model);
            } elseif ($order_id = $this->getRequest()->getParam('id')) {
                /** @var $order Mage_Sales_Model_Order */
                $order = Mage::getModel('sales/order')->loadByIncrementId($order_id);
                $this->_payment = $order->getPayment()->getMethodInstance();
            }
            if (! $this->_payment)
            {
                Mage::throwException(
                    $this->__('An error occurred while processing the payment: unable to recreate payment instance.')
                );
            }
        }
        return $this->_payment;
    }
}
