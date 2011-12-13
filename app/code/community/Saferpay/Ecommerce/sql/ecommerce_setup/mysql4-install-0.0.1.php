<?php

 $installer = $this;
 $request = Mage::app()->getRequest();
 $url = $request->getScheme() . '://' . $request->getHttpHost();
 
 $installer->setConfigData('saferpay/settings/success_url', $url.'/saferpay/process/success/');
 $installer->setConfigData('saferpay/settings/back_url', $url.'/saferpay/process/back/');
 $installer->setConfigData('saferpay/settings/fail_url', $url.'/saferpay/process/fail/');
 $installer->setConfigData('saferpay/settings/notify_url', $url.'/saferpay/process/notify/');

?>