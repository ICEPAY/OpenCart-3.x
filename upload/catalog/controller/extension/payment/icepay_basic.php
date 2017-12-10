<?php

/**
 * @package           ICEPAY Payment Module for OpenCart
 * @author            Ricardo Jacobs <ricardo.jacobs@icepay.com>
 * @copyright     (c) 2017 ICEPAY. All rights reserved.
 * @license           BSD 2 License, see https://github.com/icepay/OpenCart/blob/master/LICENSE
 */

define('ICEPAY_MODULE_VERSION', '3.0.0');

class ControllerExtensionPaymentIcepayBasic extends Controller {
	protected $api;

	private function init() {
		$this->load->model('extension/payment/icepay_basic');
		$this->load->model('checkout/order');
		$this->load->model('setting/setting');
		// Load language files
		$this->load->language('extension/payment/icepay_basic');
	}

	private function showErrorPage($message) {

		$data['heading_title'] = $this->language->get('error_header');
		$data['text_message'] = $message;
		$data['button_continue'] = $this->language->get('button_continue');
		$data['continue'] = $this->url->link('checkout/checkout', '', true);

		$data['column_left'] = $this->load->controller('common/column_left');
		$data['column_right'] = $this->load->controller('common/column_right');
		$data['content_top'] = $this->load->controller('common/content_top');
		$data['content_bottom'] = $this->load->controller('common/content_bottom');
		$data['footer'] = $this->load->controller('common/footer');
		$data['header'] = $this->load->controller('common/header');

		$this->response->setOutput($this->load->view('extension/payment/icepay_error', $data));
	}

	public function saveMyPaymentMethods() {
		if (!$this->session->data['ajax_ok']) {
			return;
		}

		$this->init();

		// Delete old pminfo
		$this->db->query("TRUNCATE TABLE `{$this->model_extension_payment_icepay_basic->getTableWithPrefix('icepay_pminfo')}`");

		$params = array();

		parse_str($_POST['content'], $params);

		$params_clean = array();

		foreach ($params as $key => $param) {
			$key = str_replace('amp;', '', $key);
			$params_clean[$key] = $param;
		}

		$this->db->query("DELETE FROM `{$this->model_extension_payment_icepay_basic->getTableWithPrefix('extension')}` WHERE `code` LIKE 'icepay_pm_%'");

		$i = 1;

		foreach ($params_clean['paymentMethodCode'] as $key => $payment_method) {
			// Paymentmethod code
			$pm_code = $this->db->escape($payment_method);

			// Displayname
			$display_name = $this->db->escape($params_clean['paymentMethodDisplayName'][$key]);

			// PM name
			$pm_name = $this->db->escape($params_clean['paymentDisplayName'][$key]);

			// Geo Zone
			$geo_zone = $this->db->escape($params_clean['paymentMethodGeoZone'][$key]);

			$active = 0;
			if (isset($params_clean['paymentMethodActive']) && isset($params_clean['paymentMethodActive'][$key])) {
				$active = 1;
			}

			// Store
			$store = $this->db->escape($params_clean['paymentMethodStore'][$key]);

			$this->db->query("INSERT INTO `{$this->model_extension_payment_icepay_basic->getTableWithPrefix('icepay_pminfo')}`
				(store_id, active, displayname, readablename, pm_code, geo_zone_id) VALUES ('{$store}', '{$active}', '{$display_name}', '{$pm_name}', '{$pm_code}', '{$geo_zone}')");

			$this->db->query("INSERT INTO `{$this->model_extension_payment_icepay_basic->getTableWithPrefix('extension')}`
				(type, code) VALUES ('payment', 'icepay_pm_{$i}')");

			$i++;
		}

		die();
	}

	public function getMyPaymentMethods() {
		if (!$this->session->data['ajax_ok']) {
			return;
		}

		$this->init();
		$this->load->model('setting/store');

		if (class_exists('SoapClient') === false) {
			echo "Error: SOAP extension for PHP must be enabled. Please contact your webhoster!";
			die();
		}

		$this->api = $this->model_extension_payment_icepay_basic->loadPaymentMethodService();

		try {
			// Retrieve paymentmethods
			$payment_methods = $this->api->retrieveAllPaymentmethods()->asArray();

			// Delete old rawpmdata
			$this->db->query("TRUNCATE TABLE `{$this->model_extension_payment_icepay_basic->getTableWithPrefix('icepay_rawpmdata')}`");

			// Store new rawpmdata
			$serialized_raw_data = serialize($payment_methods);
			$this->db->query("INSERT INTO `{$this->model_extension_payment_icepay_basic->getTableWithPrefix('icepay_rawpmdata')}` (raw_pm_data) VALUES ('{$serialized_raw_data}')");

			// Get stores and generate select options
			$stores = $this->model_setting_store->getStores();
			$geo_zone_data = $this->db->query("SELECT * FROM " . DB_PREFIX . "geo_zone ORDER BY name ASC");

			$stores[] = array('store_id' => '-1', 'name' => 'All Stores');
			$stores[] = array('store_id' => '0', 'name' => 'Default');

			$html = '';

			// Display payment methods on page
			if (count($payment_methods) > 0) {
				foreach ($payment_methods as $key => $payment_method) {
					if (isset($payment_method['PaymentMethodCode'])) {
						$pm_code = $payment_method['PaymentMethodCode'];

						$payment_method_stored_data = $this->db->query("SELECT * FROM `{$this->model_extension_payment_icepay_basic->getTableWithPrefix('icepay_pminfo')}` WHERE `pm_code` = '$pm_code'");

						if (isset($payment_method_stored_data->row['displayname'])) {
							$display_name = $payment_method_stored_data->row['displayname'];
						} else {
							$display_name = $payment_method['Description'];
						}

						$readable_name = $payment_method['Description'];
						$pm_active = false;

						// Check if paymentmethod exists already, if so fetch it and prefill the form with user saved data.
						$payment_method_info = $this->db->query("SELECT * FROM`{$this->model_extension_payment_icepay_basic->getTableWithPrefix('icepay_pminfo')}` WHERE `pm_code` = '{$pm_code}' ");

						// Stored data has been found
						if (count($payment_method_info->row) > 0) {
							$readable_name = $payment_method_info->row['readablename'];

							if ($payment_method_info->row['active']) {
								$pm_active = true;
							}
						}

						$checked = ($pm_active) ? 'checked=checked' : '';

						$html .= "<tr>";
						$html .= "<td><input type='hidden' name='paymentMethodCode[{$key}]' value='{$pm_code}' />
									  <input type='hidden' name='paymentDisplayName[{$key}]' value='{$display_name}' />
									  {$display_name}
								 </td>";
						$html .= "<td><input name='paymentMethodActive[{$key}]' type='checkbox' {$checked} /></td>";
						$html .= "<td><input name='paymentMethodDisplayName[{$key}]' type='text' style='padding: 5px; width: 200px;' value='{$readable_name}' /></td>";
						$html .= "<td><select name='paymentMethodStore[{$key}]' style='padding: 5px; width: 200px;'>";

						foreach ($stores as $store) {
							if (isset($payment_method_stored_data->row['store_id']) && $store['store_id'] == $payment_method_stored_data->row['store_id']) {
								$html .= "<option value='{$store['store_id']}' selected>{$store['name']}</option>";
							} else {
								$html .= "<option value='{$store['store_id']}'>{$store['name']}</option>";
							}
						}

						$html .= "</select></td>";
						$html .= "<td>
									<select name='paymentMethodGeoZone[{$key}]' style='padding: 5px; width: 150px;'>
										<option value='-1'>All Zones</option>";
						foreach ($geo_zone_data->rows as $geo_zone) {
							if (isset($payment_method_stored_data->row['geo_zone_id']) && $geo_zone['geo_zone_id'] == $payment_method_stored_data->row['geo_zone_id']) {
								$html .= "<option value='{$geo_zone['geo_zone_id']}' selected>{$geo_zone['name']}</option>";
							} else {
								$html .= "<option value='{$geo_zone['geo_zone_id']}'>{$geo_zone['name']}</option>";
							}
						}

						$html .= "</select>
								  </td>";
						$html .= "</tr>";
					}
				}

				echo $html;
			} else {
				echo "Error: No paymentmethods found for your ICEPAY account. Please contact ICEPAY.";
				die();
			}
		} catch (Exception $e) {
			echo "Error: " . $e->getMessage();
		}

		die();
	}

	public function process() {

		$this->init();

		if (!isset($this->session->data['order_id'])) {
			$this->response->redirect($this->url->link('common/home'));
		}

		if (!isset($this->request->post['ic_issuer'])) {
			$this->response->redirect($this->url->link('common/home'));
		}

		$order_info = $this->model_checkout_order->getOrder($this->session->data['order_id']);
		$url = $this->model_extension_payment_icepay_basic->getUrl($order_info, $this->request->post['ic_issuer']);

		if (!$url) {
			$this->showErrorPage($_SESSION['ICEPAY_ERROR']);
		} else {
			return header("Location:" . $url);
		}
	}

	public function index() {
		$this->load->model('extension/payment/icepay_basic');

		$payment_method_name = $this->model_extension_payment_icepay_basic->getPaymentMethodName($this->pm_code);
		$issuers = $this->model_extension_payment_icepay_basic->getIssuers($this->pm_code);

		$data['action'] = $this->url->link('extension/payment/icepay_basic/process', '', true);
		$data['displayname'] = $payment_method_name;
		$data['issuers'] = $issuers;
		$data['button_confirm'] = $this->language->get('button_confirm');

		return $this->load->view('extension/payment/icepay_basic', $data);
	}

	public function result() {
		$this->init();

		// Postback or Result
		if ($_SERVER['REQUEST_METHOD'] === 'POST') {
			try {
				$api = $this->model_extension_payment_icepay_basic->loadPostback();
			} catch (Exception $e) {
				$this->response->addHeader('HTTP/1.1 400 Bad Request');
				$this->response->setOutput("Failed to load postback");
			}

			if ($api->validate()) {
				$icepay_info = $this->model_extension_payment_icepay_basic->getIcepayOrderById($api->getOrderID());

				if ($icepay_info["status"] === "NEW" || $api->canUpdateStatus($icepay_info["status"])) {
					$postback = $api->getPostback();
					$this->model_extension_payment_icepay_basic->updateStatus($api->getOrderID(), $api->getStatus(), $postback->transactionID);
					$this->model_checkout_order->addOrderHistory($api->getOrderID(), $this->model_extension_payment_icepay_basic->getOpenCartStatus($api->getStatus()), $api->getStatus());
				}
			} else {
				$this->response->addHeader('HTTP/1.1 400 Bad Request');
				$this->response->setOutput('Server response validation failed');
			}
		} else {
			//Result
			$api = $this->model_extension_payment_icepay_basic->loadResult();

			if (!$api->validate()) {
				$this->showErrorPage("Server response validation failed");

				return;
			}

			if ($api->getStatus() === Icepay_StatusCode::ERROR) {
				$this->showErrorPage($api->getStatus(true));

				return;
			}

			$icepay_info = $this->model_extension_payment_icepay_basic->getIcepayOrderById($api->getOrderID());

			if ($icepay_info["status"] === "NEW" || $api->getStatus() !== $icepay_info["status"]) {
				//we haven't received Postback Notification yet or status changed
				$this->model_checkout_order->addOrderHistory($api->getOrderID(), $this->model_extension_payment_icepay_basic->getOpenCartStatus($api->getStatus()), $api->getStatus());
				$this->response->redirect($this->url->link('checkout/success', '', 'SSL'));
			} else if ($icepay_info["status"] === Icepay_StatusCode::SUCCESS || $icepay_info["status"] === Icepay_StatusCode::OPEN || $icepay_info["status"] === Icepay_StatusCode::VALIDATE) {
				//we've received Postback Notification before processing this request (Result)
				$this->response->redirect($this->url->link('checkout/success', '', 'SSL'));
			}

			$this->showErrorPage($api->getStatus(true));

			return;

		}
	}
}
