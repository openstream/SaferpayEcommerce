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

abstract class Saferpay_Ecommerce_Model_Abstract extends Mage_Payment_Model_Method_Abstract
{
    /**
     * Payment Method Code
     *
     * @var string
     */
    protected $_code = 'abstract';

    protected $_formBlockType = 'saferpay/form';
    protected $_infoBlockType = 'saferpay/info';

    /*
     * Availability options
     */
    protected $_isGateway              = true;
    protected $_canAuthorize           = true;
    protected $_canCapture             = true;
    protected $_canCapturePartial      = false;
    protected $_canRefund              = false;
    protected $_canVoid                = false;
    protected $_canUseInternal         = false;
    protected $_canUseCheckout         = true;
    protected $_canUseForMultishipping = false;

    protected $_order;

    const STATE_AUTHORIZED = 'authorized';

    /**
     * @param $event string
     * @param $request Mage_Core_Controller_Request_Http
     * @return bool
     */
    public function _processPayment($event, $request){
        /** @var $_session Mage_Checkout_Model_Session */
        $_session = Mage::getSingleton('checkout/session');
        $helper = $this->getHelper();
        /** @var $checkout_helper Mage_Checkout_Helper_Data */
        $checkout_helper = Mage::helper('checkout');

        try{
            if ($transaction_id = $this->validateSignature($request)){
                $order = $this->getOrder();

                if ($event == 'notify') {
                    if ($order->getState() == 'pending_payment') {
                        $this->setStatus(Saferpay_Ecommerce_Model_Abstract::STATUS_APPROVED);
                        $data = $helper->_parseResponseXml($request->getParam('DATA'));
                        while (list($key, $val) = each($data)) {
                            $this->getInfoInstance()->setAdditionalInformation($key, $val);
                        }
                        if ($request->getParam('capture', '') == Mage_Payment_Model_Method_Abstract::ACTION_AUTHORIZE_CAPTURE){
                            $params = array(
                                'ACCOUNTID' => $helper->getSetting('saferpay_account_id'),
                                'ID' => $transaction_id
                            );
                            if($helper->getSetting('saferpay_password') != ''){
                                $params['spPassword'] = $helper->getSetting('saferpay_password');
                            }
                            $url = Mage::getStoreConfig('saferpay/settings/paycomplete_base_url');
                            $response = $helper->process_url($url, $params);
                            list($status, $params) = $helper->_splitResponseData($response);
                            if ($status == 'OK') {
                                $params = $helper->_parseResponseXml($params);
                                if (is_array($params) && isset($params['RESULT']) && $params['RESULT'] == 0){
                                    $order->setState(Mage_Sales_Model_Order::STATE_PROCESSING, true, $helper->__('Captured by SaferPay. Transaction ID: ' . $transaction_id));
                                    if (!$order->canInvoice()) {
                                        Mage::throwException($helper->__('Can not create an invoice.'));
                                    }
                                    $invoice = $order->prepareInvoice();
                                    $invoice->register()->capture();
                                    $order->addRelatedObject($invoice);
                                    $order->save();
                                }
                            }else{
                                Mage::throwException($helper->__('PayComplete call failed. Result: "%s"', $response));
                            }
                        }else{
                            $order->setState(Saferpay_Ecommerce_Model_Abstract::STATE_AUTHORIZED, true, $helper->__('Authorized by SaferPay. Transaction ID: ' . $transaction_id))
                                ->save();
                        }
                    }
                } elseif ($event == 'success') {
                    $order->sendNewOrderEmail();
                }
            } else {
                Mage::throwException($helper->__('Signature invalid, possible manipulation detected!'));
            }
        }catch (Mage_Core_Exception $e){
            Mage::logException($e);
            if($event == 'success'){
                $checkout_helper->sendPaymentFailedEmail($_session->getQuote(), $e->getMessage());
                $_session->addError($e->getMessage());
            }
            return false;
        }catch (Exception $e){
            Mage::logException($e);
            if($event == 'success'){
                $checkout_helper->sendPaymentFailedEmail($_session->getQuote(), $helper->__("An error occurred while processing the payment: %s", $e->getMessage()));
                $_session->addError($helper->__('An error occurred while processing the payment, please contact the store owner for assistance.'));
            }
            return false;
        }
        return true;
    }

    /**
     * @param $request Mage_Core_Controller_Request_Http
     * @return int
     */
    private function validateSignature($request)
    {
        $helper = $this->getHelper();
        $params = array(
            'DATA'      => $request->getParam('DATA', ''),
            'SIGNATURE' => $request->getParam('SIGNATURE', '')
        );
        $url = Mage::getStoreConfig('saferpay/settings/verifysig_base_url');
        $response = $helper->process_url($url, $params);
        list($status, $ret) = $helper->_splitResponseData($response);

        return $status == 'OK' ? $ret['ID'] : 0;
    }

    public function _abortPayment($event, $order_id)
    {
        /** @var $_session Mage_Checkout_Model_Session */
        $_session = Mage::getSingleton('checkout/session');
        $helper = $this->getHelper();
        try{
            $message = $helper->__('Payment aborted with status "%s"', $helper->__($event));
            /** @var $order Mage_Sales_Model_Order */
            $order = Mage::getModel('sales/order');
            $order->loadByIncrementId($order_id);
            $order->cancel();
            $_session->addError($message);
            /** @var $payment Mage_Sales_Model_Order_Payment */
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
            Mage::helper('checkout')->sendPaymentFailedEmail($_session->getQuote(), $helper->__("An error occurred while processing the payment failure: %s", $e->getMessage()) . "\n");
            $_session->addError($helper->__('An error occurred while processing the payment failure, please contact the store owner for assistance.'));
        }

    }

    /**
     * Get order model
     *
     * @return Mage_Sales_Model_Order
     */
    public function getOrder()
    {
        if (!$this->_order) {
            try{
                $this->_order = $this->getInfoInstance()->getOrder();
                if (! $this->_order)
                {
                    $orderId = $this->getSession()->getQuote()->getReservedOrderId();
                    /** @var $order Mage_Sales_Model_Order */
                    $order = Mage::getModel('sales/order');
                    $order->loadByIncrementId($orderId);
                    if ($order->getId())
                    {
                        $this->_order = $order;
                    }
                }
            }catch(Exception $e){
                $id = $this->getSession()->getLastOrderId();
                $this->_order = Mage::getModel('sales/order')->load($id);
            }
        }
        return $this->_order;
    }

    /**
     *
     * @return Mage_Checkout_Model_Session
     */
    public function getSession()
    {
        return Mage::getSingleton('checkout/session');
    }

    /**
     * Return the payment provider id
     *
     * @return string
     */
    public function getProviderId()
    {
        $id = str_replace(' ', '', (string) $this->getConfigData('provider_id'));
        return $id;
    }

    /**
     * Return url for redirection after order placed
     *
     * @return string
     */
    public function getOrderPlaceRedirectUrl()
    {
        $helper = $this->getHelper();
        $url = $helper->process_url($helper->getSetting('payinit_base_url'), $this->getPayInitFields());
        if(preg_match('|^http(s)?://[a-z0-9-]+(.[a-z0-9-]+)*(:[0-9]+)?(/.*)?$|i', $url)){
            return $url;
        }else{
            $this->getSession()->addError($helper->__('An error occurred while processing the payment failure, please contact the store owner for assistance.'));
            return Mage::getUrl('checkout/cart');
        }
    }

    /**
     * Capture payment through saferpay
     *
     * @param Varien_Object $payment
     * @param float $amount
     * @return Saferpay_Ecommerce_Model_Abstract
     */
    public function capture(Varien_Object $payment, $amount)
    {
        $payment->setStatus(self::STATUS_APPROVED)
            ->setTransactionId($this->getTransactionId())
            ->setIsTransactionClosed(0);

        return $this;
    }

    /**
     * Cancel payment
     *
     * @param Varien_Object $payment
     * @return Saferpay_Ecommerce_Model_Abstract
     */
    public function cancel(Varien_Object $payment)
    {
        $payment->setStatus(self::STATUS_DECLINED)
            ->setTransactionId($this->getTransactionId())
            ->setIsTransactionClosed(1);

        return $this;
    }

    /**
     * Prepare params array to send it to gateway
     *
     * @return array
     */
    public function getPayInitFields()
    {
        $helper = $this->getHelper();
        $orderId = $this->getOrder()->getRealOrderId();

        $params = array(
            'ACCOUNTID'             => $helper->getSetting('saferpay_account_id'),
            'AMOUNT'                => intval(round($helper->round($this->getOrder()->getGrandTotal(), 2) * 100)),
            'CURRENCY'              => $this->getOrder()->getOrderCurrencyCode(),
            'DESCRIPTION'           => $this->getOrder()->getStore()->getWebsite()->getName(),
            'CCCVC'                 => 'yes',
            'CCNAME'                => 'yes',
            'ORDERID'               => $orderId,
            'SUCCESSLINK'           => Mage::getUrl('saferpay/process/success', array('id' => $orderId, 'capture' => $this->getConfigData('payment_action'))),
            'BACKLINK'              => Mage::getUrl('saferpay/process/back', array('id' => $orderId, 'capture' => $this->getConfigData('payment_action'))),
            'FAILLINK'              => Mage::getUrl('saferpay/process/fail', array('id' => $orderId, 'capture' => $this->getConfigData('payment_action'))),
            'NOTIFYURL'             => Mage::getUrl('saferpay/process/notify', array('id' => $orderId, 'capture' => $this->getConfigData('payment_action'))),
            'AUTOCLOSE'             => 0,
            'PROVIDERSET'           => $this->getProviderId(),
            'LANGID'                => $this->getLangId(),
            'SHOWLANGUAGES'         => $this->getUseDefaultLangId() ? 'yes' : 'no',
            'DELIVERY'				=> 'no',
            'VTCONFIG'				=> $helper->getSetting('vtconfig')
        );

        return $params;
    }

    /**
     * Return the language to use in the saferpay terminal
     *
     * @param string
     * @return string
     */
    protected function getLangId($lang = null)
    {
        try
        {
            if (is_null($lang))
            {
                $lang = $this->_getOrderLang();
            }
            if ($lang)
            {
                if ($xml = $this->_getLangIdsXml())
                {
                    $nodes = $xml->xpath("//LANGUAGE[@CODE='{$lang}']");
                    foreach ($nodes as $node)
                    {
                        return (string) $node['CODE'];
                    }
                }
            }
        }
        catch (Exception $e)
        {
            Mage::logException($e);
        }

        $this->setUseDefaultLangId(true);
        return $this->getHelper()->getSetting('default_lang_id');
    }

    protected function _getOrderLang()
    {
        $orderLocale = $locale = Mage::getStoreConfig('general/locale/code', $this->getOrder()->getStoreId());
        $lang = strtolower(substr($orderLocale, 0, 2));
        return $lang;
    }

    /**
     * Return the available language id's from the saferpay API
     *
     * @return SimpleXMLElement
     */
    protected function _getLangIdsXml()
    {
        $langIds = $this->getData('lang_ids_xml');
        if (is_null($langIds))
        {
            $helper = $this->getHelper();
            $url = $helper->getSetting('language_ids_url');
            if ($langIds = new SimpleXMLElement($helper->process_url($url)))
            {
                $this->setLangIdsXml($langIds);
            }
        }
        return $langIds;
    }

    /**
     * Get initialized flag status
     *
     * @return boolean
     */
    public function isInitializeNeeded()
    {
        return true;
    }

    /**
     * Instantiate state and set it to state object
     *
     * @param string $paymentAction
     * @param object $stateObject
     * @return void
     */
    public function initialize($paymentAction, $stateObject)
    {
        $stateObject->setState(Mage_Sales_Model_Order::STATE_PENDING_PAYMENT);
        $stateObject->setStatus(Mage_Sales_Model_Order::STATE_PENDING_PAYMENT);
        $stateObject->setIsNotified(false);
        $this->getSession()->setSaferpayPaymentMethod($this->getCode());
    }

    /**
     * Throw an exception with a default error message if none is specified
     *
     * @param string $msg
     * @param array $params
     */
    protected function _throwException($msg = null, $params = null)
    {
        if (is_null($msg))
        {
            $msg = $this->getConfigData('generic_error_msg');
        }
        Mage::throwException($this->getHelper()->__($msg, $params));
    }

    /**
     * @return Saferpay_Ecommerce_Helper_Data
     */
    private function getHelper()
    {
        return Mage::helper('saferpay');
    }
}
