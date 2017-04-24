<?php

/**
 * @file Copyright iATS Payments (c) 2014.
 * @author Alan Dixon
 *
 * This file is a part of CiviCRM published extension.
 *
 * This extension is free software; you can copy, modify, and distribute it
 * under the terms of the GNU Affero General Public License
 * Version 3, 19 November 2007.
 *
 * It is distributed in the hope that it will be useful, but
 * WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 * See the GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public
 * License with this program; if not, see http://www.gnu.org/licenses/
 *
 * This code provides glue between CiviCRM payment model and the iATS Payment model encapsulated in the iATS_Service_Request object
 */

/**
 *
 */
class CRM_Core_Payment_iATSService extends CRM_Core_Payment {

  /**
   * We only need one instance of this object. So we use the singleton
   * pattern and cache the instance in this variable.
   *
   * @var object
   * @static
   */
  static private $_singleton = NULL;

  /**
   * Constructor.
   *
   * @param string $mode
   *   the mode of operation: live or test.
   *
   * @param array $paymentProcessor
   */
  public function __construct($mode, &$paymentProcessor, &$paymentForm = NULL, $force = FALSE) {
    $this->_paymentProcessor = $paymentProcessor;
    $this->_processorName = ts('iATS Payments');

    // Get merchant data from config.
    $config = CRM_Core_Config::singleton();
    // Live or test.
    $this->_profile['mode'] = $mode;
    // We only use the domain of the configured url, which is different for NA vs. UK.
    $this->_profile['iats_domain'] = parse_url($this->_paymentProcessor['url_site'], PHP_URL_HOST);
  }

  /**
   *
   */
  static public function &singleton($mode, &$paymentProcessor, &$paymentForm = NULL, $force = FALSE) {
    $processorName = $paymentProcessor['name'];
    if (self::$_singleton[$processorName] === NULL) {
      self::$_singleton[$processorName] = new CRM_Core_Payment_iATSService($mode, $paymentProcessor);
    }
    return self::$_singleton[$processorName];
  }

  /**
   * Override the default way of testing if a method is supported to enable admin configuration of certain
   * functions.
   * Where certain functions currently only means updateSubscriptionBillingInfo.
   *
   * Core says this method is now deprecated, so I might need to change this in the future, but this is how it is used now.
   */
  public function isSupported($method) {
    switch($method) {
      case 'updateSubscriptionBillingInfo':
        if (!CRM_Core_Permission::check('access CiviContribution')) {
          // only enable self-service update of billing info if the admin has allowed it
          $settings = civicrm_api3('Setting', 'getvalue', array('name' => 'iats_settings'));
          if (empty($settings['enable_update_subscription_billing_info'])) {
            return FALSE;
          }
        }
        break;
    }
    return method_exists(CRM_Utils_System::getClassName($this), $method);
  }

  /**
   *
   */
  public function doDirectPayment(&$params) {

    if (!$this->_profile) {
      return self::error('Unexpected error, missing profile');
    }
    // Use the iATSService object for interacting with iATS. Recurring contributions go through a more complex process.
    require_once "CRM/iATS/iATSService.php";
    $isRecur = CRM_Utils_Array::value('is_recur', $params) && $params['contributionRecurID'];
    $methodType = $isRecur ? 'customer' : 'process';
    $method = $isRecur ? 'create_credit_card_customer' : 'cc';
    $iats = new iATS_Service_Request(array('type' => $methodType, 'method' => $method, 'iats_domain' => $this->_profile['iats_domain'], 'currencyID' => $params['currencyID']));
    $request = $this->convertParams($params, $method);
    $request['customerIPAddress'] = (function_exists('ip_address') ? ip_address() : $_SERVER['REMOTE_ADDR']);
    $credentials = array(
      'agentCode' => $this->_paymentProcessor['user_name'],
      'password'  => $this->_paymentProcessor['password'],
    );
    // Get the API endpoint URL for the method's transaction mode.
    // TODO: enable override of the default url in the request object
    // $url = $this->_paymentProcessor['url_site'];.
    // Make the soap request.
    $response = $iats->request($credentials, $request);
    if (!$isRecur) {
      // Process the soap response into a readable result, logging any credit card transactions.
      $result = $iats->result($response);
      if ($result['status']) {
        // Success.
        $params['contribution_status_id'] = 1;
        // For versions >= 4.6.6, the proper key.
        $params['payment_status_id'] = 1;
        $params['trxn_id'] = trim($result['remote_id']) . ':' . time();
        $params['gross_amount'] = $params['amount'];
        return $params;
      }
      else {
        return self::error($result['reasonMessage']);
      }
    }
    else {
      // Save the client info in my custom table, then (maybe) run the transaction.
      $customer = $iats->result($response, FALSE);
      // print_r($customer);
      if ($customer['status']) {
        $processresult = $response->PROCESSRESULT;
        $customer_code = (string) $processresult->CUSTOMERCODE;
        $exp = sprintf('%02d%02d', ($params['year'] % 100), $params['month']);
        $email = '';
        if (isset($params['email'])) {
          $email = $params['email'];
        }
        elseif (isset($params['email-5'])) {
          $email = $params['email-5'];
        }
        elseif (isset($params['email-Primary'])) {
          $email = $params['email-Primary'];
        }
        $query_params = array(
          1 => array($customer_code, 'String'),
          2 => array($request['customerIPAddress'], 'String'),
          3 => array($exp, 'String'),
          4 => array($params['contactID'], 'Integer'),
          5 => array($email, 'String'),
          6 => array($params['contributionRecurID'], 'Integer'),
        );
        CRM_Core_DAO::executeQuery("INSERT INTO civicrm_iats_customer_codes
          (customer_code, ip, expiry, cid, email, recur_id) VALUES (%1, %2, %3, %4, %5, %6)", $query_params);
        $settings = CRM_Core_BAO_Setting::getItem('iATS Payments Extension', 'iats_settings');
        $allow_days = empty($settings['days']) ? array('-1') : $settings['days'];
        // Run the transaction immediately.
        if (max($allow_days) <= 0) {
          $iats = new iATS_Service_Request(array('type' => 'process', 'method' => 'cc_with_customer_code', 'iats_domain' => $this->_profile['iats_domain'], 'currencyID' => $params['currencyID']));
          $request = array('invoiceNum' => $params['invoiceID']);
          $request['total'] = sprintf('%01.2f', CRM_Utils_Rule::cleanMoney($params['amount']));
          $request['customerCode'] = $customer_code;
          $request['customerIPAddress'] = (function_exists('ip_address') ? ip_address() : $_SERVER['REMOTE_ADDR']);
          $response = $iats->request($credentials, $request);
          $result = $iats->result($response);
          if ($result['status']) {
            // Success.
            $params['contribution_status_id'] = 1;
            // For versions >= 4.6.6, the proper key.
            $params['payment_status_id'] = 1;
            $params['trxn_id'] = trim($result['remote_id']) . ':' . time();
            $params['gross_amount'] = $params['amount'];
            $params['next_sched_contribution'] = strtotime('+' . $params['frequency_interval'] . ' ' . $params['frequency_unit']);
            return $params;
          }
          else {
            return self::error($result['reasonMessage']);
          }
        }
        // I've got a schedule to adhere to!
        else {
          // Pending.
          $params['contribution_status_id'] = 2;
          // For versions >= 4.6.6, the proper key.
          $params['payment_status_id'] = 2;
          $from_time = _iats_contributionrecur_next(time(), $allow_days);
          $params['next_sched_contribution'] = $params['receive_date'] = date('Ymd', $from_time) . '030000';
          return $params;
        }
        return self::error('Unexpected error');
      }
      else {
        return self::error($customer['reasonMessage']);
      }
    }
  }

  /**
   * support corresponding CiviCRM method
   */
  public function changeSubscriptionAmount(&$message = '', $params = array()) {
    // $userAlert = ts('You have modified this recurring contribution.');
    // CRM_Core_Session::setStatus($userAlert, ts('Warning'), 'alert');
    return TRUE;
  }

  /**
   * support corresponding CiviCRM method
   */
  public function cancelSubscription(&$message = '', $params = array()) {
    $userAlert = ts('You have cancelled this recurring contribution.');
    CRM_Core_Session::setStatus($userAlert, ts('Warning'), 'alert');
    return TRUE;
  }

  /**
   * Set additional fields when editing the schedule.
   *
   * Note: this doesn't completely replace the form hook, which is still 
   * in use for additional changes, and to support 4.6.
   * e.g. the commented out fields below don't work properly here.
   */
  public function getEditableRecurringScheduleFields() {
    return array('amount',
         'installments',
         'next_sched_contribution_date',
//         'contribution_status_id',
//         'start_date',
//         'is_email_receipt',
       );
  }

  /*
   * Set a useful message at the top of the schedule editing form
   */
  public function getRecurringScheduleUpdateHelpText() {
    return 'Use this form to change the amount or number of installments for this recurring contribution. You can not change the contribution frequency.<br />You can also modify the next scheduled contribution date, and whether or not the recipient will get email receipts for each contribution.<br />You have an option to notify the donor of these changes.';
  }

  /**
   *
   */
  public function &error($error = NULL) {
    $e = CRM_Core_Error::singleton();
    if (is_object($error)) {
      $e->push($error->getResponseCode(),
        0, NULL,
        $error->getMessage()
      );
    }
    elseif ($error && is_numeric($error)) {
      $e->push($error,
        0, NULL,
        $this->errorString($error)
      );
    }
    elseif (is_string($error)) {
      $e->push(9002,
        0, NULL,
        $error
      );
    }
    else {
      $e->push(9001, 0, NULL, "Unknown System Error.");
    }
    return $e;
  }

  /**
   * This function checks to see if we have the right config values.
   *
   * @return string
   *   The error message if any
   */
  public function checkConfig() {
    $error = array();

    if (empty($this->_paymentProcessor['user_name'])) {
      $error[] = ts('Agent Code is not set in the Administer CiviCRM &raquo; System Settings &raquo; Payment Processors.');
    }

    if (empty($this->_paymentProcessor['password'])) {
      $error[] = ts('Password is not set in the Administer CiviCRM &raquo; System Settings &raquo; Payment Processors.');
    }

    if (!empty($error)) {
      return implode('<p>', $error);
    }
    else {
      return NULL;
    }
  }

  /**
   * Convert the values in the civicrm params to the request array with keys as expected by iATS.
   *
   * @param array $params
   * @param string $method
   *
   * @return array
   */
  protected function convertParams($params, $method) {
    $request = array();
    $convert = array(
      'firstName' => 'billing_first_name',
      'lastName' => 'billing_last_name',
      'address' => 'street_address',
      'city' => 'city',
      'state' => 'state_province',
      'zipCode' => 'postal_code',
      'country' => 'country',
      'invoiceNum' => 'invoiceID',
      'creditCardNum' => 'credit_card_number',
      'cvv2' => 'cvv2',
    );

    foreach ($convert as $r => $p) {
      if (isset($params[$p])) {
        $request[$r] = htmlspecialchars($params[$p]);
      }
    }
    $request['creditCardExpiry'] = sprintf('%02d/%02d', $params['month'], ($params['year'] % 100));
    $request['total'] = sprintf('%01.2f', CRM_Utils_Rule::cleanMoney($params['amount']));
    // Place for ugly hacks.
    switch ($method) {
      case 'cc_create_customer_code':
        $request['ccNum'] = $request['creditCardNum'];
        unset($request['creditCardNum']);
        $request['ccExp'] = $request['creditCardExpiry'];
        unset($request['creditCardExpiry']);
        break;

      case 'cc_with_customer_code':
        foreach (array('creditCardNum', 'creditCardExpiry', 'mop') as $key) {
          if (isset($request[$key])) {
            unset($request[$key]);
          }
        }
        break;
    }
    if (!empty($params['credit_card_type'])) {
      $mop = array(
        'Visa' => 'VISA',
        'MasterCard' => 'MC',
        'Amex' => 'AMX',
        'Discover' => 'DSC',
      );
      $request['mop'] = $mop[$params['credit_card_type']];
    }
    // print_r($request); print_r($params); die();
    return $request;
  }

  /*
   * Implement the ability to update the billing info for recurring contributions,
   * This functionality will apply to back-end and front-end,
   * so it's only enabled when configured as on via the iATS admin settings.
   * The default isSupported method is overridden above to achieve this.
   */
  public function updateSubscriptionBillingInfo(&$message = '', $params = array()) {
    require_once('CRM/iATS/Form/IATSCustomerUpdateBillingInfo.php');

    $fakeForm = new IATSCustomerUpdateBillingInfo();
    $fakeForm->updatedBillingInfo = $params;
    $fakeForm->postProcess();

    $status = CRM_Core_Session::singleton()->getStatus(TRUE);
    $matches = array();
    preg_match('/\[AUTHORIZATIONRESULT\]\s*?=>\s(\d*)(.*)/', $status[0]['text'], $matches);

    if ($matches[2] == 'OK') {
      return TRUE;
    }
    $message = $matches[2];

    $e = CRM_Core_Error::singleton();
    $e->push($matches[1] ?: 0, 0, array(), $matches[2]);

    return $e;
  }

}
