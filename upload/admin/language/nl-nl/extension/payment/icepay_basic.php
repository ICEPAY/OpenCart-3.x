<?php

/**
 * @package       ICEPAY Payment Module for OpenCart
 * @author        Ricardo Jacobs <ricardo.jacobs@icepay.com>
 * @copyright     (c) 2016-2018 ICEPAY. All rights reserved.
 * @license       BSD 2 License, see https://github.com/icepay/OpenCart/blob/master/LICENSE
 */

// Heading
$_['heading_title'] = 'ICEPAY';

// Tabs
$_['tab_general'] = 'Merchant instellingen';
$_['tab_statuscodes'] = 'Status Codes';
$_['tab_paymentmethods'] = 'Betaal Methodes';
$_['tab_about'] = 'Informatie';

// Text
$_['text_extension'] = 'Extensions';
$_['text_success'] = 'Voltooid: De ICEPAY module instellingen zijn aangepast!';
$_['text_icepay_basic'] = '<img src="view/image/payment/icepay_basic.png" alt="ICEPAY" title="ICEPAY" />';

//About Tab
$_['text_about_logo'] = '<a onclick="window.open(\'http://www.icepay.com/webshop-modules/ideal-voor-opencart/\');"><img src="view/image/payment/icepay-logo.png" alt="ICEPAY" title="ICEPAY" border="0"/></a>';
$_['text_about_link'] = '<a onclick="window.open(\'http://www.icepay.com/\');">www.icepay.com</a>';
$_['text_about_support'] = 'Offici&euml;le ICEPAY module';
$_['text_about_user_manual_link'] = '<a onclick="window.open(\'https://github.com/ICEPAY/OpenCart-3.x/wiki\');">Handleiding (Engels)</a>';
$_['text_about_support_link'] = '<a onclick="window.open(\'https://github.com/ICEPAY/OpenCart-3.x/issues\');">report an issue</a>';


// Entry
$_['entry_url'] = 'URL voor Succes/Error/Postback:';
$_['entry_merchantid'] = 'Merchant ID:';
$_['entry_secretcode'] = 'SecretCode:';
$_['entry_geo_zone'] = 'Geo Zone:';
$_['entry_status'] = 'Status:';
$_['entry_sort_order'] = 'Sorteervolgorde:';
$_['entry_debug'] = 'Debug Logging';

// Status
$_['entry_new_status'] = 'New Order Status:<br/><span class="help">Geadviseerd: Pending</span>';
$_['entry_open_status'] = 'In afwachting van betaling status:<br/><span class="help">Geadviseerd: Pending</span>';
$_['entry_ok_status'] = 'Betaling ontvangen status:<br/><span class="help">Geadviseerd: Processing</span>';
$_['entry_err_status'] = 'Fout bij betaling status:<br/><span class="help">Geadviseerd: Failed</span>';
$_['entry_cback_status'] = 'Chargeback status:<br/><span class="help">Geadviseerd: Chargeback</span>';
$_['entry_refund_status'] = 'Refund status:<br/><span class="help">Geadviseerd: Refunded</span>';

// Error
$_['error_permission'] = 'You do not have permission to access this page.';
$_['error_merchantid'] = 'ICEPAY Merchant ID is vereist!';
$_['error_merchantid_incorrect'] = 'ICEPAY Merchant ID dient 5 getallen te bevatten!';
$_['error_secretcode'] = 'ICEPAY SecretCode is vereist!';
$_['error_secretcode_incorrect'] = 'ICEPAY Merchant ID dient exact 40 karakters lang te zijn.';
$_['error_no_api_settings'] = 'Error: No merchant ID and/or secret code found.';

// Help
$_['help_debug'] = 'Enabling debug will write sensitive data to a log file. You should always disable unless instructed otherwise';
