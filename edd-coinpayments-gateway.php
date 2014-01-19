<?php
/**
 * Plugin Name:     Easy Digital Downloads - CoinPayments Gateway
 * Plugin URI:      http://wordpress.org/plugins/easy-digital-downloads-coinpayments-gateway
 * Description:     Add support for CoinPayments to Easy Digital Downloads. This plugin is almost entirely based on code provided by <a href="https://www.coinpayments.net/index.php?cmd=merchant_tools&sub=plugins" target="_blank">CoinPayments</a>.
 * Version:         1.0.1
 * Author:          Daniel J Griffiths & CoinPayments.net
 * Author URI:      http://ghost1227.com
 */


// Exit if accessed directly
if( !defined( 'ABSPATH' ) ) exit;


if( !class_exists( 'EDD_CoinPayments' ) ) {

    class EDD_CoinPayments {

        private static $instance;

        /**
         * Get active instance
         *
         * @since       1.0.0
         * @access      public
         * @static
         * @return      object self::$instance
         */
        public static function get_instance() {
            if( !self::$instance )
                self::$instance = new EDD_CoinPayments();

            return self::$instance;
        }


        /**
         * Class constructor
         *
         * @since       1.0.0
         * @access      public
         * @return      void
         */
        public function __construct() {
            // Plugin dir
            define( 'EDD_COINPAYMENTS_DIR', plugin_dir_path( __FILE__ ) );

            // Plugin URL
            define( 'EDD_COINPAYMENTS_URL', plugin_dir_url( __FILE__ ) );

            $this->init();
        }


        /**
         * Run action and filter hooks
         *
         * @since       1.0.0
         * @access      private
         * @return      void
         */
        private function init() {
            // Make sure EDD is active
            if( !class_exists( 'Easy_Digital_Downloads' ) ) return;

            global $edd_options;

            // Internationalization
            add_action( 'init', array( $this, 'textdomain' ) );

            // Register settings
            add_filter( 'edd_settings_gateways', array( $this, 'settings' ), 1 );

            // Add the gateway
            add_filter( 'edd_payment_gateways', array( $this, 'register_gateway' ) );

            // Remove CC form
            add_action( 'edd_coinpayments_cc_form', '__return_false' );

            // Process payment
            add_action( 'edd_gateway_coinpayments', array( $this, 'process_payment' ) );
            add_action( 'init', array( $this, 'edd_listen_for_coinpayments_ipn' ) );
            add_action( 'edd_verify_coinpayments_ipn', array( $this, 'edd_process_coinpayments_ipn' ) );

            // Display errors
            add_action( 'edd_after_cc_fields', array( $this, 'errors_div' ), 999 );
        }


        /**
         * Internationalization
         *
         * @since       1.0.0
         * @access      public
         * @static
         * @return      void
         */
        public static function textdomain() {
            // Set filter for language directory
            $lang_dir = dirname( plugin_basename( __FILE__ ) ) . '/languages/';
            $lang_dir = apply_filters( 'edd_coinpayments_lang_dir', $lang_dir );

            // Load translations
            load_plugin_textdomain( 'edd-coinpayments', false, $lang_dir );
        }


        /**
         * Add settings
         *
         * @since       1.0.0
         * @access      public
         * @param       array $settings The existing plugin settings
         * @return      array
         */
        public function settings( $settings ) {
            $coinpayments_settings = array(
                array(
                    'id'    => 'edd_coinpayments_settings',
                    'name'  => '<strong>' . __( 'CoinPayments Settings', 'edd-coinpayments' ) . '</strong>',
                    'desc'  => __( 'Configure your CoinPayments settings', 'edd-coinpayments' ),
                    'type'  => 'header'
                ),
                array(
                    'id'    => 'edd_coinpayments_merchant',
                    'name'  => __( 'Merchant ID', 'edd-coinpayments' ),
                    'desc'  => __( 'Enter your CoinPayments merchant ID', 'edd-coinpayments' ),
                    'type'  => 'text'
                ),
                array(
                    'id'    => 'edd_coinpayments_ipn_secret',
                    'name'  => __( 'IPN Secret', 'edd-coinpayments' ),
                    'desc'  => __( 'Enter your CoinPayments IPN secret', 'edd-coinpayments' ),
                    'type'  => 'text'
                ),
                array(
                    'id'    => 'edd_coinpayments_email',
                    'name'  => __( 'IPN Debug Email (optional)', 'edd-coinpayments' ),
                    'desc'  => __( 'Enter an email address to receive IPN debug emails', 'edd-coinpayments' ),
                    'type'  => 'text'
                )
            );

            return array_merge( $settings, $coinpayments_settings );
        }


        /**
         * Register our new gateway
         *
         * @since       1.0.0
         * @access      public
         * @param       array $gateways The current gateway list
         * @return      array $gateways The updated gateway list
         */
        public function register_gateway( $gateways ) {
            $gateways['coinpayments'] = array(
                'admin_label'       => 'Coin Payments',
                'checkout_label'    => __( 'Coin Payments - Pay with Bitcoin, Litecoin, or other cryptocurrencies', 'edd-coinpayments-gateway' )
            );

            return $gateways;
        }


        /**
         * Process payment submission
         *
         * @since       1.0.0
         * @access      public
         * @global      array $edd_options
         * @param       array $purchase_data The data for a specific purchase
         * @return      void
         */
        public function process_payment( $purchase_data ) {
            global $edd_options;

            // Collect payment data
            $payment_data = array(
                'price'         => $purchase_data['price'],
                'date'          => $purchase_data['date'],
                'user_email'    => $purchase_data['user_email'],
                'purchase_key'  => $purchase_data['purchase_key'],
                'currency'      => edd_get_currency(),
                'downloads'     => $purchase_data['downloads'],
                'user_info'     => $purchase_data['user_info'],
                'cart_details'  => $purchase_data['cart_details'],
                'gateway'       => 'coinpayments',
                'status'        => 'pending'
            );

            // Record the pending payment
            $payment = edd_insert_payment( $payment_data );

            // Were there any errors?
            if( !$payment ) {
                // Record the error
                edd_record_gateway_error( __( 'Payment Error', 'edd-coinpayments' ), sprintf( __( 'Payment creation failed before sending buyer to CoinPayments. Payment data: %s', 'edd-coinpayments' ), json_encode( $payment_data ) ), $payment );
                edd_send_back_to_checkout( '?payment-mode=' . $purchase_data['post_data']['edd-gateway'] );
            } else {
                $ipn_url = trailingslashit( home_url() ).'?edd-listener=CPIPN';
                $success_url = add_query_arg( 'payment-confirmation', 'coinpayments', get_permalink( $edd_options['success_page'] ) );
                $coinpayments_redirect = 'https://www.coinpayments.net/index.php?';

                // Setup CoinPayments arguments
                $coinpayments_args = array(
                    'cmd'           => '_pay',
                    'reset'         => '1',
                    'merchant'      => $edd_options['edd_coinpayments_merchant'],
                    'email'         => $purchase_data['user_email'],
                    'amountf'       => round( $purchase_data['price'] - $purchase_data['tax'], 2 ),
                    'item_name'     => stripslashes( html_entity_decode( wp_strip_all_tags( edd_get_purchase_summary( $purchase_data, false ) ), ENT_COMPAT, 'UTF-8' ) ),
                    'invoice'       => $purchase_data['purchase_key'],
                    'want_shipping' => '0',
                    'shippingf'     => '0',
                    'allow_extra'   => '0',
                    'currency'      => edd_get_currency(),
                    'custom'        => $payment,
                    'success_url'   => $success_url,
                    'cancel_url'    => edd_get_failed_transaction_uri(),
                    'ipn_url'       => $ipn_url,
                );
            }

            // Calculate discount
            /*
            $discounted_amount = $purchase_data['discount'];

            if( ! empty( $purchase_data['fees'] ) ) {
                //$i = empty( $i ) ? 1 : $i;
                foreach( $purchase_data['fees'] as $fee ) {
                    if( floatval( $fee['amount'] ) > '0' ) {
                        $coinpayments_args['amountf'] += $fee['amount'];
                    } else {
                        // This is a negative fee (discount)
                        $discounted_amount += abs( $fee['amount'] );
                    }
                }
            }

            if( $discounted_amount > '0' )
                $coinpayments_args['amountf'] -= $discounted_amount;
                */

            // Add taxes to the cart
            if ( edd_use_taxes() )
                $coinpayments_args['taxf'] = $purchase_data['tax'];

            $coinpayments_args = apply_filters('edd_coinpayments_redirect_args', $coinpayments_args, $purchase_data );

            // Build query
            $coinpayments_redirect .= http_build_query( $coinpayments_args );

            // Get rid of cart contents
            edd_empty_cart();

            // Redirect to CoinPayments
            wp_redirect( $coinpayments_redirect );
            exit;
        }


        /**
         * Listens for a CoinPayments IPN requests and then sends to the processing function
         *
         * @since       1.0.0
         * @access      public
         * @global      array $edd_options
         * @return      void
         */
        public function edd_listen_for_coinpayments_ipn() {
            global $edd_options;

            if ( isset( $_GET['edd-listener'] ) && $_GET['edd-listener'] == 'CPIPN' ) {
                do_action( 'edd_verify_coinpayments_ipn' );
            }
        }


        /**
         * Handle IPN errors
         *
         * @since       1.0.0
         * @access      public
         * @global      array $edd_options
         * @param       int $payment_id
         * @param       string $error_msg
         * @return      void
         */
        public function edd_coinpayments_ipn_error( $payment_id, $error_msg ) {
            global $edd_options;

            if( !empty( $edd_options['edd_coinpayments_email'] ) ) {
                $report  = __( 'AUTH User: ', 'edd-coinpayments' ) . $_SERVER['PHP_AUTH_USER'] . "\n";
                $report .= __( 'AUTH Pass: ', 'edd-coinpayments' ) . $_SERVER['PHP_AUTH_PW'] . "\n\n";

                $report .= __( 'Error Message: ', 'edd-coinpayments' ) . $error_msg . "\n\n";

                $report .= __( 'POST Fields: ', 'edd-coinpayments' ) . "\n\n";

                foreach( $_POST as $key => $value ) {
                    $report .= $key . '=' . $value . "\n";
                }

                mail( $edd_options['edd_coinpayments_email'], __( 'CoinPayments.net Invalid IPN', 'edd-coinpayments' ), $report );
            }

            if( !empty( $payment_id ) ) {
                edd_record_gateway_error( __( 'IPN Error', 'edd-coinpayments' ), $error_msg, $payment_id );
                edd_update_payment_status( $payment_id, 'failed' );
            }

            die( __( 'IPN Error: ', 'edd-coinpayments' ) . $error_msg );
        }


        /**
         * Process CoinPayments IPN
         *
         * @since       1.0.0
         * @access      public
         * @global      array $edd_options
         * @return void
         */
        public function edd_process_coinpayments_ipn() {
            global $edd_options;

            // Check the request method is POST
            if( isset( $_SERVER['REQUEST_METHOD'] ) && $_SERVER['REQUEST_METHOD'] != 'POST' ) {
                edd_coinpayments_ipn_error( null, __( 'Request method not POST', 'edd-coinpayments' ) );
            }
    
            // Collect payment details
            $payment_id     = $_POST['custom'];
            $purchase_key   = isset( $_POST['invoice'] ) ? $_POST['invoice'] : $_POST['item_number'];
            $amount         = $_POST['amount1'];
            $status         = intval( $_POST['status'] );
            $status_text    = strtolower( $_POST['status_text'] );
            $currency       = strtolower( $_POST['currency1'] );

            if( !isset( $_SERVER['PHP_AUTH_USER']) || !isset( $_SERVER['PHP_AUTH_PW'] ) ) {
                edd_coinpayments_ipn_error( $payment_id, __( 'HTTP Auth user/pass not set!', 'edd-coinpayments' ) );
            }

            if( $_SERVER['PHP_AUTH_USER'] != $edd_options['edd_coinpayments_merchant'] || $_SERVER['PHP_AUTH_PW'] != $edd_options['edd_coinpayments_ipn_secret'] ) {
                edd_coinpayments_ipn_error( $payment_id, __( 'HTTP Auth user/pass do not match! (check your merchant ID and IPN secret)', 'edd-coinpayments' ) );
            }
    
            if( $_POST['ipn_type'] != 'button' ) {
                edd_coinpayments_ipn_error( $payment_id, __( 'ipn_type should be button', 'edd-coinpayments' ) );
            }

            if( $_POST['merchant'] != $edd_options['edd_coinpayments_merchant'] ) {
                edd_coinpayments_ipn_error( $payment_id, __( 'merchant does not match', 'edd-coinpayments' ) );
            }

            // Retrieve the total purchase amount
            $payment_amount = edd_get_payment_amount( $payment_id );

            if( get_post_status( $payment_id ) == 'publish' )
                die( __( 'Payment already published', 'coinpayments' ) ); // Only complete payments once

            if( edd_get_payment_gateway( $payment_id ) != 'coinpayments' )
                edd_coinpayments_ipn_error( $payment_id, __( 'Not a CoinPayments.net order!', 'edd-coinpayments' ) ); // this isn't a CoinPayments order

            if( !edd_get_payment_user_email( $payment_id ) ) {

                // No email associated with purchase, so store from CoinPayments
                update_post_meta( $payment_id, '_edd_payment_user_email', $_POST['email'] );

                // Setup and store the customers's details
                $address = array();
                $address['line1']   = ! empty( $_POST['address1']       ) ? $_POST['address1']       : false;
                $address['line2']   = ! empty( $_POST['address2']       ) ? $_POST['address2']       : false;
                $address['city']    = ! empty( $_POST['city']         ) ? $_POST['city']         : false;
                $address['state']   = ! empty( $_POST['state']        ) ? $_POST['state']        : false;
                $address['country'] = ! empty( $_POST['country'] ) ? $_POST['country'] : false;
                $address['zip']     = ! empty( $_POST['zip']          ) ? $_POST['zip']          : false;

                $user_info = array(
                    'id'         => '-1',
                    'email'      => $_POST['email'],
                    'first_name' => $_POST['first_name'],
                    'last_name'  => $_POST['last_name'],
                    'discount'   => '',
                    'address'    => $address
                );

                $payment_meta = get_post_meta( $payment_id, '_edd_payment_meta', true );
                $payment_meta['user_info'] = serialize( $user_info );
                update_post_meta( $payment_id, '_edd_payment_meta', $payment_meta );
            }

            // Verify payment currency
            if( $currency != strtolower( edd_get_currency() ) ) {
                edd_coinpayments_ipn_error( $payment_id, sprintf( __( 'Invalid currency in IPN response. IPN data: %s', 'edd-coinpayments' ), json_encode( $_POST ) ) );
            }

            if( number_format( (float)$amount, 2 ) < number_format( (float)$payment_amount, 2 ) ) {
                edd_coinpayments_ipn_error( $payment_id, sprintf( __( 'Invalid payment amount in IPN response. IPN data: %s', 'edd-coinpayments' ), json_encode( $_POST ) ) );
            }
    
            if ( $purchase_key != edd_get_payment_key( $payment_id ) ) {
                // Purchase keys don't match
                edd_coinpayments_ipn_error( $payment_id, sprintf( __( 'Invalid purchase key in IPN response. IPN data: %s', 'edd-coinpayments' ), json_encode( $_POST ) ) );
            }

            if( $status < 0 ) {
                edd_insert_payment_note( $payment_id, sprintf( __( 'CoinPayments Error: %s', 'edd-coinpayments' ) , $status_text ) );
                edd_update_payment_status( $payment_id, 'failed' );
            } else if( $status < 100 && $status != 2 ) { // 2 == Queued for nightly payout
                edd_insert_payment_note( $payment_id, sprintf( __( 'CoinPayments Pending: %s', 'edd-coinpayments' ) , $status_text ) );
                edd_update_payment_status( $payment_id, 'pending' );
            } else {
                edd_insert_payment_note( $payment_id, sprintf( __( 'CoinPayments Transaction ID: %s', 'edd-coinpayments' ) , $_POST['txn_id'] ) );
                edd_insert_payment_note( $payment_id, sprintf( __( 'CoinPayments Success: %s', 'edd-coinpayments' ) , $status_text ) );
                edd_update_payment_status( $payment_id, 'publish' );
            }
    
            die( __('IPN Processed OK', 'edd-coinpayments' ) );
        }
    }
}


function edd_coinpayments_gateway_load() {
    $edd_coinpayments = new EDD_CoinPayments();
}
add_action( 'plugins_loaded', 'edd_coinpayments_gateway_load' );
