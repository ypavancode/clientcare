<?php
/**
 * Outgoing webhooks (Business / Agency plans): events are queued in webhook_deliveries when a notification is created
 * and delivered by the process-notifications cron (HTTP POST, JSON body, HMAC-SHA256 signature, 3 attempts).
 */
class Webhooks
{
    const EVENTS = ['website.down', 'website.recovered', 'page.failed', 'page.recovered', 'form.failed', 'form.recovered', 'ssl.failed', 'ssl.recovered', 'domain.expiring', 'hosting.expiring', 'incident.created', 'incident.resolved'];

    /** Map an in-app notification to webhook events. */
    public static function onNotification(?int $tenantId, string $category, string $type, string $title, string $message, array $opts): void
    {
        if (!$tenantId) return;
        $map = ['website_down' => ['website.down', 'incident.created'], 'website_recovered' => ['website.recovered', 'incident.resolved'], 'page_failed' => ['page.failed', 'incident.created'], 'page_recovered' => ['page.recovered', 'incident.resolved'],
            'form_failed' => ['form.failed', 'incident.created'], 'form_recovered' => ['form.recovered', 'incident.resolved'], 'domain_expiry' => ['domain.expiring'], 'hosting_expiry' => ['hosting.expiring']];
        $events = $map[$category] ?? [];
        if ($category === 'ssl') $events = $type === 'recovery' ? ['ssl.recovered', 'incident.resolved'] : ($type === 'critical' ? ['ssl.failed', 'incident.created'] : ['ssl.expiring']);
        if (!$events) return;
        try {
            if (!Tenant::feature('webhooks', $tenantId)) return;
            $hooks = DB::fetchAll("SELECT * FROM webhooks WHERE tenant_id = ? AND status = 'active'", [$tenantId]);
            if (!$hooks) return;
            $payloadBase = ['tenant_id' => $tenantId, 'title' => $title, 'message' => $message, 'severity' => $type, 'website_id' => $opts['website_id'] ?? null, 'client_id' => $opts['client_id'] ?? null, 'form_id' => $opts['form_id'] ?? null,
                'link' => !empty($opts['link']) ? url($opts['link']) : null, 'occurred_at' => date('c')];
            foreach ($hooks as $h) {
                $subscribed = json_decode((string) $h['events'], true) ?: [];
                foreach ($events as $ev) {
                    if ($subscribed && !in_array($ev, $subscribed, true)) continue;
                    DB::insert('webhook_deliveries', ['webhook_id' => $h['id'], 'tenant_id' => $tenantId, 'event' => $ev, 'payload' => json_encode(['event' => $ev] + $payloadBase, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'status' => 'pending', 'created_at' => date('Y-m-d H:i:s')]);
                }
            }
        } catch (Throwable $e) {
            app_log('warning', 'Webhook enqueue failed: ' . $e->getMessage());
        }
    }

    /** Deliver pending webhook calls. */
    public static function deliver(int $limit = 100): array
    {
        $sent = 0; $failed = 0;
        $rows = DB::fetchAll("SELECT d.*, w.url, w.secret FROM webhook_deliveries d JOIN webhooks w ON w.id = d.webhook_id WHERE d.status = 'pending' AND d.attempts < 3 AND w.status = 'active' ORDER BY d.id ASC LIMIT " . (int) $limit);
        foreach ($rows as $d) {
            $r = self::post($d['url'], $d['payload'], $d['secret'], $d['event'], (int) $d['id']);
            $attempts = (int) $d['attempts'] + 1;
            $ok = $r['code'] >= 200 && $r['code'] < 300;
            DB::update('webhook_deliveries', ['status' => $ok ? 'sent' : ($attempts >= 3 ? 'failed' : 'pending'), 'attempts' => $attempts, 'response_code' => $r['code'] ?: null, 'response_body' => mb_substr((string) $r['body'], 0, 500), 'sent_at' => $ok ? date('Y-m-d H:i:s') : null], 'id = ?', [$d['id']]);
            DB::update('webhooks', ['last_status_code' => $r['code'] ?: null, 'last_delivered_at' => $ok ? date('Y-m-d H:i:s') : null, 'failures' => $ok ? 0 : (int) DB::value("SELECT failures FROM webhooks WHERE id = ?", [$d['webhook_id']]) + 1], 'id = ?', [$d['webhook_id']]);
            if (!$ok) DB::query("UPDATE webhooks SET status = 'failed' WHERE id = ? AND failures >= 20", [$d['webhook_id']]);
            $ok ? $sent++ : $failed++;
        }
        return ['sent' => $sent, 'failed' => $failed];
    }

    public static function post(string $url, string $json, ?string $secret, string $event, int $deliveryId): array
    {
        $ch = curl_init($url);
        $headers = ['Content-Type: application/json', 'User-Agent: OutlineMonitor-Webhooks/1.0', 'X-Webhook-Event: ' . $event, 'X-Webhook-Delivery: ' . $deliveryId];
        if ($secret) $headers[] = 'X-Webhook-Signature: sha256=' . hash_hmac('sha256', $json, $secret);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $json, CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12, CURLOPT_CONNECTTIMEOUT => 6, CURLOPT_FOLLOWLOCATION => false, CURLOPT_SSL_VERIFYPEER => true]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        return ['code' => $code, 'body' => $body === false ? $err : $body];
    }

    /** Send a sample payload to a webhook (settings "Test" button). */
    public static function test(array $hook): array
    {
        $payload = json_encode(['event' => 'test', 'tenant_id' => $hook['tenant_id'], 'title' => 'Webhook test', 'message' => 'This is a test delivery from ' . setting('platform_name', 'Outline Monitor'), 'occurred_at' => date('c')], JSON_UNESCAPED_SLASHES);
        return self::post($hook['url'], $payload, $hook['secret'], 'test', 0);
    }
}
