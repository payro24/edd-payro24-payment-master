<?php
/**
 * Plugin Name: payro24 for Easy Digital Downloads (EDD)
 * Author: payro24
 * Description: <a href="https://payro24.ir">payro24</a> secure payment gateway for Easy Digital Downloads (EDD)
 * Version: 2.1.2
 * Author URI: https://payro24.ir
 * Author Email: info@payro24.ir
 *
 * Text Domain: payro24-for-edd
 * Domain Path: languages
 */

if (!class_exists('EDD_payro24_Gateway')) exit;

new EDD_payro24_Gateway;

class EDD_payro24_Gateway
{
  /**
   * @var string
   */
  public $keyname;

  /**
   * EDD_payro24_Gateway constructor.
   */
  public function __construct()
  {
    $this->keyname = 'payro24';
    add_filter('edd_payment_gateways', array($this, 'add'));
    add_action($this->format('edd_{key}_cc_form'), array($this, 'cc_form'));
    add_action($this->format('edd_gateway_{key}'), array($this, 'process'));
    add_action($this->format('edd_verify_{key}'), array($this, 'verify'));
    add_filter('edd_settings_gateways', array($this, 'settings'));
    add_action( 'edd_payment_receipt_after', array( $this, 'receipt' ) );
    add_action('init', array($this, 'listen'));
  }

  /**
   * @param $gateways
   * @return mixed
   */
  public function add($gateways)
  {
    if ( ! isset( $_SESSION ) ) {
      session_start();
    }
    $gateways[$this->keyname] = array(
      'admin_label' => __('payro24', 'payro24-for-edd'),
      'checkout_label' => __('payro24 payment gateway', 'payro24-for-edd'),
    );

    return $gateways;
  }

  /**
   *
   */
  public function cc_form()
  {
    return;
  }

  /**
   * @param $purchase_data
   * @return bool
   */
  public function process($purchase_data)
  {
    global $edd_options;
    //create payment
    $payment_id = $this->insert_payment($purchase_data);
    if ($payment_id) {
      $api_key = empty($edd_options['payro24_api_key']) ? '' : $edd_options['payro24_api_key'];
      $sandbox = empty($edd_options['payro24_sandbox']) ? '' : $edd_options['payro24_sandbox'];
      $customer_name = $purchase_data['user_info']['first_name'] . ' ' . $purchase_data['user_info']['last_name'];
      $desc = "description(payment id is $payment_id)";
      $callback = add_query_arg(array('verify_' . $this->keyname => '1', 'payment_key' => urlencode($purchase_data['purchase_key'])), get_permalink($edd_options['success_page']));
      $email = $purchase_data['user_info']['email'];
      $amount = $this->payro24_edd_get_amount(intval($purchase_data['price']), edd_get_currency());

      if (empty($amount)) {
        $message = __('Selected currency is not supported.', 'payro24-for-edd');
        edd_insert_payment_note($payment_id, $message);
        edd_update_payment_status($payment_id, 'failed');
        edd_set_error('payro24_connect_error', $message);
        edd_send_back_to_checkout();

        return FALSE;
      }

      $data = array(
        'order_id' => $payment_id,
        'amount' => $amount,
        'name' => $customer_name,
        'phone' => '',
        'mail' => $email,
        'desc' => $desc,
        'callback' => $callback,
      );

      $headers = array(
        'Content-Type' => 'application/json',
        'P-TOKEN' => $api_key,
        'P-SANDBOX' => $sandbox,
      );
      $args = array(
        'body' => json_encode($data),
        'headers' => $headers,
        'timeout' => 15,
      );

      $response = $this->payro24_edd_call_gateway_endpoint('https://api.payro24.ir/v1.1/payment', $args);
      if (is_wp_error($response)) {
        $note = $response->get_error_message();
        edd_insert_payment_note($payment_id, $note);

        return FALSE;
      }

      $http_status = wp_remote_retrieve_response_code($response);
      $result = wp_remote_retrieve_body($response);
      $result = json_decode($result);

      if ($http_status != 201 || empty($result) || empty($result->link)) {
        $message = $result->error_message;
        edd_insert_payment_note($payment_id, $http_status . ' - ' . $message);
        edd_update_payment_status($payment_id, 'failed');
        edd_set_error('payro24_connect_error', $message);
        edd_send_back_to_checkout();

        return FALSE;
      }

      // Saves transaction id and link
      edd_insert_payment_note($payment_id, __('Transaction ID: ', 'payro24-for-edd') . $result->id);
      edd_insert_payment_note($payment_id, __('Redirecting to the payment gateway.', 'payro24-for-edd'));

      edd_update_payment_meta($payment_id, '_payro24_edd_transaction_id', $result->id);
      edd_update_payment_meta($payment_id, '_payro24_edd_transaction_link', $result->link);

      wp_redirect($result->link);

    } else {
      $message = $this->payro24_other_status_messages();
      edd_set_error('payro24_connect_error', $message);
      edd_send_back_to_checkout('?payment-mode=' . $purchase_data['post_data']['edd-gateway']);
    }

  }

  /**
   * Verify the payment
   * @return bool
   */
  public function verify()
  {
    global $edd_options;

    // Check method post or get
    $method = $_SERVER['REQUEST_METHOD'];
    if ($method == 'POST') {
      $status = sanitize_text_field($_POST['status']);
      $track_id = sanitize_text_field($_POST['track_id']);
      $id = sanitize_text_field($_POST['id']);
      $order_id = sanitize_text_field($_POST['order_id']);
    }
    elseif ($method == 'GET') {
      $status = sanitize_text_field($_GET['status']);
      $track_id = sanitize_text_field($_GET['track_id']);
      $id = sanitize_text_field($_GET['id']);
      $order_id = sanitize_text_field($_GET['order_id']);
    }

    if (empty($id) || empty($order_id)) {
      wp_die(__('The information sent is not correct.', 'payro24-for-edd'));
      return FALSE;
    }

    $payment = edd_get_payment($order_id);
    if (!$payment) {
      wp_die(__('The information sent is not correct.', 'payro24-for-edd'));
      return FALSE;
    }

    if ($payment->status != 'pending') {
      edd_send_back_to_checkout();
      return FALSE;
    }

    if ($status != 10) {
      edd_insert_payment_note($order_id, $status . ' - ' . $this->payro24_other_status_messages($status));
      edd_insert_payment_note($order_id, __('payro24 tracking id: ', 'payro24-for-edd') . $track_id);
      edd_update_payment_status($order_id, 'failed');
      edd_set_error('payro24_connect_error', $this->payro24_other_status_messages($status));
      edd_send_back_to_checkout();

      return false;
    } elseif ($status = 10) {

      $api_key = empty($edd_options['payro24_api_key']) ? '' : $edd_options['payro24_api_key'];
      $sandbox = empty($edd_options['payro24_sandbox']) ? 'false' : 'true';

      $data = array(
        'id' => $id,
        'order_id' => $order_id,
      );

      $headers = array(
        'Content-Type' => 'application/json',
        'P-TOKEN' => $api_key,
        'P-SANDBOX' => $sandbox,
      );

      $args = array(
        'body' => json_encode($data),
        'headers' => $headers,
        'timeout' => 15,
      );

      $response = $this->payro24_edd_call_gateway_endpoint('https://api.payro24.ir/v1.1/payment/verify', $args);

      if (is_wp_error($response)) {
        $note = $response->get_error_message();
        edd_insert_payment_note($payment->ID, $note);

        return FALSE;
      }
      $http_status = wp_remote_retrieve_response_code($response);
      $result = wp_remote_retrieve_body($response);
      $result = json_decode($result);

      if ($http_status != 200) {
        $message = $result->error_message;
        edd_insert_payment_note($payment->ID, $http_status . ' - ' . $message);
        edd_update_payment_status($payment->ID, 'failed');
        edd_set_error('payro24_connect_error', $message);
        edd_send_back_to_checkout();

        return FALSE;
      }

      $verify_status = empty($result->status) ? NULL : $result->status;
      $verify_track_id = empty($result->track_id) ? NULL : $result->track_id;
      $verify_id = empty($result->id) ? NULL : $result->id;
      $verify_order_id = empty($result->order_id) ? NULL : $result->order_id;
      $verify_amount = empty($result->amount) ? NULL : $result->amount;
      $verify_card_no = empty($result->payment->card_no) ? NULL : $result->payment->card_no;
      $verify_hashed_card_no = empty($result->payment->hashed_card_no) ? NULL : $result->payment->hashed_card_no;
      $verify_date = empty($result->payment->date) ? NULL : $result->payment->date;

      update_post_meta($payment->ID, 'payro24_transaction_status', $verify_status);
      update_post_meta($payment->ID, 'payro24_track_id', $verify_track_id);
      update_post_meta($payment->ID, 'payro24_transaction_id', $verify_id);
      update_post_meta($payment->ID, 'payro24_transaction_order_id', $verify_order_id);
      update_post_meta($payment->ID, 'payro24_payment_hashed_card_no', $verify_hashed_card_no);
      update_post_meta($payment->ID, 'payro24_transaction_amount', $verify_amount);
      update_post_meta($payment->ID, 'payro24_payment_card_no', $verify_card_no);
      update_post_meta($payment->ID, 'payro24_payment_date', $verify_date);

      edd_insert_payment_note($payment->ID, __('payro24 tracking id: ', 'payro24-for-edd') . $verify_track_id);
      edd_insert_payment_note($payment->ID, __('Payer card number: ', 'payro24-for-edd') . $verify_card_no);
      edd_insert_payment_note($payment->ID, __('Payer card hash number: ', 'payro24-for-edd') . $verify_hashed_card_no);

      //check Double Spending
      if ($this->payro24_edd_double_spending_occurred($payment->ID, $result->id)) {
        $message = $this->payro24_other_status_messages(0);
        edd_insert_payment_note($payment->ID, $message);
        edd_update_payment_status($payment->ID, 'failed');
        edd_set_error('payro24_connect_error', $message);
        edd_send_back_to_checkout();

        return FALSE;
      }

      if (empty($verify_status) || empty($verify_track_id) || empty($verify_amount)) {
        $message = $this->payro24_other_status_messages();
        edd_insert_payment_note($payment->ID, $message);
        edd_update_payment_status($payment->ID, 'failed');
        edd_set_error('payro24_connect_error', $message);
        edd_send_back_to_checkout();

        return FALSE;
      }

      if ($result->status >= 100) {
        $session = edd_get_purchase_session();
        if (!$session) {
          edd_set_purchase_session(['purchase_key' => urldecode($_GET['payment_key'])]);
          $session = edd_get_purchase_session();
        }

        edd_empty_cart();
        edd_update_payment_status($payment->ID, 'publish');
        edd_insert_payment_note($payment->ID, $status . ' - ' . $this->payro24_other_status_messages($status));
        edd_send_to_success_page();
      } else {
        $message = $this->payro24_other_status_messages();
        edd_insert_payment_note($payment->ID, $message);
        edd_set_error('payro24_connect_error', $message);
        edd_update_payment_status($payment->ID, 'failed');
        edd_send_back_to_checkout();

        return FALSE;
      }
    }
  }

  /**
   * Receipt field for payment
   *
   * @param 				object $payment
   * @return 				void
   */
  public function receipt( $payment ) {
    $track_id = edd_get_payment_meta( $payment->ID, 'payro24_track_id' );
    if ( $track_id ) {
      echo '<tr><td><strong>شماره تراکنش بانکی:</strong></td><td>' . $track_id . '</td></tr>';
    }
  }

  /**
   * Gateway settings
   *
   * @param array $settings
   * @return        array
   */
  public function settings($settings)
  {
    return array_merge($settings, array(
      $this->keyname . '_header' => array(
        'id' => $this->keyname . '_header',
        'type' => 'header',
        'name' => __('payro24 payment gateway', 'payro24-for-edd'),
      ),
      $this->keyname . '_api_key' => array(
        'id' => $this->keyname . '_api_key',
        'name' => 'API Key',
        'type' => 'text',
        'size' => 'regular',
        'desc' => __('You can create an API Key by going to your <a href="https://payro24.ir/dashboard/web-services">payro24 account</a>.', 'payro24-for-edd'),
      ),
      $this->keyname . '_sandbox' => array(
        'id' => $this->keyname . '_sandbox',
        'name' => __('Sandbox', 'payro24-for-edd'),
        'type' => 'checkbox',
        'default' => 0,
        'desc' => __('If you check this option, the gateway will work in Test (Sandbox) mode.', 'payro24-for-edd'),
      ),
    ));
  }

  /**
   * Format a string, replaces {key} with $keyname
   *
   * @param string $string To format
   * @return      string Formatted
   */
  private function format($string)
  {
    return str_replace('{key}', $this->keyname, $string);
  }

  /**
   * Inserts a payment into database
   *
   * @param array $purchase_data
   * @return      int $payment_id
   */
  private function insert_payment($purchase_data)
  {
    global $edd_options;

    $payment_data = array(
      'price' => $purchase_data['price'],
      'date' => $purchase_data['date'],
      'user_email' => $purchase_data['user_email'],
      'purchase_key' => $purchase_data['purchase_key'],
      'currency' => $edd_options['currency'],
      'downloads' => $purchase_data['downloads'],
      'user_info' => $purchase_data['user_info'],
      'cart_details' => $purchase_data['cart_details'],
      'status' => 'pending'
    );

    // record the pending payment
    $payment = edd_insert_payment($payment_data);

    return $payment;
  }

  /**
   * Listen to incoming queries
   *
   * @return      void
   */
  public function listen()
  {
    if (isset($_GET['verify_' . $this->keyname]) && $_GET['verify_' . $this->keyname]) {
      do_action('edd_verify_' . $this->keyname);
    }
  }

  /**
   * @param $url
   * @param $args
   * @return array|WP_Error
   */
  public function payro24_edd_call_gateway_endpoint($url, $args)
  {
    $number_of_connection_tries = 4;
    while ($number_of_connection_tries) {
      $response = wp_safe_remote_post($url, $args);
      if (is_wp_error($response)) {
        $number_of_connection_tries--;
        continue;
      } else {
        break;
      }
    }
    return $response;
  }

  /**
   * @param $payment_id
   * @param $remote_id
   * @return bool
   */
  public function payro24_edd_double_spending_occurred($payment_id, $remote_id)
  {
    if (get_post_meta($payment_id, '_payro24_edd_transaction_id', TRUE) != $remote_id) {
      return TRUE;
    }

    return FALSE;
  }

  /**
   * @param $amount
   * @param $currency
   * @return float|int
   */
  public function payro24_edd_get_amount($amount, $currency)
  {
    switch (strtolower($currency)) {
      case strtolower('IRR'):
      case strtolower('RIAL'):
        return $amount;

      case strtolower('تومان ایران'):
      case strtolower('تومان'):
      case strtolower('IRT'):
      case strtolower('Iranian_TOMAN'):
      case strtolower('Iran_TOMAN'):
      case strtolower('Iranian-TOMAN'):
      case strtolower('Iran-TOMAN'):
      case strtolower('TOMAN'):
      case strtolower('Iran TOMAN'):
      case strtolower('Iranian TOMAN'):
        return $amount * 10;

      case strtolower('IRHT'):
        return $amount * 10000;

      case strtolower('IRHR'):
        return $amount * 1000;

      default:
        return 0;
    }
  }

  /**
   * @param null $status
   * @return string
   */
  public function payro24_other_status_messages($status = null)
  {
    switch ($status) {
      case "1":
        $msg = __("Payment has not been made. code:", 'payro24-for-edd');
        break;
      case "2":
        $msg = __("Payment has failed. code:", 'payro24-for-edd');
        break;
      case "3":
        $msg = __("An error has occurred. code:", 'payro24-for-edd');
        break;
      case "4":
        $msg = __("Blocked. code:", 'payro24-for-edd');
        break;
      case "5":
        $msg = __("Return to payer. code:", 'payro24-for-edd');
        break;
      case "6":
        $msg = __("Systematic return. code:", 'payro24-for-edd');
        break;
      case "7":
        $msg = __("Cancel payment. code:", 'payro24-for-edd');
        break;
      case "8":
        $msg = __("It was transferred to the payment gateway. code:", 'payro24-for-edd');
        break;
      case "10":
        $msg = __("Waiting for payment confirmation. code:", 'payro24-for-edd');
        break;
      case "100":
        $msg = __("Payment has been confirmed. code:", 'payro24-for-edd');
        break;
      case "101":
        $msg = __("Payment has already been confirmed. code:", 'payro24-for-edd');
        break;
      case "200":
        $msg = __("Deposited to the recipient. code:", 'payro24-for-edd');
        break;
      case "0":
        $msg = __("Abuse of previous transactions. code:", 'payro24-for-edd');
        break;
      case null:
        $msg = __("Unexpected error. code:", 'payro24-for-edd');
        $status = '1000';
        break;
    }
    $msg = sprintf("$msg %s", $status);

    return $msg;
  }

}
