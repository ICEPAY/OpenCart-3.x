<?php

/**
 * @package       ICEPAY Payment Module for OpenCart
 * @author        Ricardo Jacobs <ricardo.jacobs@icepay.com>
 * @copyright     (c) 2017 ICEPAY. All rights reserved.
 * @license       BSD 2 License, see https://github.com/icepay/OpenCart/blob/master/LICENSE
 */

class ControllerExtensionPaymentIcepayBasic extends Controller
{
	private $error = array();
	private $version = "3.0.0";
	protected $api;

	public function install() {
		// Create order table
		$this->db->query("
			CREATE TABLE IF NOT EXISTS `{$this->getTableWithPrefix('icepay_orders')}` (
			  `order_id` int(11) NOT NULL,
			  `transaction_id` int(11) NOT NULL,
			  `status` varchar(11) NOT NULL DEFAULT 'NEW',
			  `order_data` text NOT NULL,
			  `created` datetime NOT NULL,
			   `last_update` datetime NOT NULL,
			  UNIQUE KEY `icepay_order_id` (`order_id`),
			  KEY `order_id` (`order_id`,`transaction_id`)
			)"
		);

		// Create the rawpmdata table
		$this->db->query("
			CREATE TABLE IF NOT EXISTS `{$this->getTableWithPrefix('icepay_rawpmdata')}` (
				`raw_pm_data` LONGTEXT
			)"
		);

		// Create the paymentmethod table
		$this->db->query("
			CREATE TABLE IF NOT EXISTS `{$this->getTableWithPrefix('icepay_pminfo')}` (
				id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
				store_id INT NOT NULL,
				active INT DEFAULT 0,
				displayname VARCHAR(100),
				readablename VARCHAR(100),
				pm_code VARCHAR(25),
				geo_zone_id VARCHAR(255)
			)"
		);

		// Order statusses (Assuming default shop)
		$order_statusses = array(
			'payment_icepay_basic_refund_status_id' => 11,
			'payment_icepay_basic_cback_status_id' => 13,
			'payment_icepay_basic_err_status_id' => 10,
			'payment_icepay_basic_ok_status_id' => 2,
			'payment_icepay_basic_open_status_id' => 1
		);

		$this->load->model('setting/setting');
		$this->model_setting_setting->editSetting('icepay_basic', $order_statusses);

		for ($i = 1; $i < 14; $i++) {
			$this->model_setting_setting->editSetting("payment_icepay_pm_{$i}", array("payment_icepay_pm_{$i}_status" => 1));
		}
	}

	public function uninstall() {
		// Remove the raw payment method data table
		$this->db->query("DROP TABLE IF EXISTS `{$this->getTableWithPrefix('icepay_rawpmdata')}`");

		// Remove the payment method table
		$this->db->query("DROP TABLE IF EXISTS `{$this->getTableWithPrefix('icepay_pminfo')}`");

		// Note: icepay_orders shouldn't be deleted incase the extension gets reinstalled again. This to prevent old orders not being updated.
		//       also, requesting invoices and order pages will not work anymore. You should leave icepay_orders installed.
	}

	private function init() {

		// Load models
		$this->load->model('setting/setting');
		$this->load->model('setting/store');
		$this->load->model('localisation/geo_zone');
		$this->load->model('localisation/order_status');
		$this->load->model('extension/payment/icepay_basic');

		// Load language files
		$this->load->language('extension/payment/icepay_basic');
	}

	public function index() {
		$data = array();

		$this->init();

		// Ajax session to prevent ajax calls from the outside
		$this->session->data['ajax_ok'] = true;

		// Set html title
		$this->document->setTitle($this->language->get('heading_title'));

		// Generate Breadcrumbs
		$this->generateBreadcrumbs($data);

		// Insert translated language keys into data
		foreach ($this->getIcepayLanguageKeys() as $lang) {
			$data[$lang] = $this->language->get($lang);
		}

		if (($this->request->server['REQUEST_METHOD'] == 'POST') && $this->validate()) {
			$this->model_setting_setting->editSetting('payment_icepay_basic', $this->request->post);
			$this->session->data['success'] = $this->language->get('text_success');

			$this->response->redirect($this->url->link('extension/payment/icepay_basic', 'user_token=' . $this->session->data['user_token'], true));
		}

		$data["text_version"] = $this->version;

		if (!empty($this->error)) {
			$data['error_warning'] = array_shift($this->error);
		}

		$data['action'] = $this->url->link('extension/payment/icepay_basic', 'user_token=' . $this->session->data['user_token'], true);
		$data['cancel'] = $this->url->link('extension/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment', true);

		$settings = array(
			"payment_icepay_basic_merchantid",
			"payment_icepay_basic_secretcode",
			"payment_icepay_basic_status",
			"payment_icepay_basic_sort_order",
			"payment_icepay_basic_debug",
			"payment_icepay_basic_new_status_id",
			"payment_icepay_basic_open_status_id",
			"payment_icepay_basic_ok_status_id",
			"payment_icepay_basic_err_status_id",
			"payment_icepay_basic_cback_status_id",
			"payment_icepay_basic_refund_status_id"
		);

		foreach ($settings as $setting) {
			$data[$setting] = (isset($this->request->post[$setting])) ? $this->request->post[$setting] : $this->config->get($setting);
		}

		$base_url = defined('HTTPS_CATALOG') ? HTTPS_CATALOG : HTTP_CATALOG;

		// Fetch stored paymentmethods
		$stored_payment_methods = $this->db->query("SELECT * FROM `{$this->getTableWithPrefix('icepay_pminfo')}`");
		$data['stored_payment_methods'] = $stored_payment_methods;

		// Fetch Geo Zones
		$data['geo_zones'] = $this->model_localisation_geo_zone->getGeoZones();

		// Fetch stores
		$data['stores'] = $this->model_setting_store->getStores();

		$data["icepay_url"] = $base_url . 'index.php?route=extension/payment/icepay_basic/result';
//		$data['icepay_ajax_get'] = $this->url->link('extension/payment/icepay_basic/getMyPaymentMethods', 'user_token=' . $this->session->data['user_token'], true);
//		$data['icepay_ajax_save'] = $this->url->link('extension/payment/icepay_basic/saveMyPaymentMethods', 'user_token=' . $this->session->data['user_token'], true);

		$data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();

		$data['header'] = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer'] = $this->load->controller('common/footer');

		$data['user_token'] = $this->session->data['user_token'];

		$this->response->setOutput($this->load->view('extension/payment/icepay_basic', $data));
	}

	private function validate() {
		if (!$this->user->hasPermission('modify', 'extension/payment/icepay_basic'))
			$this->error['warning'] = $this->language->get('error_permission');

		if (!$this->request->post['payment_icepay_basic_merchantid']) {
			$this->error['merchantid'] = $this->language->get('error_merchantid');
		} else {
			if (strlen($this->request->post['payment_icepay_basic_merchantid']) != 5) {
				$this->error['merchantid'] = $this->language->get('error_merchantid_incorrect');
			}
		}

		if (!$this->request->post['payment_icepay_basic_secretcode']) {
			$this->error['secretcode'] = $this->language->get('error_secretcode');
		} else {
			if (strlen($this->request->post['payment_icepay_basic_secretcode']) != 40) {
				$this->error['secretcode'] = $this->language->get('error_secretcode_incorrect');
			}
		}

		if ($this->error)
			return false;

		return true;
	}

	private function generateBreadcrumbs(&$data) {
		$data['breadcrumbs'] = array();

		$data['breadcrumbs'][] = array(
			'text' => $this->language->get('text_home'),
			'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'], true)
		);

		$data['breadcrumbs'][] = array(
			'text' => $this->language->get('text_extension'),
			'href' => $this->url->link('extension/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment', true)
		);

		$data['breadcrumbs'][] = array(
			'text' => $this->language->get('heading_title'),
			'href' => $this->url->link('extension/payment/icepay_basic', 'user_token=' . $this->session->data['user_token'], true)
		);

	}

	private function getIcepayLanguageKeys() {
		$keys = array(
			"heading_title",
			"entry_url",
			"entry_merchantid",
			"entry_secretcode",
			"entry_geo_zone",
			"entry_status",
			"entry_sort_order",
			"entry_debug",
			"entry_new_status",
			"entry_open_status",
			"entry_ok_status",
			"entry_err_status",
			"entry_cback_status",
			"entry_refund_status",
			"entry_checkout_title",
			"entry_checkout_icon",
			"text_yes",
			"text_no",
			"text_enabled",
			"text_disabled",
			"text_about_logo",
			"text_about_link",
			"text_about_support",
			"text_about_support_link",
			"text_about_user_manual_link",
			"button_save",
			"button_cancel",
			"tab_general",
			"tab_statuscodes",
			"tab_paymentmethods",
			"tab_about",
			"help_debug",
		);

		return $keys;
	}

	private function getTableWithPrefix($table_name) {
		return DB_PREFIX . $table_name;
	}

	public function saveMyPaymentMethods() {
		if (!$this->session->data['ajax_ok']) {
			return;
		}

		$this->init();

		$params = array();
		parse_str($_POST['content'], $params);

		$params_clean = array();
		foreach ($params as $key => $param) {
			$key = str_replace('amp;', '', $key);
			$params_clean[$key] = $param;
		}

		if(!is_array($param) || !array_key_exists('paymentMethodCode',$params))
		{
			echo "Error: no payment methods to save";
			die();
		}
		// Delete old pminfo
		$this->db->query("TRUNCATE TABLE `{$this->model_extension_payment_icepay_basic->getTableWithPrefix('icepay_pminfo')}`");

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

		if (class_exists('SoapClient') === false) {
			echo "Error: SOAP extension for PHP must be enabled. Please contact your webhoster!";
			die();
		}



		try {

			$this->api = $this->model_extension_payment_icepay_basic->loadPaymentMethodService();
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

}
