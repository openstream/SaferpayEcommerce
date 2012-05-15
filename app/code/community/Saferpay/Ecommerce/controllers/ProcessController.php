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
	public function successAction(){
		$this->_redirect('checkout/'.($this->_processPayment('success') ? 'onepage/success' : 'cart'));
	}

	public function notifyAction(){
		sleep(10);
		$this->_processPayment('notify');
	}

	public function backAction(){
        $this->_abortPayment('canceled');
	}

	public function failAction(){
        $this->_abortPayment('failed');
	}

	public function _abortPayment($event){
		$_session = Mage::getSingleton('checkout/session');
		try{
			$message = Mage::helper('saferpay')->__('Payment aborted with status "%s"', Mage::helper('saferpay')->__($event));
			$order = Mage::getModel('sales/order');
			$order->loadByIncrementId($this->getRequest()->getParam('id', ''));
			$order->cancel();
			$_session->addError($message);
			$payment = $order->getPayment();
			$payment->setStatus('canceled')
					->setIsTransactionClosed(1);
			$order->setState('canceled', true, $message)
				  ->save();
		}catch (Mage_Core_Exception $e){
			Mage::logException($e);
			$_session->addError($e->getMessage());
		}catch (Exception $e){
			Mage::logException($e);
			Mage::helper('checkout')->sendPaymentFailedEmail($_session->getQuote(), Mage::helper('saferpay')->__("An error occures while processing the payment failure: %s", print_r($e, 1)) . "\n");
			$_session->addError(Mage::helper('saferpay')->__('An error occured while processing the payment failure, please contact the store owner for assistance.'));
		}
		$this->_redirect('checkout/cart');
	}
	
	public function _processPayment($event){
		$_session = Mage::getSingleton('checkout/session');
		$order = Mage::getModel('sales/order');
		$order->loadByIncrementId($this->getRequest()->getParam('id', ''));
		try{
			$params = array(
				'DATA' => $this->getRequest()->getParam('DATA', ''),
				'SIGNATURE' => $this->getRequest()->getParam('SIGNATURE', '')
			);
			$url = Mage::getStoreConfig('saferpay/settings/verifysig_base_url');
			$response = Mage::helper('saferpay')->process_url($url, $params);
			list($status, $ret) = Mage::helper('saferpay')->_splitResponseData($response);
			if ($status != 'OK'){
				Mage::throwException(Mage::helper('saferpay')->__('Signature invalid, possible manipulation detected! Validation Result: "%s"', $response));
			}
		
			if($order->getState() == 'pending_payment'){
				$payment = $order->getPayment();
				$payment->setStatus(Saferpay_Ecommerce_Model_Abstract::STATUS_APPROVED);
				if ($this->getRequest()->getParam('capture', '') == Mage_Payment_Model_Method_Abstract::ACTION_AUTHORIZE_CAPTURE){
					$params = array(
						'ACCOUNTID' => Mage::helper('saferpay')->getSetting('saferpay_account_id'),
						'ID' => $ret['ID']
					);
					if(Mage::helper('saferpay')->getSetting('saferpay_password') != ''){
						$params['spPassword'] = Mage::helper('saferpay')->getSetting('saferpay_password');
					}
					$url = Mage::getStoreConfig('saferpay/settings/paycomplete_base_url');
					$response = Mage::helper('saferpay')->process_url($url, $params);
					list($status, $params) = Mage::helper('saferpay')->_splitResponseData($response);
					$params = Mage::helper('saferpay')->_parseResponseXml($params);
					if ($status == 'OK' && is_array($params) && isset($params['RESULT']) && $params['RESULT'] == 0){
						$order->setState(Mage_Sales_Model_Order::STATE_PROCESSING, true, $this->__('Captured by SaferPay. Transaction ID: '.$ret['ID']));
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
						Mage::throwException(Mage::helper('saferpay')->__('PayComplete call failed. Result: "%s"', $response));
					}
				}else{
					$order->setState(Saferpay_Ecommerce_Model_Abstract::STATE_AUTHORIZED, true, $this->__('Authorized by SaferPay. Transaction ID: '.$ret['ID']))
						  ->save();
				}
			}
			return true;
		}catch (Mage_Core_Exception $e){
			Mage::logException($e);
			if($event == 'success'){
			 Mage::helper('checkout')->sendPaymentFailedEmail($_session->getQuote(), $e->getMessage());
			}
			$_session->addError($e->getMessage());
			return false;
		}catch (Exception $e){
			Mage::logException($e);
			Mage::helper('checkout')->sendPaymentFailedEmail($_session->getQuote(), Mage::helper('saferpay')->__("An error occures while processing the payment: %s", print_r($e, 1)));
			$_session->addError(Mage::helper('saferpay')->__('An error occured while processing the payment, please contact the store owner for assistance.'));
			return false;
		}	
	}
}
