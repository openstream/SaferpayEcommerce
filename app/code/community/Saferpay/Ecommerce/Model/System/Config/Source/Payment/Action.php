<?php

class Saferpay_Ecommerce_Model_System_Config_Source_Payment_Action
{
        /**
         * Return the options for the payment action configuration
         *
         * @return array
         */
        public function toOptionArray()
        {
                return array(
                        array(
                                'value' => Mage_Payment_Model_Method_Abstract::ACTION_AUTHORIZE,
                                'label' => Mage::helper('saferpay')->__('Authorize Only (Reservation)')
                        ),
                        array(
                                'value' => Mage_Payment_Model_Method_Abstract::ACTION_AUTHORIZE_CAPTURE,
                                'label' => Mage::helper('saferpay')->__('Authorize and Capture (Booking)')
                        )
                );
        }

}