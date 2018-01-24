<?php

/**
 * @package           ICEPAY Payment Module for OpenCart
 * @author            Ricardo Jacobs <ricardo.jacobs@icepay.com>
 * @copyright     (c) 2016-2018 ICEPAY. All rights reserved.
 * @license           BSD 2 License, see https://github.com/icepay/OpenCart/blob/master/LICENSE
 */

require_once DIR_CATALOG . 'model/extension/payment/icepay/icepay_api_webservice.php';

class ModelExtensionPaymentIcepayBasic extends Model {

	public function loadPaymentMethodService() {
		$api = Icepay_Api_Webservice::getInstance()->paymentMethodService();

		return $this->setApiSettings($api);
	}

	private function setApiSettings($api) {

		try {
			$api->setMerchantID(intval($this->config->get('payment_icepay_basic_merchantid')))->setSecretCode($this->config->get('payment_icepay_basic_secretcode'));
		} catch (Exception $e) {
			echo 'Postback URL installed correctly';
		}

		return $api;
	}

	public function getTableWithPrefix($table_name) {
		return DB_PREFIX . $table_name;
	}


}