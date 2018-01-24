<?php

/**
 * @package           ICEPAY Payment Module for OpenCart
 * @author            Ricardo Jacobs <ricardo.jacobs@icepay.com>
 * @copyright     (c) 2016-2018 ICEPAY. All rights reserved.
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
