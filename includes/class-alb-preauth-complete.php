<?php
/**
 * Copyright © 2026 Alliance Dgtl. https://alb.ua/uk
 */

declare(strict_types=1);

namespace ALB;

use Exception;
use WC_Order;

if (!defined('ABSPATH')) exit;

/**
 * Class ALB_Preauth_Complete.
 */
class ALB_Preauth_Complete
{
    private AlbHppClient $client;

    private ALB_Authorize $albAuthorize;

    private array $opt;

    public function __construct(AlbHppClient $client, ALB_Authorize $albAuthorize, array $opt)
    {
        $this->client = $client;
        $this->albAuthorize = $albAuthorize;
        $this->opt = $opt;
    }

    /**
     * @return void
     */
    public function init_hooks(): void
    {
        add_action('woocommerce_order_status_changed', [$this, 'handle_status_change'], 10, 4);
    }

    /**
     * @param int $order_id
     * @param string $old_status
     * @param string $new_status
     * @param WC_Order $order
     * @return void
     */
    public function handle_status_change(int $order_id, string $old_status, string $new_status, WC_Order $order): void
    {
        $completion_trigger = ltrim($this->opt['preAuthCompleteStatus'] ?? 'processing', 'wc-');

        if ($new_status !== $completion_trigger) {
            return;
        }

        global $wpdb;
        $table = AllianceConfig::table_alliance_checkout_integration_order();

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM `{$table}` WHERE order_id = %d",
                $order_id
            )
        );

        if (!$row) {
            return;
        }

        if ($row->hpp_pay_type !== AllianceConfig::HPP_PAY_TYPE_PREAUTH) {
            return;
        }

        if (empty($row->operation_id)) {
            $this->revert_status_with_error(
                $order,
                $old_status,
                __(
                    'AlliancePay PREAUTH capture blocked: operation_id is empty. '
                    . 'The preauth callback from the bank has not been received yet. '
                    . 'Please wait for the callback and try again.',
                    'alliancepay'
                )
            );
            return;
        }

        $current_amount = (int)round((float)$order->get_total() * 100);
        $original_amount = (int)$row->original_authorized_amount;

        if (!empty($original_amount)) {
            $min_allowed = (int)round($original_amount * 0.8);
            $max_allowed = (int)round($original_amount * 1.2);

            if ($current_amount < $min_allowed || $current_amount > $max_allowed) {
                $this->revert_status_with_error(
                    $order,
                    $old_status,
                    sprintf(
                        __(
                            'AlliancePay PREAUTH capture blocked: order total %1$s UAH (coins: %2$d) is outside'
                            . ' the allowed ±20%% range of the original authorized'
                            . ' amount %3$s UAH (allowed: %4$d–%5$d coins).',
                            'alliancepay'
                        ),
                        number_format($current_amount / 100, 2),
                        $current_amount,
                        number_format($original_amount / 100, 2),
                        $min_allowed,
                        $max_allowed
                    )
                );
                return;
            }
        }

        try {
            $payload = [
                'merchantRequestId' => wp_generate_uuid4(),
                'merchantId' => $this->opt['merchantId'] ?? '',
                'originalOperationId' => $row->operation_id,
                'coinAmount' => (string)$current_amount,
                'date' => $this->get_completion_date(),
                'notificationUrl' => AllianceConfig::get_notification_url(),
            ];

            if (empty($this->opt['privateJwk'])) {
                throw new Exception('privateJwk is required for PREAUTH completion');
            }

            $jwk = is_array($this->opt['privateJwk'])
                ? $this->opt['privateJwk']
                : json_decode((string)$this->opt['privateJwk'], true);

            if (empty($this->opt['serverPublicKey'])) {
                throw new Exception('serverPublicKey is required for PREAUTH completion');
            }

            $serverPublicKey = json_decode($this->opt['serverPublicKey'], true);
            $encryptedParams = ALB_Authorize::encrypt_with_simplejwt(
                $serverPublicKey,
                $payload
            );

            $res = $this->client->complete_preauth($encryptedParams);

            if (!empty($res['jwe'])) {
                $res = ALB_Authorize::decrypt_with_simplejwt(
                    $res['jwe'],
                    $jwk
                );
            }

            $wpdb->update(
                $table,
                ['updated_at' => current_time('mysql')],
                ['order_id' => $order_id]
            );

            $order->add_order_note(
                sprintf(
                    __(
                        'AlliancePay PREAUTH capture request sent successfully.'
                        . ' Amount: %s coins. Awaiting bank callback to finalize.',
                        'alliancepay'
                    ),
                    $current_amount
                )
            );

            if (defined('ALB_HPP_DEBUG') && ALB_HPP_DEBUG) {
                $dir = WP_CONTENT_DIR . '/uploads/alb-logs';
                if (!is_dir($dir)) {
                    @wp_mkdir_p($dir);
                }
                @file_put_contents(
                    $dir . '/debug.log',
                    date('c') . ' PREAUTH COMPLETION order=' . $order_id
                    . ' payload=' . wp_json_encode($payload, JSON_UNESCAPED_UNICODE)
                    . ' response=' . wp_json_encode($res, JSON_UNESCAPED_UNICODE) . PHP_EOL,
                    FILE_APPEND
                );
            }
        } catch (\Throwable $e) {
            $this->revert_status_with_error(
                $order,
                $old_status,
                sprintf(
                    __('AlliancePay PREAUTH capture failed: %s', 'alliancepay'),
                    $e->getMessage()
                )
            );
        }
    }

    /**
     * @param WC_Order $order
     * @param string $revert_to_status
     * @param string $message
     * @return void
     */
    private function revert_status_with_error(WC_Order $order, string $revert_to_status, string $message): void
    {
        remove_action('woocommerce_order_status_changed', [$this, 'handle_status_change'], 10);

        $order->update_status($revert_to_status, $message);

        add_action('woocommerce_order_status_changed', [$this, 'handle_status_change'], 10, 4);

        set_transient('alb_hpp_notice', [
            'type' => 'error',
            'text' => $message,
        ], 60);
    }

    /**
     * @return string
     * @throws Exception
     */
    private function get_completion_date(): string
    {
        $tz = wp_timezone();
        $dt = new \DateTime('now', $tz);
        $ms2 = substr($dt->format('v'), 0, 2);

        return $dt->format('Y-m-d H:i:s.') . $ms2 . $dt->format('P');
    }
}
