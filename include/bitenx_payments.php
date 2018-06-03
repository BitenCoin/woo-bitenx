<?php

/**
 * bitenx_payments.php
 *
 * 
 * 
 * 
 * 
 * Reality is construcuted by the consensus between your neurons.
 */


class BitenX_Gateway extends WC_Payment_Gateway {
    private $reloadTime = 30000;
    private $discount;
    private $confirmed = false;
    private $bitenx_daemon;

    function __construct() {
        $this->id = "bitenx_gateway";
        $this->method_title = __("BitenX Gateway", 'bitenx_gateway');
        $this->method_description = __("BitenX Payment Gateway Plug-in for WooCommerce.", 'bitenx_gateway');
        $this->title = __("BitenX Gateway", 'bitenx_gateway');
        $this->version = "0.1";
        $this->icon = apply_filters('woocommerce_offline_icon', '');
        $this->has_fields = false;
        $this->log = new WC_Logger();
        $this->init_form_fields();
        $this->host = $this->get_option('daemon_host');
        $this->port = $this->get_option('daemon_port');
        $this->password = $this->get_option('daemon_password');
        $this->address = $this->get_option('address');
        $this->confirms = $this->get_option('confirms');
        $this->discount = $this->get_option('discount');
        $this->delete_history = $this->get_option('history');        
        $this->init_settings();

        foreach ($this->settings as $setting_key => $value) {
            $this->$setting_key = $value;
        }

        add_action('admin_notices', array($this, 'sslCheck'));
        add_action('admin_notices', array($this, 'validate_fields'));
        add_action('woocommerce_thankyou_' . $this->id, array($this, 'instruction'));
       
        if(is_admin()) {
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
            add_filter('woocommerce_currencies', 'add_my_currency');
            add_filter('woocommerce_currency_symbol', 'add_my_currency_symbol', 10, 2);
            add_action('woocommerce_email_before_order_table', array($this, 'email_instructions'), 10, 2);
        }
        
        $this->bitenx_daemon = new BitenX_Library($this->host, $this->port, $this->password);
    }

    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title' => __('Enable / Disable', 'bitenx_gateway'),
                'label' => __('Enable this BTENX payment gateway. Requires Walletd RCP API access.', 'bitenx_gateway'),
                'type' => 'checkbox',
                'default' => 'no'
            ),
            'title' => array(
                'title' => __('Title', 'bitenx_gateway'),
                'type' => 'text',
                'description' => __('Payment title the customer will see during the checkout process.', 'bitenx_gateway'),
                'default' => __('BitenX (BTENX)', 'bitenx_gateway')
            ),
            'description' => array(
                'title' => __('Description', 'bitenx_gateway'),
                'type' => 'textarea',
                'description' => __('Payment description the customer will see during the checkout process.', 'bitenx_gateway'),
                'default' => __('Pay securely using BTENX.', 'bitenx_gateway')
            ),
            'address' => array(
                'title' => __('Address', 'bitenx_gateway'),
                'description' => __('Enter the BTENX address that will receive customer payments.'),
                'type' => 'text',
                'default' => 'BTENX'
            ),
            'confirms' => array(
                'title' => __('Confirmations', 'bitenx_gateway'),
                'description' => __('Enter the amount of confirmations (blocks) that are needed for the order to be approved. (leave empty if manual approval needed)'),
                'type' => 'text',
                'default' => '20'

            ),
            'daemon_host' => array(
                'title' => __('Walletd RCP API Host', 'bitenx_gateway'),
                'desc_tip' => __('Walletd daemon hostname or IP address.', 'bitenx_gateway'),
                'type' => 'text',

                'default' => 'localhost',
            ),
            'daemon_port' => array(
                'title' => __('Walletd RCP API Port', 'bitenx_gateway'),
                'desc_tip' => __('Walletd ', 'bitenx_gateway'),
                'type' => 'text',
                'default' => '8080',
            ),
            'daemon_password' => array(
                'title' => __('Walletd RCP API Password', 'bitenx_gateway'),
                'desc_tip' => __('Enter your walletd daemon RCP password.', 'bitenx_gateway'),
                'type' => 'password',
                'default' => '',
            ),
            'discount' => array(
                'title' => __('% discount for using BTENX', 'bitenx_gateway'),
                'description' => __('Provide a discount to your customers who pay with BTENX! Leave this empty if you do not wish to provide a discount.', 'bitenx_gateway'),
                'type' => __('text'),
                'default' => '5'

            ),
            'history' => array(
                'title' => __('Delete Payment History ', 'bitenx_gateway'),
                'label' => __('Delete payment ID history.', 'bitenx_gateway'),
                'type' => 'checkbox',
                'description' => __('During the verification process, the transaction is stored in the database, including the pid, hash, amount and conversion. Check this to delete the record of the payment after the payment is finalized. (This will not delete the woocommerce order record)', 'bitenx_gateway'),
                'default' => 'no'
            ),
            'onion_service' => array(
                'title' => __('SSL Warnings', 'bitenx_gateway'),
                'label' => __('Silence SSL Warnings', 'bitenx_gateway'),
                'type' => 'checkbox',
                'description' => __('Check this box if you are running on an Onion Service (Suppress SSL errors)', 'bitenx_gateway'),
                'default' => 'no'
            ),
        );
    }

    public function add_my_currency($currencies) {
        $currencies['BTENX'] = __('bitenx', 'woocommerce');
        return $currencies;
    }

    function add_my_currency_symbol($currency_symbol, $currency) {
        switch ($currency) {
            case 'BTENX':
                $currency_symbol = 'BTENX';
                break;
        }
        return $currency_symbol;
    }

    public function admin_options() {
        $this->log->add('bitenx_gateway', '[SUCCESS] BitenX Settings OK');
        echo "<h1>BitenX Payment Gateway</h1>";
        echo "<p>Welcome to BitenX Extension for WooCommerce. Getting started: Make a connection with a wallet daemon!";
        echo "<div style='border:1px solid #DDD;padding:5px 10px;font-weight:bold;color:#223079;background-color:#9ddff3;'>";
        $this->getBalance();
        echo "</div>";
        echo "<table class='form-table'>";
        $this->generate_settings_html();
        echo "</table>";
    }

    public function getBalance() {
        $wallet_amount = $this->bitenx_daemon->getbalance();

        if (!isset($wallet_amount)) {
            $this->log->add('bitenx_gateway', '[ERROR] Can not connect to RCP host');
            echo "</br>Your balance is: Not Avaliable </br>";
            echo "Unlocked balance: Not Avaliable";
        }
        else {
            $real_wallet_amount = $wallet_amount['availableBalance'] / 100;
            $real_amount_rounded = round($real_wallet_amount, 2);

            $unlocked_wallet_amount = $wallet_amount['lockedAmount'] / 100;
            $unlocked_amount_rounded = round($unlocked_wallet_amount, 2);
        
            echo "Your balance is:  " . $real_amount_rounded . " BTENX </br>";
            echo "Unlocked balance: " . $unlocked_amount_rounded . " BTENX </br>";
        }
    }

    public function process_payment($order_id)
    {
        $order = wc_get_order($order_id);
        $order->update_status('on-hold', __('Awaiting direct payment', 'bitenx_gateway'));
        $order->reduce_order_stock();

        WC()->cart->empty_cart();

        return array(
            'result' => 'success',
            //'redirect' => add_query_arg('key', $order->order_key, add_query_arg('order', $order->id, get_permalink(woocommerce_get_page_id('thanks'))))            
            'redirect' => $this->get_return_url($order)
        );
    }

    public function validate_fields() {
        if ($this->check_bitenx() != TRUE) {
            echo "<div class=\"error\"><p>Your BitenX Address doesn't seem valid. Have you checked it?</p></div>";
        }
    }

    public function check_bitenx() {
        $bitenx_address = $this->settings['bitenx_address'];
        if(strlen($bitenx_address) == 99 && substr($bitenx_address, 4)) {
            return true;
        }

        return false;
    }

    public function instruction($order_id) {
        $order = wc_get_order($order_id);
        $amount = floatval(preg_replace('#[^\d.]#', '', $order->get_total()));
        $payment_id = $this->setPaymentCookie();
        $currency = $order->get_currency();
        $amount_BTENX2 = $this->ChangeTo($amount, $currency, $payment_id, $order_id);
        $address = $this->address;
        
        // If there isn't address, $address will be the Fexra's address for donating :)
        if(!isset($address)) {
            $address = "BTENXuzAzNs1E1RBFhteX56A5353vyHuSJ5AYYQfoN97PNbcMDvwQo4pUWHs7SYpuD9ThvA7AD3r742kwTmWh5o9WFaB9JXH8evP";
        }

        $uri = "bitenx:$address?amount=$amount?payment_id=$payment_id";
        $message = $this->verifyPayment($payment_id, $amount_BTENX2, $order);
        
        if($this->confirmed) {
            $color = "006400";
            $icon = "bitenx_icon_large.png";
            
        } else {
            $color = "DC143C";
            $icon = "loader.gif";            
        }
        
        if($this->discount) {
            $sanatized_discount = preg_replace('/[^0-9]/', '', $this->discount);
            $price = $amount_BTENX2." BTENX (".$sanatized_discount."% discount for using BTENX!)"; 
        } else {
            $price = $amount_BTENX2." BTENX";
        }

        echo "
            <head>
                <meta name='viewport' content='width=device-width, initial-scale=1.0'/>
            </head>
            <body>
                <div class='page-container'>
                    <div class='container-BTENX-payment'>
                        <div class='content-BTENX-payment'>
                            <div class='BTENX-amount-send'>
                                <span class='BTENX-label' style='font-weight:bold;'>Amount:</span>
                                <img src='".plugins_url() . "/woo-bitenx/assets/bitenx_icon.png' />" . $price . "
                            </div>
                            <br>
                            <div class='BTENX-address'>
                                <span class='BTENX-label' style='font-weight:bold;'>Address:</span>
                                <div class='BTENX-address-box'><input type='text' value='". $address . "' disabled style='width:100%;'></div>
                            </div>
                            <br>
                            <div class='BTENX-paymentid'>
                                <span class='BTENX-label' style='font-weight:bold;'>Payment ID:</span>
                                <div class='BTENX-paymentid-box'><input type='text' value='".$payment_id . "' disabled style='width:100%;'></div>
                            </div>
                            <br>
                            <div class='BTENX-verification-message' style='width:60%;float:left;text-align:center;'>
                                <img src=".plugins_url() . "/woo-bitenx/assets/".$icon." />
                                <h4><font color=$color>" . $message . "</font></h4>                    
                            </div>
                            <div class='BTENX-qr-code' style='width:40%;float:left;text-align:center;'>
                                <div class='BTENX-qr-code-box'><img src='https://api.qrserver.com/v1/create-qr-code/? size=200x200&data=" . $uri . "' /></div>
                                <a href='https://bitenx.net' target='_blank'>About BitenX</a>
                            </div>
                            <div class='clear'></div>
                        </div>
                        <div class='footer-BTENX-payment' style='text-align:center;margin: 15px 0 15px 0;'>
                            <small>Transaction should take no longer than a few minutes. This page refreshes automatically ever 30 seconds.</small>
                        </div>
                    </div>
                </div>
            </body>
        ";

        echo "<script type='text/javascript'>setTimeout(function () { location.reload(true); }, $this->reloadTime);</script>";
      }
  
    private function setPaymentCookie() {
        if (!isset($_COOKIE['payment_id'])) {
            $payment_id = bin2hex(random_bytes(32));
            setcookie('payment_id', $payment_id, time() + 2700);
        }
        else {
            $payment_id = $this->sanatizeID($_COOKIE['payment_id']);
        }

        return $payment_id;
    }
	
    public function sanatizeID($payment_id) {
        $sanatized_id = preg_replace("/[^a-zA-Z0-9]+/", "", $payment_id);
    	return $sanatized_id;
    }

    public function ChangeTo($amount, $currency, $payment_id, $order_id) {
        global $wpdb;
        //$wpdb->show_errors();
        $table = $wpdb->prefix . 'woocommerce_bitenx';
        $rows_num = $wpdb->get_results("SELECT count(*) as count FROM $table WHERE pid = '$payment_id'");

        //Check for matching paymentID (order vs cookie)
        if($rows_num[0]->count) {
            $stored_amount = $wpdb->get_results("SELECT hash, amount, paid FROM $table WHERE pid = '$payment_id'");
            $rounded_amount = $stored_amount[0]->amount;
        }
        else {
            $BTENX_live_price = $this->fetchPrice($currency);
            $new_amount = $amount / $BTENX_live_price;
            
            //Apply discount
            if(isset($this->discount)) {
                $sanatized_discount = preg_replace('/[^0-9]/', '', $this->discount);
                $discount_decimal = $sanatized_discount / 100;
                $discount = $new_amount * $discount_decimal;
                $final_amount = $new_amount - $discount;
                $rounded_amount = round($final_amount, 2);
            }
            else {
                $rounded_amount = round($new_amount, 2);
            }
            
            $status = $this->bitenx_daemon->getStatus();
            $hash = $status['lastBlockHash'];
            
            $wpdb->query("INSERT INTO $table(oid, pid, hash, amount, conversion, paid) VALUES($order_id, '$payment_id', '$hash', $rounded_amount, $BTENX_live_price, '0')");
        }

        return $rounded_amount;
    }

    public function fetchPrice($currency) {
        if ($currency == 'BTENX') {
            $price = '1';
            return $price;
        }
        else {
            $BTENX_price = file_get_contents('https://cryptohub.online/api/market/ticker/BTC_BTENX/');
            $BTC_price = file_get_contents('https://min-api.cryptocompare.com/data/price?fsym=BTC&tsyms=' . $currency);
       
            $price = json_decode($BTENX_price, TRUE);
            $bprice = json_decode($BTC_price, TRUE);

            if (!isset($price)) {
                $this->log->add('BitenX_Gateway', '[ERROR] Unable to get the price of BTENX.');
            }

            if (!isset($bprice)) {
                $this->log->add('BitenX_Gateway', '[ERROR] Unable to get the price of ' + $currency);
            }

            return $price['price']*$bprice[$currency];
        }
    }
    
    private function onVerified($payment_id, $tAmount, $order_id) {
        $message = "Payment has been received and confirmed. Thanks!";
        $this->log->add('bitenx_gateway', '[SUCCESS] Payment has been recorded. Congratulations!');
        $this->confirmed = true;

        $order = wc_get_order($order_id);
        $order->update_status('completed', __('Payment has been received. Your order will be processed after ' + $this->confirms + ' confirmations', 'bitenx_gateway'));

        global $wpdb;
        $table = $wpdb->prefix . 'woocommerce_bitenx';   

        //Delete or Updates payment ID details.
        if(isset($this->delete_history)) {
            $wpdb->query("DELETE FROM $table WHERE pid ='$payment_id'");
        }
        else {
            $wpdb->query("UPDATE $table SET paid = '1' WHERE pid = '$payment_id'");
        }

        $this->reloadTime = 3000000000000; // dirty fix
        return $message;
    }
    
    public function verifyPayment($payment_id, $amount, $order_id) {

        $order = wc_get_order($order_id);
        $message = "We are waiting for your payment to be confirmed.";
        
        global $wpdb;
        //$wpdb->show_errors();
        $table = $wpdb->prefix . 'woocommerce_bitenx';
        $result = $wpdb->get_results("SELECT hash, paid FROM $table WHERE pid = '$payment_id'");

        //Check if already paid
        if($result[0]->paid == 1) {
            $message = $this->onVerified($payment_id, $tAmount, $order_id);            
        }

        //Check if order has been paid already        
        if($order->status == "completed") {
            echo "PAID";
            $message = $this->onVerified($payment_id, $tAmount, $order_id);
        }
                    
        $lastBlockHash = $result[0]->hash;
        $get_payments_method = $this->bitenx_daemon->getPayment($lastBlockHash, $payment_id);
        
        $tAmount = $amount*100;
        $vAmount = 0;

        foreach($get_payments_method["items"] as $item) {
            foreach($item["transactions"] as $itemm) {
                if($itemm["paymentId"] === $payment_id) {
                    $vAmount += $itemm["amount"];
                }
            }  
        }

        if($vAmount >= $tAmount) {
            $order->update_status("completed");
            $message = $this->onVerified($payment_id, $tAmount, $order_id);
        }

        return $message;
    }

    public function sslCheck() {
        if($this->enabled == "yes" && !$this->get_option('onion_service')) {
            if (get_option('woocommerce_force_ssl_checkout') == "no") {
                echo "<div class=\"error\"><p>" . sprintf(__("<strong>%s</strong> is enabled and WooCommerce is not forcing the SSL certificate on your checkout page. Please ensure that you have a valid SSL certificate and that you are <a href=\"%s\">forcing the checkout pages to be secured.</a>"), $this->method_title, admin_url('admin.php?page=wc-settings&tab=checkout')) . "</p></div>";
            }
        }
    }
}