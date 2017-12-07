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
    private $_version = "2.2.1";

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
        $orderStatusses = array(
            'payment_icepay_refund_status_id' => 11,
            'payment_icepay_cback_status_id' => 13,
            'payment_icepay_err_status_id' => 10,
            'payment_icepay_ok_status_id' => 2,
            'payment_icepay_open_status_id' => 1
        );

        $this->load->model('setting/setting');
        $this->model_setting_setting->editSetting('icepay_basic', $orderStatusses);

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

    public function index() {
		$data = array();

        // Ajax session to prevent ajax calls from the outside
        $this->session->data['ajax_ok'] = true;

        // Load language files
        $this->load->language('extension/payment/icepay_basic');

        // Set html title
        $this->document->setTitle($this->language->get('heading_title'));

        // Load models
        $this->load->model('setting/setting');
        $this->load->model('setting/store');
        $this->load->model('localisation/geo_zone');

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

        $data["text_version"] = $this->_version;

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

        $baseURL = defined('HTTPS_CATALOG') ? HTTPS_CATALOG : HTTP_CATALOG;

        // Fetch stored paymentmethods
        $storedPaymentMethods = $this->db->query("SELECT * FROM `{$this->getTableWithPrefix('icepay_pminfo')}`");
        $data['storedPaymentMethods'] = $storedPaymentMethods;

        // Fetch Geo Zones
        $data['geo_zones'] = $this->model_localisation_geo_zone->getGeoZones();

        // Fetch stores
        $data['stores'] = $this->model_setting_store->getStores();

        $data["icepay_url"] = $baseURL . 'index.php?route=extension/payment/icepay_basic/result';
        $data['icepay_ajax_get'] = $baseURL . 'index.php?route=extension/payment/icepay_basic/getMyPaymentMethods';
        $data['icepay_ajax_save'] = $baseURL . 'index.php?route=extension/payment/icepay_basic/saveMyPaymentMethods';

        $this->load->model('localisation/order_status');
        $data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();

		$data['header'] = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer'] = $this->load->controller('common/footer');

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

    private function getTableWithPrefix($tableName) {
        return DB_PREFIX . $tableName;
    }
}
