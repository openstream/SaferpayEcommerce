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

	public function successAction(){
		try{
			$this->verifySignature($this->getRequest()->getParam('DATA', ''), $this->getRequest()->getParam('SIGNATURE', ''));
			$this->_redirect('checkout/onepage/success');
		}catch (Mage_Core_Exception $e){
			Mage::logException($e);
			Mage::helper('checkout')->sendPaymentFailedEmail($this->_getSession()->getQuote(), $e->getMessage());
			$this->_getSession()->addError($e->getMessage());
			$this->_redirect('checkout/cart');
		}catch (Exception $e){
			Mage::logException($e);
			Mage::helper('checkout')->sendPaymentFailedEmail(
				$this->_getSession()->getQuote(),
				Mage::helper('saferpay')->__("An error occures while processing the payment: %s", print_r($e, 1))
			);
			$this->_getSession()->addError(
				Mage::helper('saferpay')->__('An error occured while processing the payment, please contact the store owner for assistance.')
			);
			$this->_redirect('checkout/cart');
		}
	}

	public function notifyAction(){
		try{
			$this->verifySignature($this->getRequest()->getParam('DATA', ''), $this->getRequest()->getParam('SIGNATURE', ''));
			$order = Mage::getModel('sales/order');
			$order->loadByIncrementId($this->getRequest()->getParam('id', ''));
			$payment = $order->getPayment();
			$payment->setStatus(Saferpay_Ecommerce_Model_Abstract::STATUS_APPROVED);
			if ($this->getRequest()->getParam('capture', '') == Mage_Payment_Model_Method_Abstract::ACTION_AUTHORIZE_CAPTURE){
				if (!$order->canInvoice()) {
					Mage::throwException($this->__('Can not create an invoice.'));
				}
				$invoice = $order->prepareInvoice();
				$invoice->register()->capture();
				$order->addRelatedObject($invoice);
				$order->sendNewOrderEmail()
					  ->setEmailSent(true)
					  ->save();
			}else{
				$order->setState(Saferpay_Ecommerce_Model_Abstract::STATE_AUTHORIZED, true, $this->__('Authorized by SaferPay'))
				      ->save();
			}
		}catch (Mage_Core_Exception $e){
			Mage::logException($e);
		}catch (Exception $e){
			Mage::logException($e);
		}	
	}

	public function backAction(){
        $this->_abortPayment('canceled');
	}

	public function failAction(){
        $this->_abortPayment('failed');
	}

	public function _abortPayment($status){
		try
		{
			$this->_getPayment()->abortPayment($status);
		}
		catch (Mage_Core_Exception $e)
		{
			Mage::logException($e);
			$this->_getSession()->addError($e->getMessage());
		}
		catch (Exception $e)
		{
			Mage::logException($e);
			Mage::helper('checkout')->sendPaymentFailedEmail(
				$this->_getSession()->getQuote(),
				Mage::helper('saferpay')->__("An error occures while processing the payment failure: %s", print_r($e, 1)) . "\n"
			);
			$this->_getSession()->addError(
				Mage::helper('saferpay')->__('An error occured while processing the payment failure, please contact the store owner for assistance.')
			);
		}
		$this->_redirect('checkout/cart');
	}


	/**
	 * Verify a signature from the saferpay gateway response
	 *
	 * @param string $data
	 * @param string $sig
	 * @return Saferpay_Business_Model_Abstract
	 */
	public function verifySignature($data, $sig)
	{
		$params = array(
			'DATA' => $data,
			'SIGNATURE' => $sig
		);
		$url = Mage::getStoreConfig('saferpay/settings/verifysig_base_url');
		$response = trim(Mage::helper('saferpay')->process_url($url, $params));
		list($status, $params) = $this->_splitResponseData($response);
		if ($status != 'OK')
		{
			$this->_throwException('Signature invalid, possible manipulation detected! Validation Result: "%s"', $response);
		}
		return $this;
	}

	/**
	 * Seperate the result status and the xml in the response
	 *
	 * @param string $response
	 * @return array
	 */
	protected function _splitResponseData($response)
	{
		if (($pos = strpos($response, ':')) === false)
		{
			$status = $response;
			$xml = '';
		}
		else
		{
			$status = substr($response, 0, strpos($response, ':'));
			$xml = substr($response, strpos($response, ':')+1);
		}
		return array($status, $xml);
	}

	/**
	 *
	 *
	 * @return Saferpay_Business_Model_Abstract
	 */
	protected function _getPayment()
	{
		if (is_null($this->_payment))
		{
			$methodCode = $this->_getSession()->getSaferpayPaymentMethod();
			$model = Mage::getStoreConfig('payment/' . $methodCode . '/model');
			$this->_payment = Mage::getModel($model);
		}
		return $this->_payment;
	}

	/**
	 *
	 * @return Mage_Checkout_Model_Session
	 */
	protected function _getSession()
	{
		return Mage::getSingleton('checkout/session');
	}
}