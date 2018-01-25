<?php

/**
 * @package           ICEPAY Payment Module for OpenCart
 * @author            Ricardo Jacobs <ricardo.jacobs@icepay.com>
 * @copyright     (c) 2016-2018 ICEPAY. All rights reserved.
 * @license           BSD 2 License, see https://github.com/icepay/OpenCart/blob/master/LICENSE
 */

require_once realpath(dirname(__FILE__)) . '/icepay/icepay_api_webservice.php';

class ModelExtensionPaymentIcepayBasic extends Model {
	private $order = null;

//	public function loadPaymentMethodService() {
//		$api = Icepay_Api_Webservice::getInstance()->paymentMethodService();
//
//		return $this->setApiSettings($api);
//	}

	public function loadPaymentService() {
		$api = Icepay_Api_Webservice::getInstance()->paymentService();

		return $this->setApiSettings($api);
	}

	public function loadPostback() {
		$api = Icepay_Project_Helper::getInstance()->postback();

		return $this->setApiSettings($api);
	}

	public function loadResult() {
		$api = Icepay_Project_Helper::getInstance()->result();

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

	public function getUrl($order, $issuer) {
		$api = $this->loadPaymentService();
		$api->addToExtendedCheckoutList(array('AFTERPAY'));

		$this->load->model('checkout/order');

		$total = $this->currency->format($order['total'], $order['currency_code'], $order['currency_value'], false);
		$total = (int)(string)($total * 100);

		$payment_method_id = str_replace('icepay_pm_', "", $order['payment_code']);
		$payment_method_code = $this->getPaymentMethodCode($payment_method_id);

		$language =  strtoupper(substr($this->session->data['language'],0, 2));

		$payment_obj = new Icepay_PaymentObject();
		$payment_obj->setOrderID($order["order_id"])
			->setReference($order["order_id"])
			->setAmount($total)
			->setCurrency($this->session->data['currency'])
			->setCountry($order['payment_iso_code_2'])
			->setLanguage($language)
			->setPaymentMethod($payment_method_code)
			->setIssuer($issuer);

		$api->setSuccessURL($this->url->link('extension/payment/icepay_basic/result', '', true))
			->setErrorURL($this->url->link('extension/payment/icepay_basic/result', '', true));

		$transaction_obj = null;

		try {
			if ($api->isExtendedCheckoutRequiredByPaymentMethod($payment_method_code)) {
				$customer_id = ($order['customer_id']) ? $order['customer_id'] : '-';

				Icepay_Order::getInstance()->setConsumer(
					Icepay_Order_Consumer::create()
						->setConsumerID($customer_id)
						->setEmail($order['email'])
						->setPhone($order['telephone'])
				);

				$street = $order['payment_address_1'] . ' ' . $order['payment_address_2'];
				Icepay_Order::getInstance()
					->setBillingAddress(Icepay_Order_Address::create()
						->setInitials($order['payment_firstname'])
						->setLastName($order['payment_lastname'])
						->setStreet(Icepay_Order_Helper::getStreetFromAddress($street))
						->setHouseNumber(Icepay_Order_Helper::getHouseNumberFromAddress($street))
						->setHouseNumberAddition(Icepay_Order_Helper::getHouseNumberAdditionFromAddress($street))
						->setZipCode($order['payment_postcode'])
						->setCity($order['payment_city'])
						->setCountry($order['payment_iso_code_2'])
					);

				$initials = empty($order['shipping_firstname']) ? $order['payment_firstname'] : $order['shipping_firstname'];
				$last_name = empty($order['shipping_lastname']) ? $order['payment_lastname'] : $order['shipping_lastname'];
				$zip_code = empty($order['shipping_postcode']) ? $order['payment_postcode'] : $order['shipping_postcode'];
				$city = empty($order['shipping_city']) ? $order['payment_city'] : $order['shipping_city'];
				$country = empty($order['shipping_iso_code_2']) ? $order['payment_iso_code_2'] : $order['shipping_iso_code_2'];

				if (!empty($order['shipping_address_1'])) {
					$street = $order['shipping_address_1'] . ' ' . $order['shipping_address_2'];
				}

				Icepay_Order::getInstance()
					->setShippingAddress(Icepay_Order_Address::create()
						->setInitials($initials)
						->setLastName($last_name)
						->setStreet(Icepay_Order_Helper::getStreetFromAddress($street))
						->setHouseNumber(Icepay_Order_Helper::getHouseNumberFromAddress($street))
						->setHouseNumberAddition(Icepay_Order_Helper::getHouseNumberAdditionFromAddress($street))
						->setZipCode($zip_code)
						->setCity($city)
						->setCountry($country)
					);

				// Set Product information
				foreach ($this->cart->getProducts() as $product) {
					$rates = $this->tax->getRates($product['price'], $product['tax_class_id']);
					$tax_info = array_shift($rates);

					$tax_rate = (int)(string)$tax_info['rate'];
					$unit_price = (int)(string)(($product['price'] * 100) + ($tax_info['amount'] * 100));

					Icepay_Order::getInstance()
						->addProduct(Icepay_Order_Product::create()
							->setProductID($product['product_id'])
							->setProductName($product["name"])
							->setDescription($product["name"])
							->setQuantity($product["quantity"])
							->setUnitPrice($unit_price)
							->setVATCategory(Icepay_Order_VAT::getCategoryForPercentage($tax_rate))
						);
				}

				if (isset($this->session->data['shipping_method'])) {
					// Shipping costs
					$rates = $this->tax->getRates($this->session->data['shipping_method']['cost'], $this->session->data['shipping_method']['tax_class_id']);
					$tax_info = array_shift($rates);
					$shipping_costs = (int)(string)(($this->session->data['shipping_method']['cost'] * 100));

					if (!empty($tax_info)) {
						$vat_amount = (int)(string)($tax_info['amount'] * 100);
						$shipping_costs = $shipping_costs + $vat_amount;
					}

					Icepay_Order::getInstance()
						->addProduct(Icepay_Order_Product::create()
							->setProductID(01)
							->setProductName($this->session->data['shipping_method']['title'])
							->setDescription($this->session->data['shipping_method']['title'])
							->setQuantity(1)
							->setUnitPrice($shipping_costs)
							->setVATCategory(Icepay_Order_VAT::getCategoryForPercentage($tax_info['rate']))
						);
				}
				// Discounts
				$total_data = array();
				$total1 = 0;
				$total2 = $total;
				$taxes = $this->cart->getTaxes();

				$this->load->model('total/voucher');
				$this->{'model_total_voucher'}->getTotal($total_data, $total2, $taxes);

				$this->load->model('total/coupon');
				$this->{'model_total_coupon'}->getTotal($total_data, $total1, $taxes);

				$this->load->model('total/reward');
				$this->{'model_total_reward'}->getTotal($total_data, $total1, $taxes);

				foreach ($total_data as $discount) {
					$price = (int)(string)($discount['value'] * 100);
					$price = -1 * abs($price);

					if ($discount['code'] != 'voucher') {
						$price = $price * 1.21;
					}

					$price = (int)(string)$price;

					Icepay_Order::getInstance()
						->addProduct(Icepay_Order_Product::create()
							->setProductID($discount['code'])
							->setProductName($discount['title'])
							->setDescription($discount['title'])
							->setQuantity(1)
							->setUnitPrice($price)
							->setVATCategory(Icepay_Order_VAT::getCategoryForPercentage(21))
						);
				}

				$transaction_obj = $api->extendedCheckOut($payment_obj);
			} else {
				$transaction_obj = $api->CheckOut($payment_obj);

			}
		} catch (Exception $e) {
			$_SESSION['ICEPAY_ERROR'] = $this->language->get($e->getMessage());
			$this->log('ICEPAY ERROR ' . $this->language->get($e->getMessage(), 1));

			return false;
		}

		$this->createOrder($order);

		return $transaction_obj->getPaymentScreenURL();
	}

	private function createOrder($order) {
		$try = $this->db->query("SELECT status FROM `{$this->getTableWithPrefix('icepay_orders')}` WHERE `order_id` = '{$order['order_id']}'");

		if ($try->num_rows == 0) {
			$this->db->query("INSERT INTO `{$this->getTableWithPrefix('icepay_orders')}` 
				(`order_id` ,`status` ,`order_data` ,`created` ,`last_update`)
					VALUES
				('{$order['order_id']}', 'NEW', '', NOW(), NOW())");
		}
	}

	public function getOpenCartStatus($icepay_status_code) {
		return $this->config->get(sprintf("payment_icepay_basic_%s_status_id", strtolower($icepay_status_code)));
	}

	public function getOpencartOrder($order_id) {
		$this->load->model('checkout/order');

		return $this->model_checkout_order->getOrder($order_id);
	}

	public function getIcepayOrderById($order_id) {
		$query = $this->db->query("SELECT * FROM `{$this->getTableWithPrefix('icepay_orders')}` WHERE `order_id` = '{$order_id}'");

		$this->order = $query->rows[0];

		return $this->order;
	}

	public function updateStatus($order_id, $status, $transaction_id) {
		$this->db->query("UPDATE `{$this->getTableWithPrefix('icepay_orders')}` SET `status` = '{$status}', `transaction_id` = '{$transaction_id}', last_update = NOW() WHERE `order_id` = '{$order_id}' LIMIT 1;");
	}

	public function isFirstOrder($order_id) {
		if ($this->order == null) {
			$this->getIcepayOrderById($order_id);
		}
		if ($this->order["transaction_id"] == 0) {
			return true;
		}

		return false;
	}

	public function getMethod($address, $total) {
		$this->load->language('extension/payment/icepay_basic');
		$this->load->model('localisation/currency');

		$method_data = array();

		if (!$this->config->get('payment_icepay_basic_status')) {
			return;
		}

		if (isset($this->pm_code)) {
			$store_id = $this->config->get('config_store_id');

			$payment_method = $this->db->query("SELECT * FROM `{$this->getTableWithPrefix('icepay_pminfo')}` WHERE `id` ='{$this->pm_code}' AND `active` = '1' AND (`store_id` = '-1' OR `store_id` = '{$store_id}')");

			if (count($payment_method->row) > 0) {

				// Check if payment method has specific geo zone
				if ($payment_method->row['geo_zone_id'] != '-1') {
					// See if geo zones matches
					$query = $this->db->query("SELECT * FROM `{$this->getTableWithPrefix('zone_to_geo_zone')}` WHERE `geo_zone_id` = '{$payment_method->rows[0]['geo_zone_id']}' AND country_id = '{$address['country_id']}' AND (zone_id = '{$address['zone_id']}' OR zone_id = '0')");

					// No match
					if (!$query->num_rows) {
						return $method_data;
					}
				}

				// Filter paymentmethod based on country, amount and currency from icepay raw data
				$stored_payment_methods = $this->db->query("SELECT * FROM `{$this->getTableWithPrefix('icepay_rawpmdata')}`");

				$filter = Icepay_Api_Webservice::getInstance()->filtering();
				$filter->loadFromArray(unserialize($stored_payment_methods->row['raw_pm_data']));
				$filter->filterByCurrency($this->session->data['currency'])
					->filterByCountry($address['iso_code_2'])
					->filterByAmount((int)(string)($total * 100));

				if ($filter->isPaymentMethodAvailable($payment_method->row['pm_code'])) {
					$method_data = array(
						'code'       => "icepay_pm_{$this->pm_code}",
						'terms'      => "",
						'title'      => $payment_method->row['displayname'],
						'sort_order' => $this->config->get('icepay_sort_order')
					);
				}
			} else {
				return '';
			}
		}

		return $method_data;
	}

	public function getIssuers($pm_id) {
		if (isset($pm_id)) {
			$payment_method = $this->db->query("SELECT * FROM `{$this->getTableWithPrefix('icepay_pminfo')}` WHERE `id` ='{$pm_id}'");

			$raw_data = $this->db->query("SELECT * FROM `{$this->getTableWithPrefix('icepay_rawpmdata')}`");

			$method = Icepay_Api_Webservice::getInstance()->singleMethod()->loadFromArray(unserialize($raw_data->row['raw_pm_data']));
			$p_method = $method->selectPaymentMethodByCode($payment_method->row['pm_code']);

			$issuers = $p_method->getIssuers();

			return $issuers;
		}

		return '';
	}


	public function getPaymentMethodName($pm_id) {
		if (isset($pm_id)) {
			$payment_method = $this->db->query("SELECT * FROM `{$this->getTableWithPrefix('icepay_pminfo')}` WHERE `id` ='{$pm_id}'");

			return $payment_method->row['displayname'];
		}

		return false;
	}

	public function getPaymentMethodCode($pm_id) {
		if (isset($pm_id) && $pm_id != 'c') {
			$payment_method = $this->db->query("SELECT * FROM `{$this->getTableWithPrefix('icepay_pminfo')}` WHERE `id` ='{$pm_id}'");

			return $payment_method->row['pm_code'];
		}
	}

	public function getTableWithPrefix($table_name) {
		return DB_PREFIX . $table_name;
	}

	public function log($data, $class_step = 6) {
		if ($this->config->get('icepay_basic_debug')) {
			$log = new Log('icepay.log');
			$backtrace = debug_backtrace();
			$log->write('ICEPAY debug (' . $backtrace[$class_step]['class'] . '::' . $backtrace[6]['function'] . ') - ' . print_r($data, true));
		}
	}
}
