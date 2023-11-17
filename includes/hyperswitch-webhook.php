<?php

require_once __DIR__ . '/../hyperswitch-payment.php';

class Hyperswitch_Webhook
{

    protected $hyperswitch;
    const PAYMENT_SUCCEEDED = 'payment_succeeded';
    const PAYMENT_FAILED = 'payment_failed';
    const PAYMENT_PROCESSING = 'payment_processing';
    const ACTION_REQURIED = 'action_required';
    const REFUND_SUCCEEDED = 'refund_succeeded';
    // const REFUND_FAILED = 'refund_failed';
    // const DISPUTE_OPENED = 'dispute_opened';
    // const DISPUTE_EXPIRED = 'dispute_expired';
    // const DISPUTE_ACCEPTED = 'dispute_accepted';
    // const DISPUTE_CANCELLED = 'dispute_cancelled';
    // const DISPUTE_CHALLENGED = 'dispute_challenged';
    // const DISPUTE_WON = 'dispute_won';
    // const DISPUTE_LOST = 'dispute_lost';

    protected $eventsArray = [
        self::PAYMENT_SUCCEEDED,
        self::PAYMENT_FAILED,
        self::PAYMENT_PROCESSING,
        self::ACTION_REQURIED,
        self::REFUND_SUCCEEDED,
        // self::REFUND_FAILED,
        // self::DISPUTE_OPENED,
        // self::DISPUTE_EXPIRED,
        // self::DISPUTE_ACCEPTED,
        // self::DISPUTE_CANCELLED,
        // self::DISPUTE_CHALLENGED,
        // self::DISPUTE_WON,
        // self::DISPUTE_LOST
    ];

    public function __construct()
    {
        $this->hyperswitch = new WC_Hyperswitch_Payment();

    }

    public function process()
    {
        $post = file_get_contents('php://input');

        $data = json_decode($post, true);

        $signature = $_SERVER['HTTP_X_WEBHOOK_SIGNATURE_512'];


        if (json_last_error() !== 0) {
            return;
        }

        $enabled = $this->hyperswitch->get_option('enable_webhook');

        if (($enabled === 'yes') and (empty($data['event_type']) === false)) {
            // Skip the webhook if webhooks are disabled, or the event type is unrecognised
            $payment_id = $data['content']['object']['payment_id'];
            $this->hyperswitch->post_log("WC_WEBHOOK_RECEIVED", $data['event_type'], $payment_id);
            if ($this->shouldConsumeWebhook($data, $signature, $post) === false) {
                return;
            }
            switch ($data['event_type']) {
                case self::PAYMENT_SUCCEEDED:
                    return $this->paymentAuthorized($data);

                case self::PAYMENT_FAILED:
                    return $this->paymentHandle($data, "failed");

                case self::PAYMENT_PROCESSING:
                    return $this->paymentHandle($data, "processing");

                case self::ACTION_REQURIED:
                    return $this->paymentHandle($data, "action required");

                case self::REFUND_SUCCEEDED:
                    return $this->refundedCreated($data);

                default:
                    return;
            }
        }

    }

    protected function paymentAuthorized(array $data)
    {

        $order_id = $data['content']['object']['metadata']['order_num'];

        $order = wc_get_order($order_id);

        if ($order) {
            $payment_id = $data['content']['object']['payment_id'];
            $payment_method = $data['content']['object']['payment_method'];
            if ($order->status !== 'processing') {
                $this->hyperswitch->post_log("WC_ORDER_PLACED", null, $payment_id);
            }
            $order->payment_complete($payment_id);
            $order->add_order_note('Payment successful via ' . $payment_method . ' (Hyperswitch Payment ID: ' . $payment_id . ') (via Hyperswitch Webhook)');
            if ($data['object']['capture_method'] === "automatic") {
                $order->update_meta_data('payment_captured', 'yes');
            }
            $this->hyperswitch->post_log("WC_PAYMENT_SUCCEEDED_WEBHOOK", null, $payment_id);

        } else {
            exit; // Not a Woocommerce Order
        }

        exit;
    }

    protected function paymentHandle(array $data, $status)
    {

        $order_id = $data['content']['object']['metadata']['order_num'];

        $order = wc_get_order($order_id);

        if ($order) {
            $payment_id = $data['content']['object']['payment_id'];
            $payment_method = $data['content']['object']['payment_method'];
            $order->set_transaction_id($payment_id);
            if ($status == 'processing') {
                $order->update_status($this->hyperswitch->processing_payment_order_status);
            } else {
                $order->update_status('pending');
            }
            $order->add_order_note('Payment ' . $status . ' via ' . $payment_method . ' (Hyperswitch Payment ID: ' . $payment_id . ') (via Hyperswitch Webhook)');
            $this->hyperswitch->post_log("WC_PAYMENT_" . str_replace(" ", "_", strtoupper($status)) . "_WEBHOOK", null, $payment_id);
        }

        exit;
    }

    protected function refundedCreated(array $data)
    {
        $payment_id = $data['content']['object']['payment_id'];
        if (isset($data['content']['object']['metadata']['order_num'])) {
            $order_id = $data['content']['object']['metadata']['order_num'];
        } else {
            $payment_intent = $this->hyperswitch->retrieve_payment_intent($payment_id);
            $order_id = $payment_intent['metadata']['order_num'];
        }
        $refund_num = $data['content']['object']['metadata']['refund_num'];
        $refund_id = $data['content']['object']['refund_id'];

        $order = wc_get_order($order_id);
        if ($order) {
            $refunds = $order->get_refunds();
            $targetRefund = null;
            if (isset($refund_num)) {
                foreach ($refunds as $refund) {
                    if ($refund->id == $refund_num) {
                        $targetRefund = $refund;
                        break;
                    }
                }
            }
            if (!isset($targetRefund)) { // Refund initiated via Hyperswitch Dashboard
                $refund = new WC_Order_Refund;
                $amount = $data['content']['object']['amount'];
                $reason = $data['content']['object']['reason'];
                $refund->set_amount((float) $amount / 100);
                $refund->set_reason($reason);
            }
            $order->add_order_note('Refund Successful (Hyperswitch Refund ID: ' . $refund_id . ') (via Hyperswitch Webhook)');
            $refund->set_refunded_payment(true);
        } else {
            exit; // Not a refund for a Woocommerce Order
        }
        // Graceful exit since payment is now refunded.
        exit;
    }


    protected function shouldConsumeWebhook($data, $signature, $payload)
    {
        $webhook_key = $this->hyperswitch->get_option('webhook_secret_key');
        $generated_signature = hash_hmac('sha512', $payload, $webhook_key);
        $signature_verification_result = $generated_signature === $signature;

        if (
            (isset($data['event_type'])) and
            (in_array($data['event_type'], $this->eventsArray) && $signature_verification_result)
        ) {
            return true;
        }

        return false;
    }
}