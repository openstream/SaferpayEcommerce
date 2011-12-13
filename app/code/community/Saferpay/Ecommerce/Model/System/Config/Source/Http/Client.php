<?php

class Saferpay_Ecommerce_Model_System_Config_Source_Http_Client
{
	/**
	 * Return an option list of network access methods.
	 * The values can be either a class of 
	 *
	 * @return array
	 */
	public function toOptionArray()
	{
		return array(
			array(
				'value' => 'stream_wrapper',
				'label' => Mage::helper('saferpay')->__('File (PHP Stream Wrappers)')
			),
			array(
				'value' => 'Zend_Http_Client_Adapter_Curl',
				'label' => Mage::helper('saferpay')->__('cURL')
			)
		);
	}

}

?>