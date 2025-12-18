# Event Mapping

`DSB_Events` hooks subscription lifecycle and WooCommerce order status changes.

| Source Hook/Status | Bridge Event | Notes |
| --- | --- | --- |
| `wps_sfw_after_created_subscription` | Derived from order status (default `activated`) | Marks event sent and clears retry on success. |
| `wps_sfw_subscription_order` / `wps_sfw_subscription_process_checkout` | `activated` | Sends when order contains mapped product. |
| `wps_sfw_after_renewal_payment` | `renewed` | Includes order context if available. |
| `wps_sfw_expire_subscription_scheduler` | `expired` | Uses subscription ID only. |
| `wps_sfw_subscription_cancel` | `cancelled` | |
| WooCommerce status change `completed`/`processing`/`active` | `activated` | Map from order status when subscription identified. |
| WooCommerce status change `cancelled`/`refunded`/`trash` | `cancelled` | |
| WooCommerce status change `failed` | `payment_failed` | |
| WooCommerce status change `expired` | `expired` | |

Missing subscription IDs trigger retries and log entries (`subscription_missing`). Plan absence logs `plan_missing` and prevents send.
