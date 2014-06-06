<?php
/**
 * Plugin Name: CampTix Dwolla Gateway
 * Plugin URL: https://github.com/eternalwaves/camptix_payment_dwolla
 * Description: Dwolla Off-Site Gateway Payment Method for CampTix
 */

/*
 * This class is a payment method for CampTix which implements
 * Dwolla Express Checkout. You can use this as a base to create
 * your own redirect-based payment method for CampTix.
 *
 * @since CampTix 1.2
 */

require_once dirname( __FILE__ ) . '/inc/dwolla.php';

class CampTix_Payment_Method_Dwolla extends CampTix_Payment_Method {

    /**
     * The following variables are required for every payment method.
     */
    public $id = 'dwolla';
    public $name = 'Dwolla';
    public $description = 'Dwolla Off-Site Gateway. Please go to "<strong><a href="https://www.dwolla.com/applications" target="_blank">Registered Applications</a></strong>" in your Dwolla account to create a new Dwolla API application and enter its credentials here. Enable the following permissions:</p>
        <ul style="list-style: disc outside; padding-left: 2em">
            <li><strong>Account Information</strong> — AccountInfoFull</li>
            <li><strong>Transaction Details</strong> — Transactions</li>
            <li><strong>Balance</strong> — Balance</li>
            <li><strong>Send Money</strong> — Send</li>
            <li><strong>Funding Sources</strong> — Funding</li>
        </ul>
        <p>To enable registration status updates, go under "<strong>Features</strong>," enable "<strong>Web Hook Notifications</strong>," and enter [http://www.mywebsite.com/ticketspage<strong>?tix_payment_method=dwolla&tix_action=payment_notify</strong>] under "<strong>TransactionStatus URL</strong>."';
    public $supported_currencies = array( 'USD');
    public $supported_features = array(
        'refund-single' => true,
        'refund-all' => true,
    );

    /**
     * We can have an array to store our options.
     * Use $this->get_payment_options() to retrieve them.
     */
    protected $options = array();

    /**
     * @var string error messages returned from Dwolla
     */
    private $errorMessage = false;

    /**
     * @var string off-site gateway or oauth/rest server 
     */
    private $serverUrl = '';

    const OAUTH_REST_API = "oauth/rest/";
    const SANDBOX_SERVER = "https://uat.dwolla.com/";
    const PRODUCTION_SERVER = "https://www.dwolla.com/";

    /**
     * Runs during camptix_init, loads our options and sets some actions.
     * @see CampTix_Addon
     */
    function camptix_init() {
        $this->options = array_merge( array(
            'dwolla_id' => '',
            'api_key' => '',
            'api_secret' => '',
            'oauth_token' => '',
            'pin' => '',
            'assume_costs' => false,
            'funding_sources' => true,
            'guest_checkout' => true,
            'additional_funding_sources' => true,
            'refunds_source' => 'Balance',
            'sandbox' => true,
            'test' => true,
        ), $this->get_payment_options() );

        $this->serverUrl = $this->options['sandbox'] ? self::SANDBOX_SERVER : self::PRODUCTION_SERVER;

        add_action( 'template_redirect', array( $this, 'template_redirect' ) );
    }

    /**
     * This runs during settings field registration in CampTix for the
     * payment methods configuration screen. If your payment method has
     * options, this method is the place to add them to. You can use the
     * helper function to add typical settings fields. Don't forget to
     * validate them all in validate_options.
     */
    function payment_settings_fields() {
        $this->add_settings_field_helper( 'dwolla_id', __( 'Dwolla ID', 'camptix' ), array( $this, 'field_text' ) );
        $this->add_settings_field_helper( 'api_key', __( 'API Key', 'camptix' ), array( $this, 'field_text' ) );
        $this->add_settings_field_helper( 'api_secret', __( 'API Secret', 'camptix' ), array( $this, 'field_text' ) );
        $this->add_settings_field_helper( 'oauth_token', __( 'OAuth Token', 'camptix' ), array( $this, 'field_text' ),
            'Generate a token at <strong><a href="https://developers.dwolla.com/dev/token" target="_blank">Dwolla Developers | API / Generate a Token</a></strong>, entering the above "<strong>API Key</strong>" and "<strong>API Secret</strong>" credentials. Be sure to mark the following checkboxes under "<strong>Scope</strong>":</p>
            <ul class="description" style="font-weight: bold; list-style: disc outside; padding-left: 2em">
                <li>Send</li>
                <li>AccountInfoFull</li>
                <li>Transactions</li>
                <li>Balance</li>
                <li>Funding</li>
            </ul>
            <p class="description">Click "<strong>Production Environment</strong>" for LIVE transactions or "<strong>Sandbox Environment</strong>" if you are still testing.' );
        $this->add_settings_field_helper( 'pin', __( 'User\'s account PIN', 'camptix' ), array( $this, 'field_text' ) );
        $this->add_settings_field_helper( 'assume_costs', __( 'Assume Costs', 'camptix' ), array( $this, 'field_yesno' ),
            'Require the customer to assume the $0.25 Dwolla fee (if applicable) for this transaction. Defaults to "<strong>No</strong>", as the recipient eats the fee by default.' );
        $this->add_settings_field_helper( 'funding_sources', __( 'Allow Funding Sources', 'camptix' ), array( $this, 'field_yesno' ),
            'Set to "<strong>Yes</strong>" to allow the user to select funding sources other than Balance, such as ACH (bank account), or FiSync.');
        $this->add_settings_field_helper( 'guest_checkout', __( 'Allow Guest Checkout', 'camptix' ), array( $this, 'field_yesno' ),
            'Set to "<strong>Yes</strong>" to enable guest checkout; enabled by default. Guest Checkout enables customers to pay directly from their bank accounts, without having to register or login to a Dwolla account. Requires "<strong>Allow Funding Sources</strong>" to be set to "<strong>Yes</strong>".' );
        $this->add_settings_field_helper( 'refunds_source', __( 'Refunds Source', 'camptix' ), array( $this, 'field_text' ),
            'The exact name of the funds source (as named in your account) from which to initiate refunds.' );
        $this->add_settings_field_helper( 'sandbox', __( 'Sandbox Mode', 'camptix' ), array( $this, 'field_yesno' ),
            'To use Sandbox, you must also create an account at <strong><a href="https://uat.dwolla.com/" target="_blank">https://uat.dwolla.com/</a></strong> and follow the intial setup instructions to create an application and fill in the above "<strong>API Key</strong>" and "<strong>API Secret</strong>" using the credentials from the Sandbox application.' );
        $this->add_settings_field_helper( 'test', __( 'Test Mode', 'camptix' ), array( $this, 'field_yesno' ),
            'Set to "<strong>Yes</strong>" to run in test mode, regardless of sandbox or production mode. All transactions will have the transactionID of "1" and will process regardless of available funds.' ),
    }

    /**
     * A text input for the Settings API, name and value attributes
     * should be specified in $args. Same goes for the rest.
     * Adds in field description.
     */
    function field_text( $args ) {
        ?>
        <input type="text" name="<?php echo esc_attr( $args['name'] ); ?>" value="<?php echo esc_attr( $args['value'] ); ?>" class="regular-text" />
        <?php if ( isset( $args['description'] ) ) : ?>
        <p class="description"><?php echo $args['description']; ?></p>
        <?php endif; ?>
        <?php
    }

    /**
     * Validate the above option. Runs automatically upon options save and is
     * given an $input array. Expects an $output array of filtered payment method options.
     */
    function validate_options( $input ) {
        $output = $this->options;

        if ( isset( $input['dwolla_id'] ) )
            $output['dwolla_id'] = $input['dwolla_id'];

        if ( isset( $input['api_key'] ) )
            $output['api_key'] = $input['api_key'];

        if ( isset( $input['api_secret'] ) )
            $output['api_secret'] = $input['api_secret'];

        if ( isset( $input['oauth_token'] ) )
            $output['oauth_token'] = $input['oauth_token'];

        if ( isset( $input['pin'] ) )
            $output['pin'] = $input['pin'];

        if ( isset( $input['assume_costs'] ) )
            $output['assume_costs'] = (bool) $input['assume_costs'];

        if ( isset( $input['funding_sources'] ) )
            $output['funding_sources'] = (bool) $input['funding_sources'];

        if ( isset( $input['guest_checkout'] ) )
            $output['guest_checkout'] = (bool) $input['guest_checkout'];

        if ( isset( $input['refunds_source'] ) )
            $output['refunds_source'] = $input['refunds_source'];

        if ( isset( $input['sandbox'] ) ) {
            $output['sandbox'] = (bool) $input['sandbox'];
            $this->serverUrl = (bool) $input['sandbox'] ? self::SANDBOX_SERVER : self::PRODUCTION_SERVER;
        }

        if ( isset( $input['test'] ) ) {
            $output['test'] = (bool) $input['test'];
        }

        return $output;
    }

    /**
     * For Dwolla we'll watch for some additional CampTix actions which may be
     * fired from Dwolla either with a redirect (cancel and return) or a webhook (notify).
     */
    function template_redirect() {
        if ( ! isset( $_REQUEST['tix_payment_method'] ) || 'dwolla' != $_REQUEST['tix_payment_method'] )
            return;

        if ( isset( $_GET['tix_action'] ) ) {
            if ( 'payment_redirect' == $_GET['tix_action'] )
                $this->payment_redirect();

            if ( 'payment_callback' == $_GET['tix_action'] )
                $this->payment_callback();

            if ( 'payment_notify' == $_GET['tix_action'] )
                $this->payment_notify();
        }
    }

    /**
     * Helps convert payment statuses from Dwolla responses, to CampTix payment statuses.
     *
     * @return
     */
    function get_status_from_string( $payment_status ) {
        $payment_status = strtolower($payment_status);

        $statuses = array(
            'processed' => CampTix_Plugin::PAYMENT_STATUS_COMPLETED,
            'paid' => CampTix_Plugin::PAYMENT_STATUS_COMPLETED,
            'completed' => CampTix_Plugin::PAYMENT_STATUS_PENDING,
            'pending' => CampTix_Plugin::PAYMENT_STATUS_PENDING,
            'failed' => CampTix_Plugin::PAYMENT_STATUS_FAILED,
            'failure' => CampTix_Plugin::PAYMENT_STATUS_FAILED,
            'cancelled' => CampTix_Plugin::PAYMENT_STATUS_CANCELLED,
            'refunded' => CampTix_Plugin::PAYMENT_STATUS_REFUNDED,
        );

        // Return pending for unknows statuses.
        if ( ! isset( $statuses[ $payment_status ] ) )
            $payment_status = 'Pending';

        return $statuses[ $payment_status ];
    }

    /**
     * Helps convert payment statuses from Dwolla responses, to CampTix payment statuses.
     */
    function get_transaction_details($transaction_id) {
        $options = $this->options;

        // Verify required parameters
        if (!$transaction_id) {
            return $this->setError('Please enter a transaction ID.');
        }
        
        $url = add_query_arg( array(
            'oauth_token' => $options['oauth_token'],
        ), $this->serverUrl . self::OAUTH_REST_API . 'transactions/' . $transaction_id );

        $transaction_details = wp_remote_retrieve_body( wp_remote_get( $url ) );

        if( is_wp_error( $transaction_details ) ) {
            $this->log( "Error: Cannot get transaction details from {$url}" ); // Check for errors
            return false;
        }
        return json_decode( $transaction_details, true );
    }

    /**
     * Runs when the user confirms or cancels their payment during checkout at Dwolla.
     * This will simply tell CampTix to put the created attendee drafts into to Completed or Cancelled state.
     */
    function payment_notify() {
        global $camptix;
        $options = $this->options;

        if ( ! $this->verifyWebhookSignature() ) {
            $this->log( 'Dwolla Webhook Signature failed to verify!' );
            die();
        }

        $transaction_details = json_decode( file_get_contents( 'php://input' ), true );

        $this->log("Transaction Details: " . print_r($transaction_details, true));

        if ( isset( $transaction_details['Type'], $transaction_details['Subtype'] ) && 'Transaction' == $transaction_details['Type'] && 'Status' == $transaction_details['Subtype'] ) {
            $data = isset( $transaction_details['Transaction'] ) ? $transaction_details['Transaction'] : null;
            if ( isset( $data ) ) {

                $transaction_id = isset( $transaction_details['Id'] ) ? $transaction_details['Id'] : 1;

                $payment_status = isset( $data['Status'] ) ? $data['Status'] : '';

                $this->log( sprintf( 'Payment details for %s', $transaction_id ), null, $request );

                $attendees = get_posts( array(
                    'posts_per_page' => 1,
                    'post_type' => 'tix_attendee',
                    'post_status' => 'any',
                    'meta_query' => array(
                        array(
                            'key' => 'tix_transaction_id',
                            'value' => $transaction_id,
                        ),
                    ),
                ) );

                if ( ! $attendees ) {
                    $this->log( 'Could not match to attendee by transaction id.', null, $transaction_details );
                    return;
                }

                $this->log("Attendees: " . print_r($attendees, true));

                $payment_token = get_post_meta( $attendees[0]->ID, 'tix_payment_token', true );

                if ( ! $payment_token ) {
                    $this->log( 'Could find a payment token by transaction id.', null, $transaction_details );
                    return;
                }

                /**
                 * Note that when returning a successful payment, CampTix will be
                 * expecting the transaction_id and transaction_details array keys.
                 */
                $payment_data = array(
                    'transaction_id' => $transaction_id,
                    'transaction_details' => array(
                        // @todo maybe add more info about the payment
                        'raw' => $transaction_details,
                    ),
                );

                return $this->payment_result( $payment_token, $this->get_status_from_string( $payment_status ), $payment_data );
            }
            $this->log('No transaction data.');
        }
        die();
    }


    /**
     * Runs when the user confirms or cancels their payment during checkout at Dwolla.
     * This will simply tell CampTix to put the created attendee drafts into to Completed or Cancelled state.
     */
    function payment_redirect() {
        global $camptix;
        $options = $this->options;

        $payment_token = ( isset( $_REQUEST['tix_payment_token'] ) ) ? trim( $_REQUEST['tix_payment_token'] ) : '';
        $request = $_REQUEST;

        if ( ! $payment_token )
            die( 'empty token' );

        $order = $this->get_order( $payment_token );

        if ( ! $order )
            die( 'could not find order' );

        if ( isset( $request['signature'], $request['checkoutId'], $request['amount'] ) ) {
            $signature = $request['signature'];
            $checkoutId = $request['checkoutId'];
            $amount = $request['amount'];
            if ( $this->verifyGatewaySignature( $signature, $checkoutId, $amount ) ) {
                if ( isset( $request['status'] ) && $request['status'] == "Completed" ) {

                    $status = $request['status'];

                    $transaction_id = isset( $request['transaction'] ) ? $request['transaction'] : 1;

                    $transaction_details = $this->get_transaction_details( $transaction_id );

                    $payment_status = isset( $transaction_details['Response']['Status'] ) ? $transaction_details['Response']['Status'] : $status;

                    $this->log( sprintf( 'Payment details for %s', $transaction_id ), null, $request );

                    $this->log("Payment Status: $payment_status");

                    /**
                     * Note that when returning a successful payment, CampTix will be
                     * expecting the transaction_id and transaction_details array keys.
                     */
                    $payment_data = array(
                        'transaction_id' => $transaction_id,
                        'transaction_details' => array(
                            // @todo maybe add more info about the payment
                            'raw' => $request,
                        ),
                    );

                    if ( isset( $request['postback'] ) && 'failure' == $request['postback'] ) {
                        $this->log( 'Error with Dwolla postback from specified callback URL.', null, $request );
                    }

                    return $this->payment_result( $payment_token, $this->get_status_from_string( $payment_status ), $payment_data );
                }

                $error = isset( $request['error'] ) ? $request['error'] : 0;
                $error_message = isset( $request['error_description'] ) ? $request['error_description'] : '';

                if ( ! empty( $error_message ) )
                    $camptix->error( sprintf( __( 'Dwolla error: %s (%s)', 'camptix' ), $error_message, $error ) );

                $payment_data = array(
                    'error' => $error,
                    'error_message' => $error_message,
                    'raw' => $request,
                );

                return $this->payment_result( $payment_token, CampTix_Plugin::PAYMENT_STATUS_FAILED, $payment_data );
            }

            $payment_data = array(
                'error' => 'Error during Off-Site Gateway checkout. Invalid Gateway Signature.',
                'raw' => $request,
            );
            $this->log( 'Error during Off-Site Gateway checkout. Invalid Gateway Signature.', null, $request );
            return $this->payment_result( $payment_token, CampTix_Plugin::PAYMENT_STATUS_FAILED, $payment_data );
        }
        if ( isset( $request['error'], $request['error_description'] ) && 'User Cancelled' == $request['error_description'] ) {
            $attendees = get_posts( array(
                'posts_per_page' => 1,
                'post_type' => 'tix_attendee',
                'post_status' => 'any',
                'meta_query' => array(
                    array(
                        'key' => 'tix_payment_token',
                        'compare' => '=',
                        'value' => $payment_token,
                        'type' => 'CHAR',
                    ),
                ),
            ) );

            if ( ! $attendees )
                die( 'attendees not found' );

            // Look for an associated transaction ID, in case this purchase has already been made.
            $transaction_id = get_post_meta( $attendees[0]->ID, 'tix_transaction_id', true );
            $access_token = get_post_meta( $attendees[0]->ID, 'tix_access_token', true );

            if ( ! empty( $transaction_id ) ) {
                $transaction_details = $this->get_transaction_details( $transaction_id );

                if ( isset( $transaction_details['Success'] ) && $transaction_details['Success'] == true ) {
                    $status = $this->get_status_from_string( $transaction_details['Response']['Status'] );
                    if ( in_array( $status, array(
                        CampTix_Plugin::PAYMENT_STATUS_PENDING,
                        CampTix_Plugin::PAYMENT_STATUS_COMPLETED,
                    ) ) ) {

                        // False alarm. The payment has indeed been made and no need to cancel.
                        $this->log( 'False alarm on payment_cancel. This transaction is valid.', 0, $transaction_details );
                        wp_safe_redirect( $camptix->get_access_tickets_link( $access_token ) );
                        die();
                    }
                }
            }

            // Set the associated attendees to cancelled.
            return $this->payment_result( $payment_token, CampTix_Plugin::PAYMENT_STATUS_CANCELLED );
        }
        $error = isset( $request['error'] ) ? $request['error'] : 0;
        $error_message = isset( $request['error_description'] ) ? $request['error_description'] : '';

        if ( ! empty( $error_message ) )
            $camptix->error( sprintf( __( 'Dwolla error: %s', 'camptix' ), $error_message ) );

        return $this->payment_result( $payment_token, CampTix_Plugin::PAYMENT_STATUS_FAILED, array(
            'error' => $error,
            'error_message' => $error_message,
            'raw' => $request,
        ) );
    }

    /**
     * This runs when Dwolla redirects the user back after the user has clicked
     * Pay Now on Dwolla. At this point, the user hasn't been charged yet, so we
     * verify their order once more and fire DoExpressCheckoutPayment to produce
     * the charge. This method ends with a call to payment_result back to CampTix
     * which will redirect the user to their tickets page, send receipts, etc.
     */
    function payment_callback() {
        global $camptix;
        $options = $this->options;

        $payment_token = ( isset( $_REQUEST['tix_payment_token'] ) ) ? trim( $_REQUEST['tix_payment_token'] ) : '';
        $data = json_decode( file_get_contents( 'php://input' ), true );

        if ( ! $payment_token || ! $data['CheckoutId'] )
            die( 'empty token' );

        $order = $this->get_order( $payment_token );

        if ( ! $order )
            die( 'could not find order' );

        if ( isset( $data['Signature'], $data['CheckoutId'], $data['Amount'] ) ) {
            $signature = $data['Signature'];
            $checkoutId = $data['CheckoutId'];
            $amount = $data['Amount'];
            if ( $this->verifyGatewaySignature( $signature, $checkoutId, $amount ) ) {
                if ( isset( $data['Status'] ) && $data['Status'] == "Completed" ) {
                    $status = $data['Status'];
                    if ( (float) $amount != $order['total'] ) {
                        echo __( "Unexpected total!", 'camptix' );
                        die();
                    }

                    // One final check before charging the user.
                    if ( ! $camptix->verify_order( $order ) ) {
                        die( 'Something went wrong, order is no longer available.' );
                    }

                    return $data;
                } else {
                    $error_message = isset( $data['Error'] ) ? $data['Error'] : 'Error during Off-Site Gateway checkout.';

                    $payment_data = array(
                        'error_message' => $error_message,
                        'raw' => $data,
                    );
                    $this->log( $error_message, null, $data );
                    return $data;
                }
            } else {
                $error_message = isset( $data['Error'] ) ? $data['Error'] : 'Error during Off-Site Gateway checkout. Invalid Gateway Signature.';

                $payment_data = array(
                    'error' => $error_message,
                    'raw' => $data,
                );
                $this->log( $error_message, null, $data );
                return $data;
            }
        } else {
            $this->log( 'Error during Dwolla Checkout.', null, $data );
            $error_message = isset( $data['Error'] ) ? $data['Error'] : '';

            if ( ! empty( $error_message ) )
                $camptix->error( sprintf( __( 'Dwolla error: %s', 'camptix' ), $error_message ) );
            return $data;
        }

        die();
    }

    /**
     * This method is the fire starter. It's called when the user initiates
     * a checkout process with the selected payment method. In Dwolla's case,
     * if everything's okay, we redirect to the Dwolla Off-Site Gateway page with
     * the details of our transaction. If something's wrong, we return a failed
     * result back to CampTix immediately.
     */
    function payment_checkout( $payment_token ) {
        global $camptix;
        $options = $this->options;

        if ( ! $payment_token || empty( $payment_token ) )
            return false;

        if ( ! in_array( $this->camptix_options['currency'], $this->supported_currencies ) )
            die( __( 'The selected currency is not supported by this payment method.', 'camptix' ) );

        $url = $this->serverUrl . 'payment/request';

        $params = array(
            'key' => $options['api_key'],
            'secret' => $options['api_secret'],
            'callback' => add_query_arg( array(
                'tix_action' => 'payment_callback',
                'tix_payment_token' => $payment_token,
                'tix_payment_method' => 'dwolla'
            ), $this->get_tickets_url() ),
            'redirect' => add_query_arg( array(
                'tix_action' => 'payment_redirect',
                'tix_payment_token' => $payment_token,
                'tix_payment_method' => 'dwolla'
            ), $this->get_tickets_url() ),
            'assumeCosts' => $options['assume_costs'],
            'allowFundingSources' => $options['funding_sources'],
            'allowGuestCheckout' => $options['guest_checkout'],
            'additionalFundingSources' => $options['additional_funding_sources'],
            'test' => $options['test'],
        );

        $order = $this->get_order( $payment_token );

        $attendees = get_posts( array(
            'posts_per_page' => 1,
            'post_type' => 'tix_attendee',
            'post_status' => 'any',
            'meta_query' => array(
                array(
                    'key' => 'tix_payment_token',
                    'compare' => '=',
                    'value' => $payment_token,
                    'type' => 'CHAR',
                ),
            ),
        ) );

        $access_token = get_post_meta( $attendees[0]->ID, 'tix_access_token', true );
        $receipt_first_name = get_post_meta( $attendees[0]->ID, 'tix_first_name', true );
        $receipt_last_name = get_post_meta( $attendees[0]->ID, 'tix_last_name', true );
        $receipt_email = get_post_meta( $attendees[0]->ID, 'tix_receipt_email', true );

        $params['purchaseOrder'] = array(
            'destinationId' => $options['dwolla_id'],
            'shipping' => 0,
            'tax' => 0,
            'total' => $order['total'],
        );

        $event_name = 'Event';
        if ( isset( $this->camptix_options['event_name'] ) )
            $event_name = $this->camptix_options['event_name'];

        $i = 0;
        foreach ( $order['items'] as $item ) {
            $params['purchaseOrder']['orderItems'][] = array(
                'Name' => substr( strip_tags( $event_name . ': ' . $item['name'] ), 0, 127 ),
                'Description' => substr( strip_tags( $item['description'] ), 0, 127 ),
                'Price' => $item['price'],
                'Quantity' => $item['quantity'],
            );
        }

        $request = $this->curl( $url, 'POST', $params );

        if ( !$request ) {
            $payment_data = array(
                'error' => 'Error requesting Dwolla checkout.',
                'raw' => $request,
            );
            $this->log( 'Error requesting Dwolla checkout.', null, $request );
            return $this->payment_result( $payment_token, CampTix_Plugin::PAYMENT_STATUS_FAILED, $payment_data );
        }
        else {
            if ( isset( $request['CheckoutId'] ) ) {
                $url = $this->serverUrl . 'payment/checkout/' . $request['CheckoutId'];
                wp_redirect( esc_url_raw( $url ) );
            } else {
                $status = isset( $request['Result'] ) ? $this->get_status_from_string( $request['Result'] ) : '';
                $error_message = isset( $request['Message'] ) ? $request['Message'] : '';

                if ( ! empty( $error_message ) )
                    $this->setError($request['Message']);

                $payment_data = array(
                    'error' => $error_message,
                    'raw' => $request,
                );

                return $this->payment_result( $payment_token, $status, $payment_data );
            }
        }
    }

    /**
     * Submits a single, user-initiated refund request to Dwolla and returns the result
     */
    function payment_refund( $payment_token ) {
        global $camptix;
        $result = $this->send_refund_request( $payment_token );

        if ( CampTix_Plugin::PAYMENT_STATUS_REFUNDED != $result['status'] ) {
            $error_message = isset( $result['refund_transaction_details']['Message'] ) ? $result['refund_transaction_details']['Message'] : '';

            if ( ! empty( $error_message ) )
                $camptix->error( sprintf( __( 'Dwolla error: %s', 'camptix' ), $error_message ) );
        }

        $refund_data = array(
            'transaction_id'             => $result['transaction_id'],
            'refund_transaction_id'      => $result['refund_transaction_id'],
            'refund_transaction_details' => array(
                'raw' => $result['refund_transaction_details'],
            ),
        );

        return $this->payment_result( $payment_token, $this->get_status_from_string( $result['status'] ), $refund_data );
    }

    /*
     * Sends a request to Dwolla to refund a transaction
     */
    function send_refund_request( $payment_token ) {
        global $camptix;
        $options = $this->options;
        $funds_source = $options['refunds_source'];

        $url = $serverUrl . self::OAUTH_REST_API . 'transactions/refund';

        $transaction_id = $camptix->get_post_meta_from_payment_token( $payment_token, 'tix_transaction_id' );

        if ( !$transaction_id )
            return $this->setError('No valid transaction ID.');

        $this->log("Transaction ID: $transaction_id");

        $result = array(
            'token' => $payment_token,
            'transaction_id' => $transaction_id,
        );

        $transaction_details = $this->get_transaction_details( $transaction_id );

        $this->log('$this->get_transaction_details( $transaction_id )' . print_r($transaction_details, true));

        if ( isset( $transaction_details['Success'] ) && true == $transaction_details['Success'] ) {

            if ( isset( $transaction_details['Response']['Amount'] ) )
                $amount = $transaction_details['Response']['Amount'];

//            if ( isset( $transaction_details['Response']['DestinationId'] ) )
//                $funds_source = $transaction_details['Response']['DestinationId'];

            // Reset request
            $params = array(
                'oauth_token' => $options['oauth_token'],
                'pin' => $options['pin'],
                'transaction_id' => $transaction_id,
                'fundsSource' => $funds_source,
                'amount' => $amount,
            );

            $response = $this->curl( $url, 'POST', $params );

            $this->log( "$this->curl( $url, 'POST', $params ): " . print_r($response, true) );

            // Process Dwolla's response
            if ( !$response ) {
                // HTTP request failed, so mimic the response structure to provide a consistent response format
                $response = array(
                    'Success' => false,
                    'Message' => __( 'Unexpected error has occurred.', 'camptix' ),   // don't reveal the raw error message to the user in case it contains sensitive network/server/application-layer data. It will be logged instead later on.
                    'raw' => $response,
                );
            }

            if ( isset( $response['Success'], $response['Response']['TransactionId'] ) && true == $response['Success'] ) {
                $result['refund_transaction_id'] = $response['Response']['TransactionId'];
                $result['refund_transaction_details'] = $response;
                $result['status'] = $this->get_status_from_string( 'refunded' );
            } else {
                $result['refund_transaction_id'] = false;
                $result['refund_transaction_details'] = $response;
                $result['status'] = CampTix_Plugin::PAYMENT_STATUS_REFUND_FAILED;

                $error_message = isset( $response['Message'] ) ? 'Error during Refund Transaction. ' . $response['Message'] : 'Error during Refund Transaction';

                $this->log( $error_message, null, $response );
            }

            return $result;
        } else
            $this->log( "Cannot retrieve transaction details for $transaction_id" );
    }

    /**
     * @return string|bool Error message or false if error message does not exist
     */
    public function getError()
    {
        if (!$this->errorMessage) {
            return false;
        }

        $error = $this->errorMessage;
        $this->errorMessage = false;

        return $error;
    }

    /**
     * @param string $message Error message
     */
    protected function setError($message) {
        global $camptix;

        $this->errorMessage = $message;

        $camptix->error( __("Dwolla error: {$this->getError()}", 'camptix') );

        return false;
    }

    /**
     * Executes GET requests against API
     * From https://github.com/Dwolla/dwolla-php
     * 
     * @param string $request
     * @param array $params
     * @return array|null Array of results or null if json_decode fails in curl()
     */
    function curl($url, $method = 'GET', $params = array())
    {
        // Encode POST data
        $data = json_encode($params);

        // Set request headers
        $headers = array('Accept: application/json', 'Content-Type: application/json;charset=UTF-8');
        if ($method == 'POST') {
            $headers[] = 'Content-Length: ' . strlen($data);
        }

        // Set up our CURL request
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        // Windows require this certificate
        if( strtoupper (substr(PHP_OS, 0,3)) == 'WIN' ) {
          $ca = dirname(__FILE__);
          curl_setopt($ch, CURLOPT_CAINFO, $ca); // Set the location of the CA-bundle
          curl_setopt($ch, CURLOPT_CAINFO, $ca . '/cacert.pem'); // Set the location of the CA-bundle
        }

        // Initiate request
        $rawData = curl_exec($ch);

        // If HTTP response wasn't 200,
        // log it as an error!
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($code !== 200) {
            if($this->debugMode) {
                echo "Here is all the information we got from curl: \n";
                print_r(curl_getinfo($ch));
                print_r(curl_error($ch));
            }

            return array(
                'Success' => false,
                'Message' => "Request failed. Server responded with: {$code}"
            );
        }

        // All done with CURL
        curl_close($ch);

        // Otherwise, assume we got some
        // sort of a response
        return json_decode($rawData, true);
    }

    /**
     * Verify the signature returned from Webhook notifications
     * 
     * @return bool Is signature valid?
     */
    function verifyWebhookSignature() {
        $options = $this->options;

        // 1. Get the request body
        $body = file_get_contents( 'php://input' );
        
        // 2. Get Dwolla's signature
        $headers = getallheaders();
        $signature = $headers['X-Dwolla-Signature'];

        // 3. Calculate hash, and compare to the signature
        $hash = hash_hmac( 'sha1', $body, $options['api_secret'] );
        $validated = ( $hash == $signature );
        
        if ( !$validated ) {
            return $this->setError( 'Dwolla signature verification failed.' );
        }
        
        return true;
    }    

    /**
     * Verify the signature returned from Offsite-Gateway Redirect
     * From https://github.com/Dwolla/dwolla-php
     * 
     * @param string $signature
     * @param string $checkoutId
     * @param float $amount
     * @return bool Is signature valid? 
     */
    function verifyGatewaySignature($signature = false, $checkoutId = false, $amount = false) {
        $options = $this->options;
        // Verify required parameters
        if (!$signature) {
            return $this->setError('Please pass a proposed signature.');
        }
        if (!$checkoutId) {
            return $this->setError('Please pass a checkout ID.');
        }
        if (!$amount) {
            return $this->setError('Please pass a total transaction amount.');
        }

        $amount = number_format($amount, 2);

        // Calculate an HMAC-SHA1 hexadecimal hash
        // of the checkoutId and amount ampersand separated
        // using the consumer secret of the application
        // as the hash key.
        //
        // @doc: http://developers.dwolla.com/dev/docs/gateway
        $hash = hash_hmac("sha1", "{$checkoutId}&{$amount}", $options['api_secret']);

        if($hash !== $signature) {
          return $this->setError('Dwolla signature verification failed.');
        }

        return TRUE;
    }
    
}

/**
 * The last stage is to register your payment method with CampTix.
 * Since the CampTix_Payment_Method class extends from CampTix_Addon,
 * we use the camptix_register_addon function to register it.
 */
camptix_register_addon( 'CampTix_Payment_Method_Dwolla' );
