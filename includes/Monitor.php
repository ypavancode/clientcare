<?php
/**
 * Monitoring engine: website uptime, page-level monitoring (every page of a website), SSL certificates, form testing,
 * expiry checks, cron health. The SAME engine is used by the cron scripts (automatic) and by the "Check now" buttons (manual).
 */
class Monitor
{
    const UA = 'Mozilla/5.0 (compatible; OutlineMediaCRM-Monitor/1.6; +website-monitor)';

    /* =====================================================================
     * WEBSITE UPTIME
     * ===================================================================== */

    /**
     * Perform one HTTP check (with retries), store the result, maintain incidents and raise alerts.
     * $probe can be a result from probeMany() (concurrent cron mode) so no extra request is made here.
     */
    public static function checkWebsite(array $website, bool $notify = true, ?array $probe = null): array
    {
        $timeout = max(3, (int) setting('check_timeout', 15));
        $retries = max(0, (int) setting('retry_attempts', 2));
        $result = $probe;
        if ($result === null) {
            for ($i = 0; $i <= $retries; $i++) {
                $result = self::applyExpectation($website, self::httpProbe($website['url'], $timeout));
                if ($result['up']) break;
                if ($i < $retries) usleep(1500000);
            }
        }
        unset($result['body']);

        $now = date('Y-m-d H:i:s');
        $prev = $website['status'];
        $newStatus = $result['status'];
        $reason = $result['up'] ? null : $result['reason'];
        $errorMsg = $result['error'] ? mb_substr($result['error'], 0, 500) : null;

        DB::insert('website_monitoring', [
            'website_id'     => $website['id'],
            'checked_at'     => $now,
            'status'         => $newStatus,
            'failure_reason' => $reason,
            'http_code'      => $result['http_code'],
            'response_time'  => $result['response_ms'],
            'error_message'  => $errorMsg,
        ]);

        $update = [
            'status'          => $newStatus,
            'http_code'       => $result['http_code'],
            'response_time'   => $result['response_ms'],
            'last_checked_at' => $now,
            'error_message'   => $errorMsg,
            'failure_reason'  => $reason,
        ];
        if ($result['up']) {
            $update['last_success_at'] = $now;
        } else {
            $update['last_failed_at'] = $now;
        }
        DB::update('websites', $update, 'id = ?', [$website['id']]);

        // Incident handling: one incident per continuous outage, one alert at start, one at recovery.
        $open = DB::fetch("SELECT * FROM website_incidents WHERE website_id = ? AND resolved_at IS NULL ORDER BY id DESC LIMIT 1", [$website['id']]);
        if (!$result['up']) {
            if (!$open) {
                $id = DB::insert('website_incidents', [
                    'tenant_id'       => $website['tenant_id'] ?? null,
                    'website_id'      => $website['id'],
                    'started_at'      => $now,
                    'status'          => $newStatus,
                    'failure_reason'  => $reason,
                    'status_code'     => $result['http_code'],
                    'error_message'   => $errorMsg,
                    'failed_checks'   => 1,
                    'last_checked_at' => $now,
                    'alert_sent'      => 0,
                ]);
                $incident = DB::fetch("SELECT * FROM website_incidents WHERE id = ?", [$id]);
                if ($notify) {
                    Notifier::websiteDown(array_merge($website, $update, ['previous_status' => $prev]), $incident, $result);
                    DB::update('website_incidents', ['alert_sent' => 1], 'id = ?', [$id]);
                }
            } else {
                // Outage continues: update the incident, do NOT send another alert.
                Notifier::alertSeen('website_down:' . $open['id'], $reason, $errorMsg);
                DB::update('website_incidents', [
                    'failed_checks'   => (int) $open['failed_checks'] + 1,
                    'last_checked_at' => $now,
                    'status'          => $newStatus,
                    'failure_reason'  => $reason,
                    'status_code'     => $result['http_code'],
                    'error_message'   => $errorMsg,
                ], 'id = ?', [$open['id']]);
            }
        } elseif ($open) {
            $duration = max(0, strtotime($now) - strtotime($open['started_at']));
            DB::update('website_incidents', [
                'resolved_at'      => $now,
                'duration_seconds' => $duration,
                'last_checked_at'  => $now,
                'recovery_sent'    => $notify ? 1 : 0,
            ], 'id = ?', [$open['id']]);
            $open['resolved_at'] = $now;
            $open['duration_seconds'] = $duration;
            if ($notify) Notifier::websiteRecovered(array_merge($website, $update), $open);
        }

        if ($prev !== $newStatus && !in_array($prev, ['unknown', null, 'paused'], true)) {
            $isDownTransition = (!$result['up'] && in_array($prev, ['online', 'redirecting'], true));
            $isUpTransition = ($result['up'] && !in_array($prev, ['online', 'redirecting'], true));
            if (!$isDownTransition && !$isUpTransition) {
                ActivityLog::add('website_status_changed', 'Website status changed from ' . $prev . ' to ' . $newStatus . ': ' . $website['name'], ['client_id' => $website['client_id'], 'website_id' => $website['id']]);
            }
        }

        return $result + ['checked_at' => $now];
    }

    /** Apply the optional "expected text" rule to a probe result. */
    private static function applyExpectation(array $website, array $result): array
    {
        if ($result['up'] && !empty($website['expect_text']) && stripos($result['body'] ?? '', $website['expect_text']) === false) {
            $result['up'] = false;
            $result['status'] = 'content_error';
            $result['reason'] = 'Expected Content Missing';
            $result['error'] = 'Expected text "' . $website['expect_text'] . '" not found on the page – the real website may not be loading';
        }
        return $result;
    }

    /**
     * Check many websites concurrently (curl_multi). This is what makes a 5-minute cycle possible with
     * tens of thousands of sites: N requests run in parallel, failures are retried in a second concurrent pass,
     * then each result goes through the normal incident/alert logic.
     * @return array{up:int, down:int, results:array<int,array>}
     */
    public static function checkWebsitesBatch(array $sites, bool $notify = true, ?callable $onResult = null): array
    {
        $timeout = max(3, (int) setting('check_timeout', 15));
        $retries = max(0, (int) setting('retry_attempts', 2));
        $concurrency = max(1, min(200, (int) setting('check_concurrency', 25)));
        $byId = [];
        foreach ($sites as $s) $byId[$s['id']] = $s;

        $results = self::probeMany(array_map(fn($s) => $s['url'], $byId), $timeout, $concurrency);
        foreach ($results as $id => $r) $results[$id] = self::applyExpectation($byId[$id], $r);

        // Retry only the failures, concurrently, up to $retries times (with a short pause like the single check)
        for ($attempt = 0; $attempt < $retries; $attempt++) {
            $failed = array_filter($results, fn($r) => !$r['up']);
            if (!$failed) break;
            usleep(1500000);
            $retryUrls = [];
            foreach (array_keys($failed) as $id) $retryUrls[$id] = $byId[$id]['url']; // keep website ids as keys
            $again = self::probeMany($retryUrls, $timeout, $concurrency);
            foreach ($again as $id => $r) $results[$id] = self::applyExpectation($byId[$id], $r);
        }

        $up = 0; $down = 0;
        foreach ($results as $id => $r) {
            $final = self::checkWebsite($byId[$id], $notify, $r);
            $final['up'] ? $up++ : $down++;
            if ($onResult) $onResult($byId[$id], $final);
        }
        return ['up' => $up, 'down' => $down, 'results' => $results];
    }

    /**
     * Fetch many URLs in parallel with curl_multi. Returns results keyed like $urls (same shape as httpProbe()).
     */
    public static function probeMany(array $urls, int $timeout = 15, int $concurrency = 25): array
    {
        $results = [];
        $queue = $urls;
        $mh = curl_multi_init();
        $active = [];
        $add = function () use (&$queue, &$active, $mh, $timeout) {
            $key = array_key_first($queue);
            if ($key === null) return false;
            $url = $queue[$key];
            unset($queue[$key]);
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 5,
                CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_USERAGENT      => self::UA,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_ENCODING       => '',
                CURLOPT_HTTPHEADER     => ['Accept: text/html,application/xhtml+xml,*/*;q=0.8', 'Accept-Language: en'],
                CURLOPT_PRIVATE        => (string) $key,
            ]);
            curl_multi_add_handle($mh, $ch);
            $active[spl_object_id($ch)] = ['key' => $key, 'url' => $url, 'start' => microtime(true), 'handle' => $ch];
            return true;
        };
        for ($i = 0; $i < $concurrency; $i++) { if (!$add()) break; }

        do {
            $status = curl_multi_exec($mh, $running);
            if ($running) curl_multi_select($mh, 0.5);
            while ($info = curl_multi_info_read($mh)) {
                $ch = $info['handle'];
                $meta = $active[spl_object_id($ch)] ?? null;
                if ($meta === null) { curl_multi_remove_handle($mh, $ch); continue; }
                $body = curl_multi_getcontent($ch);
                $results[$meta['key']] = self::classifyProbe(
                    $meta['url'], $info['result'], curl_error($ch), (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
                    (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL), (int) curl_getinfo($ch, CURLINFO_REDIRECT_COUNT),
                    is_string($body) ? $body : '', (int) round((microtime(true) - $meta['start']) * 1000), $timeout
                );
                curl_multi_remove_handle($mh, $ch);
                unset($active[spl_object_id($ch)]);
                $add();
                $running = 1; // keep looping while new handles were added
            }
        } while (($running || $active) && $status === CURLM_OK);
        curl_multi_close($mh);
        return $results;
    }

    /**
     * Raw HTTP probe.
     * Returns: up, status, reason (short category), http_code, response_ms, error (details), final_url, body
     */
    public static function httpProbe(string $url, int $timeout = 15): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_USERAGENT      => self::UA,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADER         => false,
            CURLOPT_ENCODING       => '',
            CURLOPT_HTTPHEADER     => ['Accept: text/html,application/xhtml+xml,*/*;q=0.8', 'Accept-Language: en'],
        ]);
        $start = microtime(true);
        $body = curl_exec($ch);
        $ms = (int) round((microtime(true) - $start) * 1000);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $final = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $redirects = (int) curl_getinfo($ch, CURLINFO_REDIRECT_COUNT);
        curl_close($ch);
        return self::classifyProbe($url, $errno, $err, $code, $final, $redirects, is_string($body) ? $body : '', $ms, $timeout);
    }

    /** Turn raw cURL results into the CRM status/reason (shared by the single and the concurrent probe). */
    private static function classifyProbe(string $url, int $errno, string $err, int $code, string $final, int $redirects, string $body, int $ms, int $timeout): array
    {
        $status = 'down';
        $reason = 'Connection Failed';
        $up = false;
        $error = null;

        if ($errno) {
            if ($errno === CURLE_OPERATION_TIMEDOUT) {
                $status = 'timeout';
                $reason = 'Connection Timeout';
                $error = 'The server did not respond within ' . $timeout . ' seconds.';
            } elseif ($errno === CURLE_COULDNT_RESOLVE_HOST) {
                $reason = 'DNS Resolution Failed';
                $error = 'The domain could not be resolved. Check the domain registration and nameservers/DNS records.';
            } elseif ($errno === CURLE_COULDNT_CONNECT) {
                $reason = 'Connection Refused';
                $error = 'The server rejected the connection (port closed, web server stopped or firewall).' . ($err ? ' ' . $err : '');
            } elseif (in_array($errno, [35, 51, 53, 54, 58, 59, 60, 64, 66, 77, 80, 83, 90, 91], true)) {
                $status = 'ssl_error';
                $reason = 'SSL Certificate Error';
                $error = 'SSL certificate validation failed: ' . $err;
            } elseif ($errno === CURLE_TOO_MANY_REDIRECTS) {
                $reason = 'Redirect Loop';
                $error = 'Too many redirects.';
            } elseif ($errno === CURLE_RECV_ERROR || $errno === CURLE_SEND_ERROR || $errno === CURLE_GOT_NOTHING) {
                $reason = 'Connection Reset';
                $error = 'The connection was reset or the server returned an empty reply. ' . $err;
            } else {
                $error = 'cURL error ' . $errno . ': ' . $err;
            }
        } elseif ($code >= 500) {
            $status = 'server_error';
            $reason = $code === 503 ? 'Service Unavailable' : ($code === 502 ? 'Bad Gateway' : ($code === 504 ? 'Gateway Timeout' : 'Server Error'));
            $error = 'HTTP ' . $code . ' ' . self::httpText($code);
        } elseif ($code >= 400) {
            $status = 'down';
            $reason = $code === 404 ? 'Page Not Found' : ($code === 403 ? 'Access Forbidden' : ($code === 401 ? 'Authentication Required' : ($code === 429 ? 'Rate Limited' : 'Client Error')));
            $error = 'HTTP ' . $code . ' ' . self::httpText($code);
        } elseif ($code >= 200 && $code < 400) {
            $up = true;
            $status = 'online';
            $reason = null;
            if ($redirects > 0 && host_from_url($final) !== host_from_url($url)) {
                $status = 'redirecting';
                $error = 'Redirects to ' . $final;
            }
            $placeholder = self::detectPlaceholder($body, $final, $timeout);
            if ($placeholder !== null) {
                $up = false;
                $status = 'parked';
                $reason = 'Parking / Placeholder Page';
                $error = $placeholder;
            }
        } else {
            $reason = 'No Response';
            $error = 'Unexpected response (HTTP ' . $code . ')';
        }

        return [
            'up'          => $up,
            'status'      => $status,
            'reason'      => $reason,
            'http_code'   => $code ?: null,
            'response_ms' => $ms,
            'error'       => $error,
            'final_url'   => $final,
            'body'        => $body,
        ];
    }

    /**
     * Detect domain-parking / placeholder / default-server pages that return HTTP 200 but do not serve the real website.
     */
    public static function detectPlaceholder(string $body, string $finalUrl, int $timeout = 15): ?string
    {
        if (strlen($body) < 3000 && preg_match('~(?:window\.)?location(?:\.href)?\s*=\s*["\']([^"\']+)["\']~i', $body, $m)) {
            $target = $m[1];
            if (!preg_match('~^https?://~i', $target)) {
                $p = parse_url($finalUrl);
                $target = ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '') . (str_starts_with($target, '/') ? $target : '/' . $target);
            }
            $ch = curl_init($target);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5, CURLOPT_TIMEOUT => $timeout, CURLOPT_USERAGENT => self::UA, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_ENCODING => '']);
            $body2 = (string) curl_exec($ch);
            $final2 = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
            curl_close($ch);
            if (preg_match('~/lander(?:[/?#]|$)~i', $target) || preg_match('~/lander(?:[/?#]|$)~i', $final2)) {
                return 'Domain parking page detected (GoDaddy lander) – the website is not being served';
            }
            $body = $body2 !== '' ? $body2 : $body;
            $finalUrl = $final2 ?: $finalUrl;
        }

        $haystack = strtolower($body . ' ' . $finalUrl);
        $signatures = [
            'wsimg.com/parking-lander'            => 'GoDaddy domain parking page',
            'parking-lander'                      => 'GoDaddy domain parking page',
            'img1.wsimg.com/parking'              => 'GoDaddy domain parking page',
            'sedoparking.com'                     => 'Sedo domain parking page',
            'parkingcrew.net'                     => 'ParkingCrew domain parking page',
            'bodis.com'                           => 'Bodis domain parking page',
            'afternic.com'                        => 'Afternic "domain for sale" page',
            'dan.com/buy-domain'                  => 'Dan.com "domain for sale" page',
            'hugedomains.com'                     => 'HugeDomains "domain for sale" page',
            'this domain is parked'               => 'Domain parking page',
            'domain is parked'                    => 'Domain parking page',
            'is parked free'                      => 'Domain parking page',
            'this domain may be for sale'         => 'Domain for sale page',
            'domain is for sale'                  => 'Domain for sale page',
            'buy this domain'                     => 'Domain for sale page',
            'this domain has expired'             => 'Expired domain notice page',
            'domain has expired'                  => 'Expired domain notice page',
            'apache2 ubuntu default page'         => 'Default Apache server page',
            'apache http server test page'        => 'Default Apache server page',
            'it works!</h1>'                      => 'Default Apache "It works!" page',
            'welcome to nginx!'                   => 'Default nginx server page',
            'iis windows server'                  => 'Default IIS server page',
            'plesk default page'                  => 'Plesk default page',
            'default web site page'               => 'cPanel default web page',
            'future home of something quite cool' => 'cPanel placeholder page',
            'this account has been suspended'     => 'Hosting account suspended page',
            'account has been suspended'          => 'Hosting account suspended page',
            'this site is currently unavailable'  => 'Hosting "site unavailable" page',
            'website is under construction'       => 'Under-construction placeholder page',
            'site is under construction'          => 'Under-construction placeholder page',
            'website coming soon'                 => 'Coming-soon placeholder page',
            'hostinger-default-page'              => 'Hostinger default page',
            '<title>index of /</title>'           => 'Directory listing instead of a website',
        ];
        foreach (preg_split('~[\r\n,]+~', (string) setting('placeholder_keywords', '')) as $extra) {
            $extra = strtolower(trim($extra));
            if ($extra !== '') $signatures[$extra] = 'Custom placeholder keyword "' . $extra . '"';
        }
        foreach ($signatures as $needle => $label) {
            if (str_contains($haystack, $needle)) {
                return $label . ' detected – the website is not being served';
            }
        }
        // Practically empty response on HTTP 200: no text and no interactive/visual elements at all
        $text = trim(strip_tags($body));
        if ($body !== '' && strlen($text) < 20 && !preg_match('~<(frame|iframe|script|form|input|img|video|canvas|svg|object|embed)\b~i', $body)) {
            return 'Empty page returned (no content) – the website is not being served';
        }
        return null;
    }

    public static function httpText(int $code): string
    {
        $map = [400 => 'Bad Request', 401 => 'Unauthorized', 403 => 'Forbidden', 404 => 'Not Found', 405 => 'Method Not Allowed', 408 => 'Request Timeout', 429 => 'Too Many Requests',
            500 => 'Internal Server Error', 502 => 'Bad Gateway', 503 => 'Service Unavailable', 504 => 'Gateway Timeout'];
        return $map[$code] ?? '';
    }

    /* =====================================================================
     * SSL
     * ===================================================================== */

    /**
     * Validate the live TLS connection and the certificate (availability, trust chain, hostname match, validity period,
     * expiry) every 5 minutes and keep an incident open while it fails:
     *   VALID → FAILED   one "SSL Certificate Failed" email      FAILED → FAILED   nothing (no duplicates)
     *   FAILED → VALID   one "SSL Certificate Recovered" email    expiring soon     one warning per threshold (30 / 10 days)
     * Result: status (valid|expiring_soon|expired|error|unknown), expiry, days, issuer, subject, error, checks[]
     */
    public static function checkSsl(array $website, bool $notify = true): array
    {
        $host = parse_url($website['url'], PHP_URL_HOST);
        $port = (int) (parse_url($website['url'], PHP_URL_PORT) ?: 443);
        $now = date('Y-m-d H:i:s');
        $result = ['status' => 'error', 'expiry' => null, 'days' => null, 'issuer' => null, 'error' => null, 'subject' => null, 'protocol' => null,
            'checks' => ['connection' => null, 'chain' => null, 'hostname' => null, 'validity' => null, 'expiry' => null]];

        if (!$host || stripos($website['url'], 'https://') !== 0) {
            // Plain HTTP site: nothing to check, and no alert should be raised.
            $result['status'] = 'unknown';
            $result['error'] = 'Website is not using HTTPS – no certificate to monitor';
        } else {
            // Full TLS handshake with verification; a pure connection problem is retried once (transient network hiccups)
            $cert = self::fetchCertificate($host, $port, true, $verifyError, $proto);
            if (!$cert && !preg_match('~certificate|verify|handshake|ssl|tls~i', (string) $verifyError)) {
                usleep(1500000);
                $cert = self::fetchCertificate($host, $port, true, $verifyError, $proto);
            }
            $verified = $cert !== null;
            $result['protocol'] = $proto ?? null;
            $chainTrusted = $verified;
            if (!$cert) {
                // chain-only verification: a hostname mismatch must not be reported as an untrusted chain
                $chainTrusted = self::fetchCertificate($host, $port, true, $chainError, $p2, false) !== null;
                $cert = self::fetchCertificate($host, $port, false, $fetchError, $proto);
                $result['protocol'] = $proto ?? $result['protocol'];
            }
            $result['checks']['connection'] = $cert !== null;
            if (!$cert) {
                $result['error'] = 'TLS connection failed: ' . ($fetchError ?: $verifyError ?: 'connection refused');
            } else {
                $result['checks']['chain'] = $chainTrusted;
                $parsed = openssl_x509_parse($cert);
                if (!$parsed) {
                    $result['error'] = 'Could not parse the certificate presented by the server';
                } else {
                    $validFrom = (int) ($parsed['validFrom_time_t'] ?? 0);
                    $validTo = (int) ($parsed['validTo_time_t'] ?? 0);
                    $result['expiry'] = $validTo ? date('Y-m-d H:i:s', $validTo) : null;
                    $result['days'] = $validTo ? (int) floor(($validTo - time()) / 86400) : null;
                    $result['issuer'] = trim(($parsed['issuer']['O'] ?? '') . ' ' . ($parsed['issuer']['CN'] ?? '')) ?: null;
                    $result['subject'] = $parsed['subject']['CN'] ?? null;
                    $matches = self::certMatchesHost($parsed, $host);
                    $result['checks']['hostname'] = $matches;
                    $result['checks']['validity'] = $validFrom <= time() && (!$validTo || $validTo >= time());
                    $result['checks']['expiry'] = $result['days'] === null || $result['days'] > (int) Tenant::setting('ssl_warning_days', 30, (int) ($website['tenant_id'] ?? 0) ?: null);
                    $warnDays = (int) Tenant::setting('ssl_warning_days', 30, (int) ($website['tenant_id'] ?? 0) ?: null);
                    if ($validTo && $validTo < time()) {
                        $result['status'] = 'expired';
                        $result['error'] = 'Certificate expired on ' . date('d-M-Y', $validTo);
                    } elseif ($validFrom && $validFrom > time()) {
                        $result['status'] = 'error';
                        $result['error'] = 'Certificate is not valid yet (valid from ' . date('d-M-Y H:i', $validFrom) . ')';
                    } elseif (!$matches) {
                        $result['status'] = 'error';
                        $result['error'] = 'Hostname mismatch – certificate issued for ' . ($result['subject'] ?: 'unknown') . ', not ' . $host;
                    } elseif (!$verified) {
                        $result['status'] = 'error';
                        $result['error'] = 'Certificate chain not trusted: ' . ($verifyError ?: 'verification failed');
                    } elseif ($result['days'] !== null && $result['days'] <= $warnDays) {
                        $result['status'] = 'expiring_soon';
                    } else {
                        $result['status'] = 'valid';
                    }
                }
            }
        }

        $failed = in_array($result['status'], ['expired', 'error'], true);
        $healthy = in_array($result['status'], ['valid', 'expiring_soon'], true);
        $update = [
            'ssl_status'        => $result['status'],
            'ssl_expires_at'    => $result['expiry'],
            'ssl_days_left'     => $result['days'],
            'ssl_issuer'        => $result['issuer'] ? mb_substr($result['issuer'], 0, 190) : null,
            'ssl_error'         => $result['error'] ? mb_substr($result['error'], 0, 500) : null,
            'ssl_checked_at'    => $now,
            'ssl_failed_checks' => $failed ? (int) ($website['ssl_failed_checks'] ?? 0) + 1 : 0,
        ];
        if ($healthy) $update['ssl_last_valid_at'] = $now;
        DB::update('websites', $update, 'id = ?', [$website['id']]);

        // History: every status change and every failing check; healthy checks are sampled (every 6 h) so a 5-minute cycle stays light
        $last = DB::fetch("SELECT status, checked_at FROM ssl_monitoring WHERE website_id = ? ORDER BY id DESC LIMIT 1", [$website['id']]);
        if (!$last || $last['status'] !== $result['status'] || $failed || strtotime($last['checked_at']) < time() - 6 * 3600) {
            DB::insert('ssl_monitoring', [
                'website_id'     => $website['id'],
                'checked_at'     => $now,
                'status'         => $result['status'],
                'expiry_date'    => $result['expiry'],
                'days_remaining' => $result['days'],
                'issuer'         => $result['issuer'] ? mb_substr($result['issuer'], 0, 190) : null,
                'error_message'  => $result['error'] ? mb_substr($result['error'], 0, 500) : null,
            ]);
        }

        // Incident state machine
        $open = DB::fetch("SELECT * FROM ssl_incidents WHERE website_id = ? AND resolved_at IS NULL ORDER BY id DESC LIMIT 1", [$website['id']]);
        $site = array_merge($website, $update);
        if ($failed) {
            if (!$open) {
                $id = DB::insert('ssl_incidents', ['tenant_id' => $website['tenant_id'] ?? null, 'website_id' => $website['id'], 'started_at' => $now, 'status' => $result['status'], 'error_message' => $update['ssl_error'], 'failed_checks' => 1, 'last_checked_at' => $now]);
                $open = DB::fetch("SELECT * FROM ssl_incidents WHERE id = ?", [$id]);
            } else {
                DB::update('ssl_incidents', ['failed_checks' => (int) $open['failed_checks'] + 1, 'last_checked_at' => $now, 'status' => $result['status'], 'error_message' => $update['ssl_error']], 'id = ?', [$open['id']]);
                Notifier::alertSeen('ssl_failed:' . $open['id'], null, $update['ssl_error']);
                $open['failed_checks'] = (int) $open['failed_checks'] + 1;
            }
            if ($notify && !$open['alert_sent']) {
                Notifier::sslFailed($site, $open, $result);
                DB::update('ssl_incidents', ['alert_sent' => 1], 'id = ?', [$open['id']]);
            }
        } elseif ($open && $healthy) {
            $duration = max(0, strtotime($now) - strtotime($open['started_at']));
            DB::update('ssl_incidents', ['resolved_at' => $now, 'duration_seconds' => $duration, 'last_checked_at' => $now, 'recovery_sent' => ($notify && $open['alert_sent']) ? 1 : 0], 'id = ?', [$open['id']]);
            $open['resolved_at'] = $now;
            $open['duration_seconds'] = $duration;
            if ($notify && $open['alert_sent']) Notifier::sslRecovered($site, $open, $result);
        }
        if ($notify && $result['status'] === 'expiring_soon') {
            Notifier::sslAlert($site, 'expiring_soon', $result['days'], $result['expiry'] ? substr($result['expiry'], 0, 10) : null, $result['error']);
        }
        return $result + ['checked_at' => $now, 'failed' => $failed];
    }

    /** $verifyName = false checks the trust chain only (used to tell "untrusted chain" from "hostname mismatch"). */
    private static function fetchCertificate(string $host, int $port, bool $verify, ?string &$error = null, ?string &$protocol = null, bool $verifyName = true)
    {
        $error = null;
        $protocol = null;
        $ctx = stream_context_create(['ssl' => [
            'capture_peer_cert' => true,
            'verify_peer'       => $verify,
            'verify_peer_name'  => $verify && $verifyName,
            'allow_self_signed' => !$verify,
            'SNI_enabled'       => true,
            'peer_name'         => $host,
        ]]);
        $timeout = max(3, (int) setting('check_timeout', 15));
        // PHP reports the real reason (certificate verify failed / CN did not match) in the FIRST warning; error_get_last()
        // only holds the last one ("Unable to connect to ssl://…"), so every warning is captured here.
        $warnings = [];
        set_error_handler(function ($no, $msg) use (&$warnings) { $warnings[] = $msg; return true; });
        try { $client = stream_socket_client('ssl://' . $host . ':' . $port, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx); }
        finally { restore_error_handler(); }
        if (!$client) {
            $error = null;
            foreach ($warnings as $m) {
                if (preg_match('~certificate|verify|did not match|handshake|SSL|TLS~i', $m) && !preg_match('~Failed to enable crypto|Unable to connect to~i', $m)) { $error = $m; break; }
            }
            if ($error === null) $error = $errstr ?: ($warnings ? end($warnings) : ('error ' . $errno));
            $error = preg_replace('~^stream_socket_client\(\):\s*~', '', $error);
            $error = preg_replace('~.*?(OpenSSL Error messages:|SSL operation failed[^:]*:)\s*~s', '', $error);
            $error = trim(preg_replace('~error:[0-9A-F]+:|SSL routines::~', '', $error));
            if ($error === '' || $error === 'error 0') $error = 'connection failed (timeout after ' . $timeout . ' s)';
            return null;
        }
        $params = stream_context_get_params($client);
        $meta = stream_get_meta_data($client);
        $protocol = $meta['crypto']['protocol'] ?? null;
        fclose($client);
        return $params['options']['ssl']['peer_certificate'] ?? null;
    }

    private static function certMatchesHost(array $parsed, string $host): bool
    {
        $names = [];
        if (!empty($parsed['subject']['CN'])) $names[] = $parsed['subject']['CN'];
        if (!empty($parsed['extensions']['subjectAltName'])) {
            foreach (explode(',', $parsed['extensions']['subjectAltName']) as $san) {
                $san = trim($san);
                if (stripos($san, 'DNS:') === 0) $names[] = trim(substr($san, 4));
            }
        }
        $host = strtolower($host);
        foreach ($names as $n) {
            $n = strtolower($n);
            if ($n === $host) return true;
            if (str_starts_with($n, '*.')) {
                $suffix = substr($n, 1);
                if (str_ends_with($host, $suffix) && substr_count($host, '.') === substr_count($n, '.')) return true;
            }
        }
        return false;
    }

    /* =====================================================================
     * FORMS
     * ===================================================================== */

    /** Failure reasons that are configuration problems – re-testing immediately cannot change them. */
    const FORM_CONFIG_REASONS = ['Form selector not found', 'Popup trigger not found', 'Submit button not found', 'Browser engine unavailable', 'Browser engine required', 'Form not found on page', 'Popup not found', 'Form is not visible'];

    /**
     * Test a form for real (FormTester: HTTP engine or headless-browser engine), store the result and maintain the
     * form incident so alerts follow the state machine:
     *   WORKING → FAILED   one failure email (after an immediate confirmation re-test)
     *   FAILED  → FAILED   nothing – no duplicate emails while the same problem continues
     *   FAILED  → WORKING  one recovery email with failed-since / recovered-at / total downtime
     * Result: success, reason, error, http_code, ajax_status, response_ms, excerpt, final_url, email_received, mode, engine, steps, tested_at, test_id
     */
    public static function testForm(array $form, bool $notify = true): array
    {
        $website = DB::fetch("SELECT * FROM websites WHERE id = ?", [$form['website_id']]);
        if (!$website) throw new RuntimeException('Website #' . $form['website_id'] . ' not found for form #' . $form['id']);
        // job lock: the same form is never tested by two workers at once
        if (!Tenant::lock('form:' . $form['id'], 300)) {
            return ['success' => $form['status'] === 'working', 'reason' => 'Test already running', 'error' => 'Another worker is testing this form right now – skipped.', 'skipped' => true, 'outcome' => $form['last_outcome'] ?? null,
                'http_code' => null, 'ajax_status' => null, 'response_ms' => null, 'excerpt' => null, 'final_url' => null, 'email_received' => 'unknown', 'mode' => 'submission', 'engine' => 'http', 'steps' => [], 'wp_plugin' => null, 'token' => '', 'captcha' => null, 'interference' => null,
                'tested_at' => null, 'test_id' => 0, 'screenshot_path' => null, 'status' => $form['status'], 'previous_status' => $form['status'], 'blocked' => false, 'outcome_label' => 'Skipped (running)'];
        }
        try { return self::testFormLocked($form, $website, $notify); } finally { Tenant::unlock('form:' . $form['id']); }
    }

    private static function testFormLocked(array $form, array $website, bool $notify): array
    {
        $now = date('Y-m-d H:i:s');
        $t0 = microtime(true);

        $r = FormTester::run($form, $website);
        // Confirm a failure with one more attempt before it is reported (transient errors must not raise alerts)
        $retries = max(0, min(2, (int) setting('form_confirm_retry', 1)));
        $attempts = 1;
        while (!$r['success'] && $attempts <= $retries && !in_array($r['reason'], self::FORM_CONFIG_REASONS, true) && !in_array($r['outcome'] ?? '', ['captcha_blocked', 'interference', 'config_error'], true)) {
            usleep(2000000);
            $attempts++;
            $again = FormTester::run($form, $website);
            $again['steps'] = array_merge($r['steps'], [FormTester::step('retry', null, 'Failure confirmation – attempt ' . $attempts)], $again['steps']);
            $r = $again;
        }
        $r['attempts'] = $attempts;
        $r['duration_ms'] = (int) round((microtime(true) - $t0) * 1000);
        $shot = !$r['success'] ? FormTester::saveScreenshot($r['screenshot'], (int) $form['id']) : null;
        unset($r['screenshot']);

        // Precise outcome: working / email_unknown / failed / timeout / js_error / network_error / captcha_blocked / interference / config_error
        $outcome = $r['outcome'] ?: FormTester::classifyOutcome($r);
        $r['outcome'] = $outcome;
        $def = form_outcomes()[$outcome] ?? form_outcomes()['failed'];
        $resultVal = $def[2];                 // success | failed | blocked
        $blocked = $resultVal === 'blocked';  // CAPTCHA / anti-bot / third-party widget – NOT a form failure, never an alert
        $alerts = $def[3];                    // real failures only

        $testId = DB::insert('form_tests', [
            'form_id'        => $form['id'],
            'tested_at'      => $now,
            'test_mode'      => $r['mode'],
            'engine'         => $r['engine'],
            'result'         => $resultVal,
            'outcome'        => $outcome,
            'captcha_type'   => $r['captcha'] ? mb_substr((string) $r['captcha'], 0, 30) : null,
            'interference'   => $r['interference'] ? mb_substr((string) $r['interference'], 0, 190) : null,
            'failure_reason' => $r['reason'] ? mb_substr($r['reason'], 0, 120) : null,
            'http_code'      => $r['http_code'],
            'response_time'  => $r['response_ms'],
            'response_text'  => $r['excerpt'] ? mb_substr($r['excerpt'], 0, 500) : null,
            'final_url'      => $r['final_url'] ? mb_substr($r['final_url'], 0, 500) : null,
            'ajax_status'    => $r['ajax_status'],
            'steps'          => json_encode(array_values($r['steps']), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'screenshot'     => $shot,
            'email_received' => $r['email_received'],
            'error'          => $r['error'] ? mb_substr($r['error'], 0, 500) : null,
            'test_token'     => $r['token'],
        ]);
        $test = DB::fetch("SELECT * FROM form_tests WHERE id = ?", [$testId]);

        $prevStatus = $form['status'];
        $newStatus = $r['success'] ? 'working' : ($blocked ? 'captcha_blocked' : 'failed');
        $pageOk = null; $formFound = null;
        foreach ($r['steps'] as $st) { if ($st['step'] === 'page' && $st['ok'] !== null) $pageOk = $st['ok'] ? 1 : 0; if ($st['step'] === 'form' && $st['ok'] !== null) $formFound = $st['ok'] ? 1 : 0; }
        $upd = [
            'status'              => $newStatus,
            'last_page_ok'        => $pageOk,
            'last_form_found'     => $formFound,
            'last_tested_at'      => $now,
            'last_result'         => $resultVal,
            'last_outcome'        => $outcome,
            'last_email_received' => $r['email_received'] ?? 'unknown',
            'captcha_detected'    => $r['captcha'] ? mb_substr((string) $r['captcha'], 0, 30) : null,
            'last_response_code'  => $r['http_code'],
            'last_ajax_status'    => $r['ajax_status'],
            'last_engine'         => $r['engine'],
            'last_final_url'      => $r['final_url'] ? mb_substr($r['final_url'], 0, 500) : null,
            'last_response_text'  => $r['excerpt'] ? mb_substr($r['excerpt'], 0, 500) : null,
            'last_error'          => $r['error'] ? mb_substr($r['error'], 0, 500) : null,
            'last_failure_reason' => $r['reason'] ? mb_substr($r['reason'], 0, 120) : null,
            'failed_tests'        => $r['success'] ? 0 : ($blocked ? (int) ($form['failed_tests'] ?? 0) : (int) ($form['failed_tests'] ?? 0) + 1),
            'total_failures'      => (int) ($form['total_failures'] ?? 0) + (($r['success'] || $blocked) ? 0 : 1),
            'total_success'       => (int) ($form['total_success'] ?? 0) + ($r['success'] ? 1 : 0),
        ];
        if ($r['success']) $upd['last_success_at'] = $now; elseif (!$blocked) $upd['last_failed_at'] = $now;
        if (!empty($r['wp_plugin']) && empty($form['wp_plugin'])) $upd['wp_plugin'] = $r['wp_plugin'];
        DB::update('forms', $upd, 'id = ?', [$form['id']]);
        $formNow = array_merge($form, $upd);
        if ($prevStatus !== $newStatus) FormDiscovery::recordStatus((int) $form['id'], $prevStatus, $newStatus, $r['reason'] ?: ($r['success'] ? 'Test passed' : null));
        FormDiscovery::refreshCounts((int) $website['id']);

        ActivityLog::add('form_tested', 'Form tested: ' . $form['name'] . ' on ' . $website['name'] . ' – ' . ($r['success'] ? 'WORKING' : ($blocked ? 'BLOCKED: ' : ($outcome === 'config_error' ? 'CONFIGURATION ERROR: ' : 'FAILED: ')) . $r['reason']) . ' (' . $r['engine'] . ' engine)',
            ['client_id' => $website['client_id'], 'website_id' => $website['id'], 'form_id' => $form['id']]);

        // Blocked by CAPTCHA / anti-bot / third-party widget: separate monitoring status, in-app notice once, NO failure alert email
        if ($blocked && $notify && $prevStatus !== 'captcha_blocked') Notifier::formBlocked($formNow, $website, $test);
        // Monitoring configuration problem (wrong selector, browser engine missing…): in-app notice once, no failure alert email
        if (!$r['success'] && !$blocked && !$alerts && $notify && $prevStatus !== 'failed') Notifier::formConfigError($formNow, $website, $test);

        // Incident state machine – only real form failures open incidents and send failure / recovery alerts
        $open = DB::fetch("SELECT * FROM form_incidents WHERE form_id = ? AND resolved_at IS NULL ORDER BY id DESC LIMIT 1", [$form['id']]);
        if (!$r['success'] && !$blocked && $alerts) {
            if (!$open) {
                $id = DB::insert('form_incidents', ['tenant_id' => $website['tenant_id'] ?? null, 'form_id' => $form['id'], 'website_id' => $website['id'], 'started_at' => $now, 'failure_reason' => $upd['last_failure_reason'], 'error_message' => $upd['last_error'], 'http_code' => $r['http_code'], 'failed_tests' => 1, 'last_tested_at' => $now]);
                $open = DB::fetch("SELECT * FROM form_incidents WHERE id = ?", [$id]);
            } else {
                DB::update('form_incidents', ['failed_tests' => (int) $open['failed_tests'] + 1, 'last_tested_at' => $now, 'failure_reason' => $upd['last_failure_reason'], 'error_message' => $upd['last_error'], 'http_code' => $r['http_code']], 'id = ?', [$open['id']]);
                Notifier::alertSeen('form_failed:' . $open['id'], $upd['last_failure_reason'], $upd['last_error']);
                $open['failed_tests'] = (int) $open['failed_tests'] + 1;
                $open['failure_reason'] = $upd['last_failure_reason'];
                $open['error_message'] = $upd['last_error'];
            }
            if ($notify && !$open['alert_sent']) {
                Notifier::formFailed($formNow, $website, $test, $open);
                DB::update('form_incidents', ['alert_sent' => 1], 'id = ?', [$open['id']]);
            }
        } elseif ($open && $r['success']) {
            $duration = max(0, strtotime($now) - strtotime($open['started_at']));
            DB::update('form_incidents', ['resolved_at' => $now, 'duration_seconds' => $duration, 'last_tested_at' => $now, 'recovery_sent' => ($notify && $open['alert_sent']) ? 1 : 0], 'id = ?', [$open['id']]);
            $open['resolved_at'] = $now;
            $open['duration_seconds'] = $duration;
            DB::query("UPDATE forms SET total_downtime = total_downtime + ? WHERE id = ?", [$duration, $form['id']]);
            if ($notify && $open['alert_sent']) Notifier::formRecovered($formNow, $website, $test, $open);
            elseif ($prevStatus === 'failed') ActivityLog::add('form_recovered', 'Form recovered: ' . $form['name'] . ' on ' . $website['name'], ['client_id' => $website['client_id'], 'website_id' => $website['id'], 'form_id' => $form['id']]);
        }
        return $r + ['tested_at' => $now, 'test_id' => $testId, 'screenshot_path' => $shot, 'status' => $newStatus, 'previous_status' => $prevStatus, 'blocked' => $blocked, 'outcome_label' => form_outcome_label($outcome)];
    }

    /* =====================================================================
     * EXPIRY (domain / hosting)
     * ===================================================================== */

    public static function checkExpiry(): array
    {
        $domainDays = self::thresholds(setting('domain_alert_days', '30,10'));
        $hostingDays = self::thresholds(setting('hosting_alert_days', '30,10'));
        $counts = ['domain' => 0, 'hosting' => 0];
        $active = ['domain' => [], 'hosting' => []]; // records that still cross a threshold (their alert stays open)

        $domains = DB::fetchAll("SELECT d.*, d.domain_name AS label, d.registrar AS provider, c.name AS client_name, c.email AS client_email, c.notify_client, c.assigned_user_id, w.name AS website_name
            FROM domains d LEFT JOIN clients c ON c.id = d.client_id LEFT JOIN websites w ON w.id = d.website_id
            WHERE d.expiry_date IS NOT NULL AND (c.id IS NULL OR c.status <> 'archived')");
        foreach ($domains as $d) {
            $days = days_until($d['expiry_date']);
            if ($days === null) continue;
            $t = self::crossedThreshold($days, !empty($d['tenant_id']) ? self::thresholds(Tenant::setting('domain_alert_days', '30,10', (int) $d['tenant_id'])) : $domainDays);
            if ($t !== null) {
                $active['domain'][] = (int) $d['id'];
                $d['threshold'] = $t;
                if (Notifier::expiryAlert('domain', $d, $days)) $counts['domain']++;
            }
        }
        $hosting = DB::fetchAll("SELECT h.*, CONCAT(h.provider, IF(h.plan IS NULL OR h.plan = '', '', CONCAT(' – ', h.plan))) AS label, c.name AS client_name, c.email AS client_email, c.notify_client, c.assigned_user_id, w.name AS website_name
            FROM hosting h LEFT JOIN clients c ON c.id = h.client_id LEFT JOIN websites w ON w.id = h.website_id
            WHERE h.expiry_date IS NOT NULL AND (c.id IS NULL OR c.status <> 'archived')");
        foreach ($hosting as $h) {
            $days = days_until($h['expiry_date']);
            if ($days === null) continue;
            $t = self::crossedThreshold($days, !empty($h['tenant_id']) ? self::thresholds(Tenant::setting('hosting_alert_days', '30,10', (int) $h['tenant_id'])) : $hostingDays);
            if ($t !== null) {
                $active['hosting'][] = (int) $h['id'];
                $h['threshold'] = $t;
                if (Notifier::expiryAlert('hosting', $h, $days)) $counts['hosting']++;
            }
        }
        // Renewed (new expiry date beyond every threshold), date cleared or record deleted: close the earlier alerts so
        // the alert history / dashboards stop showing the old "expiring" / "expired" state. No email – nothing is wrong any more.
        foreach ($active as $kind => $ids) {
            try {
                $stale = DB::fetchAll("SELECT alert_key FROM alerts WHERE kind = ? AND status <> 'recovered'" . ($ids ? ' AND target_id NOT IN (' . implode(',', $ids) . ')' : ''), [$kind]);
                if ($stale) Notifier::alertRecover(array_column($stale, 'alert_key'), null, ['current_status' => 'renewed']);
            } catch (Throwable $e) {
                app_log('warning', 'Closing renewed ' . $kind . ' alerts failed: ' . $e->getMessage());
            }
        }
        return $counts;
    }

    public static function thresholds(string $csv): array
    {
        $t = array_values(array_unique(array_filter(array_map('intval', preg_split('~[,\s]+~', $csv)))));
        sort($t);
        return $t ?: [30, 10];
    }

    /** Most urgent threshold crossed (smallest threshold >= days), or 0 when expired. */
    public static function crossedThreshold(int $days, array $thresholds): ?int
    {
        if ($days < 0) return 0;
        foreach ($thresholds as $t) {
            if ($days <= $t) return $t;
        }
        return null;
    }

    /* =====================================================================
     * PAGE-LEVEL MONITORING – every important page of a website, not just the homepage.
     *
     *   discoverPages()   builds the page list from sitemap.xml / WordPress wp-sitemap.xml / robots.txt / internal links
     *   scanPagesBatch()  checks every active page of many websites concurrently, stores results, opens/closes
     *                     page incidents and queues ONE alert per incident + ONE recovery email.
     * ===================================================================== */

    /** Keywords that mark the important pages of a typical business website (lower = more important). */
    private const PAGE_PRIORITY = ['about' => 10, 'contact' => 15, 'service' => 20, 'product' => 30, 'shop' => 30, 'pricing' => 35, 'project' => 40, 'team' => 45, 'gallery' => 50,
        'portfolio' => 50, 'blog' => 60, 'news' => 60, 'career' => 65, 'faq' => 70, 'privacy' => 80, 'terms' => 85, 'disclaimer' => 88, 'refund' => 88];
    private const PAGE_SKIP_EXT = '~\.(jpe?g|png|gif|webp|avif|svg|ico|bmp|tiff?|pdf|docx?|xlsx?|pptx?|zip|rar|7z|gz|tar|mp3|mp4|m4a|avi|mov|wmv|webm|css|js|mjs|json|xml|txt|woff2?|ttf|otf|eot|csv|exe|apk|dmg)$~i';
    private const PAGE_SKIP_PATH = '~(/wp-admin|/wp-login|/wp-json|/wp-content/|/wp-includes/|/xmlrpc\.php|/feed/?$|/comments/feed|/cdn-cgi/|/cart/?$|/checkout|/my-account|/logout|/log-out|/sign-?in/?$|/log-?in/?$|/register/?$|/tag/|/author/|/page/\d+|/attachment/|/wp-sitemap|/sitemap|/search/?$|/\.well-known/|/administrator/?$|/admin/?$|/cpanel|/webmail)~i';

    /** Maximum number of pages monitored for a website (per-website override, else Settings → Monitoring). */
    public static function maxPages(array $website): int
    {
        $n = (int) ($website['max_pages'] ?? 0) ?: (int) setting('page_max_pages', 50);
        $n = max(1, min(500, $n));
        // plan limit: pages already monitored for this website + what the workspace still has left
        $tid = (int) ($website['tenant_id'] ?? 0);
        if ($tid) {
            $remaining = Tenant::remaining('pages', $tid);
            if ($remaining !== null) {
                $mine = (int) DB::value("SELECT COUNT(*) FROM website_pages WHERE website_id = ? AND is_active = 1", [$website['id']]);
                $n = max(1, min($n, $mine + $remaining));
            }
        }
        return $n;
    }

    /** Human label for a page path: "/about-us/" → "About Us", "/" → "Home". */
    public static function pathLabel(string $path): string
    {
        $path = trim(preg_replace('~\.(php|html?|aspx?|jsp)$~i', '', $path), '/');
        if ($path === '' || strtolower($path) === 'index' || strtolower($path) === 'home') return 'Home';
        $last = basename($path);
        return ucwords(str_replace(['-', '_', '.', '+', '%20'], ' ', urldecode($last)));
    }

    public static function pagePath(string $url): string
    {
        $p = parse_url($url);
        $path = $p['path'] ?? '/';
        if ($path === '') $path = '/';
        if (!empty($p['query'])) $path .= '?' . $p['query'];
        return mb_substr($path, 0, 255);
    }

    private static function pagePriority(string $path, string $source): int
    {
        if ($path === '/') return 0;
        if ($source === 'manual') return 5;
        $p = strtolower($path);
        $pr = 100;
        foreach (self::PAGE_PRIORITY as $k => $v) {
            if (str_contains($p, $k)) $pr = min($pr, $v);
        }
        return $pr + min(50, substr_count(trim($path, '/'), '/') * 10);
    }

    /**
     * Normalise a discovered link against the website; returns null when it is not an internal HTML page
     * (other host, file download, admin/login/feed URL, query-string variant, ignored pattern).
     */
    public static function normalizePageUrl(string $href, string $baseUrl, string $host, bool $allowQuery = false): ?string
    {
        $href = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($href === '' || $href[0] === '#') return null;
        if (preg_match('~^(mailto|tel|javascript|data|sms|whatsapp|skype|ftp|file|callto):~i', $href)) return null;
        $b = parse_url($baseUrl);
        $scheme = $b['scheme'] ?? 'https';
        if (str_starts_with($href, '//')) $href = $scheme . ':' . $href;
        if (!preg_match('~^https?://~i', $href)) {
            $origin = $scheme . '://' . ($b['host'] ?? '') . (isset($b['port']) ? ':' . $b['port'] : '');
            if (str_starts_with($href, '/')) {
                $href = $origin . $href;
            } elseif (str_starts_with($href, '?')) {
                $href = $origin . ($b['path'] ?? '/') . $href;
            } else {
                $dir = preg_replace('~[^/]*$~', '', $b['path'] ?? '/');
                $href = $origin . ($dir ?: '/') . $href;
            }
        }
        $p = parse_url($href);
        if (!$p || empty($p['host'])) return null;
        if (host_from_url($href) !== $host) return null;
        $path = $p['path'] ?? '/';
        $segs = [];
        foreach (explode('/', $path) as $seg) {
            if ($seg === '..') array_pop($segs);
            elseif ($seg !== '.') $segs[] = $seg;
        }
        $path = implode('/', $segs);
        if ($path === '') $path = '/';
        if ($path[0] !== '/') $path = '/' . $path;
        $path = preg_replace('~/{2,}~', '/', $path);
        if (preg_match(self::PAGE_SKIP_EXT, $path)) return null;
        // keep the website's own scheme/host spelling (http vs https, www vs non-www) so every page shares one origin
        $url = $scheme . '://' . ($b['host'] ?? $p['host']) . (isset($b['port']) ? ':' . $b['port'] : '') . $path;
        if (preg_match(self::PAGE_SKIP_PATH, $url)) return null;
        if (!empty($p['query'])) {
            if (!$allowQuery) return null; // filters / sorting / tracking parameters are not distinct pages
            $url .= '?' . $p['query'];
        }
        foreach (preg_split('~[\r\n,]+~', (string) setting('page_ignore_patterns', '')) as $pat) {
            $pat = trim($pat);
            if ($pat !== '' && stripos($url, $pat) !== false) return null;
        }
        return mb_substr($url, 0, 500);
    }

    /** Lean GET used by discovery (no placeholder detection). Returns [http_code, body, final_url]. */
    private static function fetchRaw(string $url, int $timeout): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_USERAGENT      => self::UA,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_ENCODING       => '',
            CURLOPT_HTTPHEADER     => ['Accept: text/html,application/xhtml+xml,application/xml,text/xml,*/*;q=0.8', 'Accept-Language: en'],
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $final = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);
        return [$code, is_string($body) ? $body : '', $final];
    }

    /** Internal page links found in an HTML document (normalised, de-duplicated, capped). */
    public static function extractLinks(string $html, string $pageUrl, string $host, int $cap = 400): array
    {
        $out = [];
        if ($html === '') return $out;
        if (preg_match('~<base\b[^>]*href\s*=\s*["\']([^"\']+)~i', $html, $b) && preg_match('~^https?://~i', $b[1])) $pageUrl = $b[1];
        if (!preg_match_all('~<a\b[^>]*?\bhref\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))~i', $html, $m)) return $out;
        $hrefs = [];
        foreach ($m[1] as $i => $_) {
            $hrefs[] = $m[1][$i] !== '' ? $m[1][$i] : ($m[2][$i] !== '' ? $m[2][$i] : $m[3][$i]);
        }
        foreach (array_unique($hrefs) as $href) {
            $n = self::normalizePageUrl($href, $pageUrl, $host);
            if ($n) $out[$n] = true;
            if (count($out) >= $cap) break;
        }
        return array_keys($out);
    }

    /** Make sure the homepage exists as the first monitored page. */
    public static function ensureHomePage(array $website): void
    {
        $host = host_from_url($website['url']);
        $home = self::normalizePageUrl($website['url'], $website['url'], $host) ?: rtrim($website['url'], '/') . '/';
        DB::query("INSERT IGNORE INTO website_pages (website_id, url, url_hash, path, title, source, is_active, priority, discovered_at, last_seen_at)
                   VALUES (?, ?, ?, ?, 'Home', 'home', 1, 0, NOW(), NOW())", [$website['id'], $home, md5($home), self::pagePath($home)]);
    }

    /**
     * Discover the internal pages of a website and store them in website_pages.
     * Sources, in order: robots.txt "Sitemap:" lines, /sitemap.xml, /sitemap_index.xml, WordPress /wp-sitemap.xml (sitemap
     * indexes are followed, media/taxonomy sitemaps skipped), then the links on the homepage and – when there is no sitemap –
     * on the first pages found. Manual pages and the homepage are always kept; the list is capped at maxPages().
     * @return array{found:int, added:int, active:int, sitemap:bool, deactivated:int}
     */
    public static function discoverPages(array $website): array
    {
        $timeout = max(3, (int) setting('check_timeout', 15));
        $base = rtrim($website['url'], '/');
        $host = host_from_url($website['url']);
        $max = self::maxPages($website);
        $home = self::normalizePageUrl($website['url'], $website['url'], $host) ?: $base . '/';
        $found = [$home => 'home'];
        $usedSitemap = false;

        // 1) Sitemaps (robots.txt + well-known locations, sitemap indexes followed)
        $candidates = [];
        [$code, $robots] = self::fetchRaw($base . '/robots.txt', $timeout);
        if ($code === 200 && preg_match_all('~^\s*sitemap:\s*(\S+)~mi', $robots, $m)) {
            foreach ($m[1] as $u) $candidates[] = trim($u);
        }
        foreach (['/sitemap.xml', '/sitemap_index.xml', '/wp-sitemap.xml', '/sitemap-index.xml', '/page-sitemap.xml', '/sitemap/sitemap.xml', '/sitemap.php'] as $p) $candidates[] = $base . $p;
        $queue = array_values(array_unique($candidates));
        $seenMaps = [];
        $mapsFetched = 0;
        while ($queue && $mapsFetched < 30 && count($found) < $max * 4) {
            $u = array_shift($queue);
            if (isset($seenMaps[$u]) || host_from_url($u) !== $host) continue;
            $seenMaps[$u] = true;
            [$code, $xml] = self::fetchRaw($u, $timeout);
            $mapsFetched++;
            if ($code !== 200 || (stripos($xml, '<urlset') === false && stripos($xml, '<sitemapindex') === false)) continue;
            if (stripos($xml, '<sitemapindex') !== false) {
                if (preg_match_all('~<loc>\s*(.*?)\s*</loc>~is', $xml, $mm)) {
                    foreach ($mm[1] as $child) {
                        $child = trim(html_entity_decode(strip_tags($child)));
                        if (preg_match('~(image|video|attachment|category|tag|author|taxonom|users?|product_cat|product_tag)[-_]?sitemap|sitemap[-_](image|video|attachment|category|tag|author|users?)|wp-sitemap-(taxonomies|users)~i', $child)) continue;
                        $queue[] = $child;
                    }
                }
                continue;
            }
            if (preg_match_all('~<loc>\s*(.*?)\s*</loc>~is', $xml, $mm)) {
                $usedSitemap = true;
                foreach ($mm[1] as $loc) {
                    $n = self::normalizePageUrl(trim(html_entity_decode(strip_tags($loc))), $website['url'], $host);
                    if ($n && !isset($found[$n])) $found[$n] = 'sitemap';
                    if (count($found) >= $max * 4) break;
                }
            }
        }

        // 2) Internal links on the homepage, and on the first pages found when no sitemap exists (one extra level)
        if (count($found) < $max * 2) {
            [$code, $html, $final] = self::fetchRaw($home, $timeout);
            $linkPages = [];
            if ($code >= 200 && $code < 400) {
                foreach (self::extractLinks($html, $final ?: $home, $host) as $l) {
                    if (!isset($found[$l])) { $found[$l] = 'links'; $linkPages[] = $l; }
                }
            }
            if (!$usedSitemap && count($found) < $max && $linkPages) {
                $second = array_slice($linkPages, 0, 10);
                foreach (self::probeMany(array_combine($second, $second), $timeout, 10) as $u => $r) {
                    if ($r['up'] && !empty($r['body'])) {
                        foreach (self::extractLinks($r['body'], $r['final_url'] ?: $u, $host) as $l) {
                            if (!isset($found[$l])) $found[$l] = 'links';
                        }
                    }
                }
            }
        }

        // 3) Persist: new pages are added, known pages are marked as seen, ignored pages are never re-added
        $now = date('Y-m-d H:i:s');
        $existing = [];
        foreach (DB::fetchAll("SELECT id, url, source, is_active, ignored FROM website_pages WHERE website_id = ?", [$website['id']]) as $r) $existing[$r['url']] = $r;
        $added = 0;
        $seenIds = [];
        foreach ($found as $url => $source) {
            if (isset($existing[$url])) {
                $seenIds[] = (int) $existing[$url]['id'];
                continue;
            }
            $path = self::pagePath($url);
            DB::insert('website_pages', [
                'tenant_id'     => $website['tenant_id'] ?? null,
                'website_id'    => $website['id'],
                'url'           => $url,
                'url_hash'      => md5($url),
                'path'          => $path,
                'title'         => null,
                'source'        => $source,
                'is_active'     => 1,
                'priority'      => self::pagePriority($path, $source),
                'discovered_at' => $now,
                'last_seen_at'  => $now,
            ]);
            $added++;
        }
        if ($seenIds) DB::query("UPDATE website_pages SET last_seen_at = ? WHERE id IN (" . implode(',', $seenIds) . ")", [$now]);

        // Auto-discovered pages that are no longer linked / listed AND answer 404/410 were removed from the site on purpose: retire them
        $deactivated = DB::query("UPDATE website_pages SET is_active = 0 WHERE website_id = ? AND source IN ('sitemap','links') AND is_active = 1
            AND (last_seen_at IS NULL OR last_seen_at < ?) AND status = 'down' AND http_code IN (404, 410)", [$website['id'], $now])->rowCount();

        // Keep within the page limit: homepage and manual pages always, then the most important auto-discovered pages
        $ranked = DB::fetchAll("SELECT id, is_active, source FROM website_pages WHERE website_id = ? AND ignored = 0 AND (is_active = 1 OR last_seen_at = ?)
            ORDER BY (source = 'home') DESC, (source = 'manual') DESC, priority ASC, id ASC", [$website['id'], $now]);
        $keep = array_slice(array_column($ranked, 'id'), 0, $max);
        $drop = array_slice(array_column($ranked, 'id'), $max);
        if ($keep) DB::query("UPDATE website_pages SET is_active = 1 WHERE id IN (" . implode(',', array_map('intval', $keep)) . ") AND is_active = 0 AND ignored = 0");
        if ($drop) DB::query("UPDATE website_pages SET is_active = 0 WHERE id IN (" . implode(',', array_map('intval', $drop)) . ") AND source IN ('sitemap','links')");

        $active = (int) DB::value("SELECT COUNT(*) FROM website_pages WHERE website_id = ? AND is_active = 1", [$website['id']]);
        DB::update('websites', ['pages_discovered_at' => $now, 'pages_total' => $active], 'id = ?', [$website['id']]);
        return ['found' => count($found), 'added' => $added, 'active' => $active, 'sitemap' => $usedSitemap, 'deactivated' => $deactivated];
    }

    /** Websites due for a page scan (same interval as the uptime check). */
    public static function pageScansDue(bool $force = false, int $shard = 0, int $shards = 1, int $limit = 0): array
    {
        if (!$force && !setting('page_monitoring_enabled', 1)) return [];
        $sql = "SELECT w.* FROM websites w JOIN clients c ON c.id = w.client_id LEFT JOIN tenants t ON t.id = w.tenant_id LEFT JOIN plans p ON p.id = t.plan_id
                WHERE w.monitoring_enabled = 1 AND w.page_monitoring_enabled = 1 AND c.status = 'active' AND c.monitoring_enabled = 1 AND (t.id IS NULL OR t.status = 'active')";
        if (!$force) {
            $interval = max(1, (int) setting('website_check_interval', 5));
            $sql .= " AND (w.last_scan_at IS NULL OR w.last_scan_at <= DATE_SUB(NOW(), INTERVAL (GREATEST(IFNULL(p.page_interval, 1), " . $interval . ") - 1) MINUTE))";
        }
        if ($shards > 1) $sql .= " AND (w.id % " . (int) $shards . ") = " . ((int) $shard % (int) $shards);
        $sql .= " ORDER BY w.last_scan_at ASC";
        if ($limit > 0) $sql .= " LIMIT " . (int) $limit;
        return DB::fetchAll($sql);
    }

    /**
     * Scan every active page of the given websites. All page requests of the batch run concurrently (curl_multi),
     * failures are re-checked once, then each website's results are recorded and alerts raised.
     * Discovery runs first for websites whose page list is missing or older than Settings → "Re-discover pages every N hours".
     * @return array{sites:int, pages:int, ok:int, failed:int, discovered:int, alerts:int, recoveries:int}
     */
    public static function scanPagesBatch(array $sites, bool $notify = true, bool $allowDiscovery = true, ?callable $onSite = null): array
    {
        $timeout = max(3, (int) setting('check_timeout', 15));
        $concurrency = max(1, min(100, (int) setting('page_check_concurrency', 15)));
        $discoveryHours = max(1, (int) setting('page_discovery_hours', 24));
        $summary = ['sites' => 0, 'pages' => 0, 'ok' => 0, 'failed' => 0, 'discovered' => 0, 'alerts' => 0, 'recoveries' => 0];
        $byId = [];
        foreach ($sites as $s) $byId[$s['id']] = $s;
        $urls = [];
        $pagesById = [];
        foreach ($byId as $wid => $w) {
            if ($allowDiscovery && (empty($w['pages_discovered_at']) || strtotime($w['pages_discovered_at']) < time() - $discoveryHours * 3600)) {
                try {
                    self::discoverPages($w);
                    $summary['discovered']++;
                } catch (Throwable $e) {
                    app_log('warning', 'Page discovery failed for website #' . $wid . ': ' . $e->getMessage());
                }
            }
            $pages = DB::fetchAll("SELECT * FROM website_pages WHERE website_id = ? AND is_active = 1 ORDER BY priority, id", [$wid]);
            if (!$pages) {
                self::ensureHomePage($w);
                $pages = DB::fetchAll("SELECT * FROM website_pages WHERE website_id = ? AND is_active = 1 ORDER BY priority, id", [$wid]);
            }
            foreach ($pages as $p) {
                $pagesById[$p['id']] = $p;
                $urls[$p['id']] = $p['url'];
            }
        }
        $t0 = microtime(true);
        $results = $urls ? self::probeMany($urls, $timeout, $concurrency) : [];
        $failed = array_keys(array_filter($results, fn($r) => !$r['up']));
        if ($failed) {
            // one concurrent retry so a transient glitch never becomes an alert
            usleep(1500000);
            $retry = [];
            foreach ($failed as $pid) $retry[$pid] = $urls[$pid];
            foreach (self::probeMany($retry, $timeout, $concurrency) as $pid => $r) $results[$pid] = $r;
        }
        $ms = (int) round((microtime(true) - $t0) * 1000);
        $grouped = [];
        foreach ($pagesById as $pid => $p) $grouped[$p['website_id']][$pid] = $p;
        foreach ($byId as $wid => $w) {
            $r = self::recordPageResults($w, $grouped[$wid] ?? [], $results, $notify, $ms, true);
            $summary['sites']++;
            $summary['pages'] += $r['total'];
            $summary['ok'] += $r['ok'];
            $summary['failed'] += $r['failed'];
            $summary['alerts'] += $r['alerts'];
            $summary['recoveries'] += $r['recoveries'];
            if ($onSite) $onSite($w, $r);
        }
        return $summary;
    }

    /** Check ONE page now (manual button). */
    public static function checkPage(array $page, bool $notify = true): array
    {
        $website = DB::fetch("SELECT * FROM websites WHERE id = ?", [$page['website_id']]);
        $timeout = max(3, (int) setting('check_timeout', 15));
        $t0 = microtime(true);
        $r = self::httpProbe($page['url'], $timeout);
        if (!$r['up']) { usleep(1000000); $r = self::httpProbe($page['url'], $timeout); }
        $res = self::recordPageResults($website, [$page['id'] => $page], [$page['id'] => $r], $notify, (int) round((microtime(true) - $t0) * 1000), false);
        unset($r['body']);
        return $r + ['summary' => $res];
    }

    /**
     * Store the results of a page scan: per-page status + history, incidents (one per continuous failure), website page
     * totals / health, a website_scans row, and the alert / recovery emails (one per incident, never repeated).
     */
    private static function recordPageResults(array $w, array $pages, array $results, bool $notify, int $durationMs, bool $recordScan): array
    {
        $now = date('Y-m-d H:i:s');
        $ok = 0;
        $failedList = [];
        $newIncidents = [];
        $pendingAlerts = [];
        $recovered = [];
        $homeFailed = false;
        foreach ($pages as $pid => $p) {
            $r = $results[$pid] ?? null;
            if ($r === null) continue;
            $up = (bool) $r['up'];
            $status = $r['status'];
            $reason = $up ? null : $r['reason'];
            $err = $r['error'] ? mb_substr($r['error'], 0, 500) : null;
            $title = $p['title'];
            if ($up && !empty($r['body']) && preg_match('~<title[^>]*>(.*?)</title>~is', $r['body'], $tm)) {
                $t = trim(preg_replace('~\s+~', ' ', html_entity_decode(strip_tags($tm[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
                if ($t !== '') $title = mb_substr($t, 0, 150);
            }
            $upd = [
                'status'          => $status,
                'http_code'       => $r['http_code'],
                'response_time'   => $r['response_ms'],
                'failure_reason'  => $reason,
                'error_message'   => $err,
                'last_checked_at' => $now,
                'title'           => $title,
                'failed_checks'   => $up ? 0 : (int) $p['failed_checks'] + 1,
            ];
            if ($up) $upd['last_success_at'] = $now; else $upd['last_failed_at'] = $now;
            DB::update('website_pages', $upd, 'id = ?', [$pid]);
            if (!$up || $p['status'] !== $status) {
                DB::insert('page_monitoring', ['page_id' => $pid, 'website_id' => $w['id'], 'checked_at' => $now, 'status' => $status, 'http_code' => $r['http_code'], 'response_time' => $r['response_ms'], 'failure_reason' => $reason, 'error_message' => $err]);
            }
            $page = array_merge($p, $upd);
            $open = DB::fetch("SELECT * FROM page_incidents WHERE page_id = ? AND resolved_at IS NULL ORDER BY id DESC LIMIT 1", [$pid]);
            if (!$up) {
                $failedList[] = ['id' => (int) $pid, 'title' => $title ?: self::pathLabel($p['path']), 'path' => $p['path'], 'url' => $p['url'], 'http_code' => $r['http_code'], 'reason' => $reason, 'error' => $err];
                if ($p['source'] === 'home' || $p['path'] === '/') $homeFailed = true;
                if (!$open) {
                    $id = DB::insert('page_incidents', ['tenant_id' => $w['tenant_id'] ?? null, 'page_id' => $pid, 'website_id' => $w['id'], 'started_at' => $now, 'status' => $status, 'failure_reason' => $reason, 'status_code' => $r['http_code'], 'error_message' => $err, 'failed_checks' => 1, 'last_checked_at' => $now]);
                    $open = DB::fetch("SELECT * FROM page_incidents WHERE id = ?", [$id]);
                    $newIncidents[] = $open['id'];
                } else {
                    DB::update('page_incidents', ['failed_checks' => (int) $open['failed_checks'] + 1, 'last_checked_at' => $now, 'status' => $status, 'failure_reason' => $reason, 'status_code' => $r['http_code'], 'error_message' => $err], 'id = ?', [$open['id']]);
                    Notifier::alertSeen('page_failed:' . $open['id'], $reason, $err);
                    $open = array_merge($open, ['failed_checks' => (int) $open['failed_checks'] + 1, 'status' => $status, 'failure_reason' => $reason, 'status_code' => $r['http_code'], 'error_message' => $err]);
                }
                if (!(int) $open['alert_sent']) $pendingAlerts[] = ['page' => $page, 'incident' => $open];
            } else {
                $ok++;
                if ($open) {
                    $dur = max(0, strtotime($now) - strtotime($open['started_at']));
                    DB::update('page_incidents', ['resolved_at' => $now, 'duration_seconds' => $dur, 'last_checked_at' => $now], 'id = ?', [$open['id']]);
                    $open['resolved_at'] = $now;
                    $open['duration_seconds'] = $dur;
                    $recovered[] = ['page' => $page, 'incident' => $open];
                }
            }
        }

        // Website totals always come from the table so single-page checks and full scans agree
        $agg = DB::fetch("SELECT COUNT(*) AS total, COALESCE(SUM(status IN ('online','redirecting')),0) AS ok, COALESCE(SUM(status IN (" . down_statuses_sql() . ")),0) AS failed,
            COALESCE(SUM(status IN (" . down_statuses_sql() . ") AND (source = 'home' OR path = '/')),0) AS home_failed FROM website_pages WHERE website_id = ? AND is_active = 1", [$w['id']]);
        $total = (int) $agg['total'];
        $okAll = (int) $agg['ok'];
        $failedAll = (int) $agg['failed'];
        $health = $total === 0 ? 'unknown' : ($failedAll === 0 ? ($okAll === 0 ? 'unknown' : 'good') : (((int) $agg['home_failed'] || $failedAll * 2 >= $total) ? 'failed' : 'warning'));
        $siteUpd = ['pages_total' => $total, 'pages_ok' => $okAll, 'pages_failed' => $failedAll, 'page_health' => $health];
        if ($recordScan) {
            $siteUpd['last_scan_at'] = $now;
            $siteUpd['last_scan_duration'] = $durationMs;
            DB::insert('website_scans', ['tenant_id' => $w['tenant_id'] ?? null, 'website_id' => $w['id'], 'scanned_at' => $now, 'pages_total' => $total, 'pages_ok' => $okAll, 'pages_failed' => $failedAll, 'health' => $health, 'duration_ms' => $durationMs,
                'failed_pages' => $failedList ? json_encode(array_map(fn($f) => ['title' => $f['title'], 'url' => $f['url'], 'http_code' => $f['http_code'], 'reason' => $f['reason']], $failedList), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null]);
        }
        DB::update('websites', $siteUpd, 'id = ?', [$w['id']]);

        $alerts = 0;
        $recoveries = 0;
        if ($notify) {
            // While the whole website is down the "Website Down" alert covers every page – no page emails until the site itself answers again.
            $siteStatus = DB::value("SELECT status FROM websites WHERE id = ?", [$w['id']]);
            $siteDown = $homeFailed && in_array($siteStatus, ['down', 'server_error', 'timeout', 'ssl_error', 'parked', 'content_error'], true);
            $threshold = max(1, (int) setting('page_alert_threshold', 3));
            if ($pendingAlerts && !$siteDown) {
                if (count($pendingAlerts) > $threshold) {
                    Notifier::pagesFailed($w, $pendingAlerts);
                } else {
                    foreach ($pendingAlerts as $pa) Notifier::pageFailed($w, $pa['page'], $pa['incident']);
                }
                DB::query("UPDATE page_incidents SET alert_sent = 1 WHERE id IN (" . implode(',', array_map(fn($pa) => (int) $pa['incident']['id'], $pendingAlerts)) . ")");
                $alerts = count($pendingAlerts);
            }
            $rec = array_values(array_filter($recovered, fn($x) => (int) $x['incident']['alert_sent'] === 1));
            if ($rec) {
                if (count($rec) > $threshold) {
                    Notifier::pagesRecovered($w, $rec);
                } else {
                    foreach ($rec as $x) Notifier::pageRecovered($w, $x['page'], $x['incident']);
                }
                DB::query("UPDATE page_incidents SET recovery_sent = 1 WHERE id IN (" . implode(',', array_map(fn($x) => (int) $x['incident']['id'], $rec)) . ")");
                $recoveries = count($rec);
            }
        }
        return ['total' => $total, 'ok' => $okAll, 'failed' => $failedAll, 'health' => $health, 'failed_pages' => $failedList, 'new_incidents' => count($newIncidents), 'alerts' => $alerts, 'recoveries' => $recoveries];
    }

    /** Recompute the page totals of a website (after a page was added / removed / paused). */
    public static function refreshPageSummary(int $websiteId): void
    {
        $agg = DB::fetch("SELECT COUNT(*) AS total, COALESCE(SUM(status IN ('online','redirecting')),0) AS ok, COALESCE(SUM(status IN (" . down_statuses_sql() . ")),0) AS failed,
            COALESCE(SUM(status IN (" . down_statuses_sql() . ") AND (source = 'home' OR path = '/')),0) AS home_failed FROM website_pages WHERE website_id = ? AND is_active = 1", [$websiteId]);
        $total = (int) $agg['total']; $ok = (int) $agg['ok']; $failed = (int) $agg['failed'];
        $health = $total === 0 ? 'unknown' : ($failed === 0 ? ($ok === 0 ? 'unknown' : 'good') : (((int) $agg['home_failed'] || $failed * 2 >= $total) ? 'failed' : 'warning'));
        DB::update('websites', ['pages_total' => $total, 'pages_ok' => $ok, 'pages_failed' => $failed, 'page_health' => $health], 'id = ?', [$websiteId]);
    }

    /* =====================================================================
     * What is due for the cron scripts
     * ===================================================================== */

    /**
     * Websites due for a check. $shard/$shards let several cron processes split the work
     * (e.g. --shard=1/4 … --shard=4/4) so very large fleets still complete inside the interval.
     * $limit bounds one run (oldest-checked first); the next run picks up the rest.
     */
    public static function websitesDue(string $kind = 'uptime', bool $force = false, int $shard = 0, int $shards = 1, int $limit = 0): array
    {
        $sql = "SELECT w.* FROM websites w JOIN clients c ON c.id = w.client_id LEFT JOIN tenants t ON t.id = w.tenant_id LEFT JOIN plans p ON p.id = t.plan_id
                WHERE w.monitoring_enabled = 1 AND c.status = 'active' AND c.monitoring_enabled = 1 AND (t.id IS NULL OR t.status = 'active')";
        if (!$force) {
            if ($kind === 'uptime') {
                // Interval = the slower of the platform setting and the workspace plan; 1 minute grace so a 5-minute cron catches every site
                $interval = max(1, (int) setting('website_check_interval', 5));
                $sql .= " AND (w.last_checked_at IS NULL OR w.last_checked_at <= DATE_SUB(NOW(), INTERVAL (GREATEST(IFNULL(p.website_interval, 1), " . $interval . ") - 1) MINUTE))";
            } elseif ($kind === 'ssl') {
                $sslInt = max(1, (int) setting('ssl_check_interval', 5));
                $sql .= " AND (w.ssl_checked_at IS NULL OR w.ssl_checked_at <= DATE_SUB(NOW(), INTERVAL (GREATEST(IFNULL(p.ssl_interval, 1), " . $sslInt . ") - 1) MINUTE))";
            }
        }
        if ($shards > 1) $sql .= " AND (w.id % " . (int) $shards . ") = " . ((int) $shard % (int) $shards);
        $sql .= $kind === 'ssl' ? " ORDER BY w.ssl_checked_at ASC" : " ORDER BY w.last_checked_at ASC";
        if ($limit > 0) $sql .= " LIMIT " . (int) $limit;
        return DB::fetchAll($sql);
    }

    public static function formsDue(bool $force = false, int $shard = 0, int $shards = 1, int $limit = 0): array
    {
        $sql = "SELECT f.* FROM forms f JOIN websites w ON w.id = f.website_id JOIN clients c ON c.id = w.client_id LEFT JOIN tenants t ON t.id = w.tenant_id LEFT JOIN plans p ON p.id = t.plan_id
                WHERE f.status NOT IN ('disabled','removed') AND f.auto_test = 1 AND w.monitoring_enabled = 1 AND c.status = 'active' AND c.monitoring_enabled = 1 AND (t.id IS NULL OR t.status = 'active')";
        if (!$force) {
            $global = max(1, (int) setting('form_check_interval', 10));
            // per-form interval overrides the global one, but never faster than the workspace plan; 1 minute grace as for websites
            $sql .= " AND (f.last_tested_at IS NULL OR f.last_tested_at <= DATE_SUB(NOW(), INTERVAL (GREATEST(GREATEST(IF(f.test_interval > 0, f.test_interval, " . $global . "), IFNULL(p.form_interval, 1)) - 1, 1)) MINUTE))";
        }
        if ($shards > 1) $sql .= " AND (f.id % " . (int) $shards . ") = " . ((int) $shard % (int) $shards);
        $sql .= " ORDER BY f.last_tested_at ASC";
        if ($limit > 0) $sql .= " LIMIT " . (int) $limit;
        return DB::fetchAll($sql);
    }

    /* =====================================================================
     * ROLLUPS & RETENTION (keeps the monitoring tables small at scale)
     * ===================================================================== */

    /**
     * Aggregate raw checks into website_uptime_daily for every day before today that is not rolled up yet,
     * then delete raw rows older than the retention window. Idempotent; safe to run every day.
     */
    public static function rollupDaily(): array
    {
        $keepDays = max(1, (int) setting('retention_monitoring_days', 7));
        $from = DB::value("SELECT MIN(DATE(checked_at)) FROM website_monitoring WHERE checked_at < CURDATE()");
        $days = 0; $rows = 0;
        if ($from) {
            $day = new DateTime($from);
            $today = new DateTime('today');
            while ($day < $today) {
                $d = $day->format('Y-m-d');
                $n = DB::query("INSERT INTO website_uptime_daily (website_id, day, checks, up_checks, avg_response, max_response)
                    SELECT website_id, ?, COUNT(*), SUM(status IN ('online','redirecting')), AVG(IF(status IN ('online','redirecting'), response_time, NULL)), MAX(IF(status IN ('online','redirecting'), response_time, NULL))
                    FROM website_monitoring WHERE checked_at >= ? AND checked_at < ? GROUP BY website_id
                    ON DUPLICATE KEY UPDATE checks = VALUES(checks), up_checks = VALUES(up_checks), avg_response = VALUES(avg_response), max_response = VALUES(max_response)",
                    [$d, $d . ' 00:00:00', $d . ' 23:59:59'])->rowCount();
                // incidents that started that day
                DB::query("INSERT INTO website_uptime_daily (website_id, day, incidents, downtime_seconds)
                    SELECT website_id, ?, COUNT(*), COALESCE(SUM(COALESCE(duration_seconds, 0)),0) FROM website_incidents WHERE started_at >= ? AND started_at < ? GROUP BY website_id
                    ON DUPLICATE KEY UPDATE incidents = VALUES(incidents), downtime_seconds = VALUES(downtime_seconds)", [$d, $d . ' 00:00:00', $d . ' 23:59:59']);
                $rows += $n; $days++;
                $day->modify('+1 day');
                if ($days > 400) break;
            }
        }
        // Delete raw rows beyond retention in chunks (avoids long locks on huge tables)
        $cutoff = date('Y-m-d H:i:s', time() - $keepDays * 86400);
        $deleted = 0;
        do {
            $n = DB::query("DELETE FROM website_monitoring WHERE checked_at < ? LIMIT 20000", [$cutoff])->rowCount();
            $deleted += $n;
        } while ($n === 20000 && $deleted < 2000000);
        DB::query("DELETE FROM website_uptime_daily WHERE day < ?", [date('Y-m-d', time() - 400 * 86400)]);
        return ['days' => $days, 'rows' => $rows, 'deleted' => $deleted];
    }

    /** Prune the other history tables according to the retention settings. */
    public static function housekeeping(): array
    {
        $out = [];
        $chunked = function (string $sql, array $params) {
            $total = 0;
            do { $n = DB::query($sql . " LIMIT 20000", $params)->rowCount(); $total += $n; } while ($n === 20000 && $total < 2000000);
            return $total;
        };
        try { $out['analytics'] = array_sum(Analytics::prune()); } catch (Throwable $e) { app_log('error', 'Analytics prune failed: ' . $e->getMessage()); }
        $out['form_tests'] = $chunked("DELETE FROM form_tests WHERE tested_at < ?", [date('Y-m-d H:i:s', time() - max(7, (int) setting('retention_form_tests_days', 90)) * 86400)]);
        $out['ssl_monitoring'] = $chunked("DELETE FROM ssl_monitoring WHERE checked_at < ?", [date('Y-m-d H:i:s', time() - 180 * 86400)]);
        $out['activity_logs'] = $chunked("DELETE FROM activity_logs WHERE created_at < ?", [date('Y-m-d H:i:s', time() - max(30, (int) setting('retention_activity_days', 365)) * 86400)]);
        $out['email_logs'] = $chunked("DELETE FROM email_logs WHERE sent_at < ?", [date('Y-m-d H:i:s', time() - max(30, (int) setting('retention_email_logs_days', 180)) * 86400)]);
        $out['notifications'] = $chunked("DELETE FROM notifications WHERE is_read = 1 AND created_at < ?", [date('Y-m-d H:i:s', time() - max(7, (int) setting('retention_notifications_days', 90)) * 86400)]);
        $pageDays = max(1, (int) setting('retention_page_history_days', 30));
        $out['page_monitoring'] = $chunked("DELETE FROM page_monitoring WHERE checked_at < ?", [date('Y-m-d H:i:s', time() - $pageDays * 86400)]);
        $out['website_scans'] = $chunked("DELETE FROM website_scans WHERE scanned_at < ?", [date('Y-m-d H:i:s', time() - $pageDays * 86400)]);
        $out['page_incidents'] = $chunked("DELETE FROM page_incidents WHERE resolved_at IS NOT NULL AND resolved_at < ?", [date('Y-m-d H:i:s', time() - 400 * 86400)]);
        $out['form_incidents'] = $chunked("DELETE FROM form_incidents WHERE resolved_at IS NOT NULL AND resolved_at < ?", [date('Y-m-d H:i:s', time() - 400 * 86400)]);
        $out['ssl_incidents'] = $chunked("DELETE FROM ssl_incidents WHERE resolved_at IS NOT NULL AND resolved_at < ?", [date('Y-m-d H:i:s', time() - 400 * 86400)]);
        $out['screenshots'] = self::pruneScreenshots(max(1, (int) setting('retention_form_screenshots_days', 30)));
        $out['browser_profiles'] = Browser::gc();
        $out['email_queue'] = $chunked("DELETE FROM email_queue WHERE status = 'sent' AND sent_at < ?", [date('Y-m-d H:i:s', time() - 30 * 86400)]);
        $out['login_attempts'] = $chunked("DELETE FROM login_attempts WHERE attempted_at < ?", [date('Y-m-d H:i:s', time() - 7 * 86400)]);
        $out['alert_log'] = $chunked("DELETE FROM alert_log WHERE sent_at < ?", [date('Y-m-d H:i:s', time() - 400 * 86400)]);
        $out['remember_tokens'] = DB::query("DELETE FROM remember_tokens WHERE expires_at < NOW()")->rowCount();
        $out['cache_files'] = Cache::gc();
        try { $out['sessions'] = DB::query("DELETE FROM sessions WHERE last_activity < ? LIMIT 20000", [time() - SESSION_LIFETIME])->rowCount(); $out['jobs'] = Queue::prune(); } catch (Throwable $e) {}
        // SaaS housekeeping: expired trials fall back to the Free plan limits, stale tokens / locks / webhook logs are pruned (30 days)
        $logDays = max(7, (int) setting('log_retention_days', 30));
        $free = DB::value("SELECT id FROM plans WHERE code = 'free'");
        if ($free) {
            foreach (DB::fetchAll("SELECT id, name FROM tenants WHERE subscription_status = 'trial' AND trial_ends_at IS NOT NULL AND trial_ends_at < NOW()") as $t) {
                DB::query("UPDATE subscriptions SET status = 'expired', ended_at = NOW() WHERE tenant_id = ? AND status = 'trial'", [$t['id']]);
                // the workspace continues on the FREE plan (status 'free', not 'expired' – an expired subscription would stop monitoring entirely)
                DB::update('tenants', ['subscription_status' => 'free', 'plan_id' => $free, 'trial_ends_at' => null], 'id = ?', [$t['id']]);
                Tenant::forget((int) $t['id']);
                try { Scheduler::reschedule((int) $t['id']); } catch (Throwable $e) {}
                Notifier::create('warning', 'billing', 'Free trial ended', 'The free trial of ' . $t['name'] . ' has ended – the workspace now runs with the Free plan limits and intervals. Upgrade to restore the trial plan features.', ['tenant_id' => (int) $t['id']]);
                $out['trials_expired'] = ($out['trials_expired'] ?? 0) + 1;
            }
        }
        $out['plan_history'] = self::pruneHistoryByPlan();
        $out['email_verifications'] = DB::query("DELETE FROM email_verifications WHERE expires_at < DATE_SUB(NOW(), INTERVAL ? DAY)", [$logDays])->rowCount();
        $out['team_invitations'] = DB::query("DELETE FROM team_invitations WHERE accepted_at IS NULL AND expires_at < DATE_SUB(NOW(), INTERVAL ? DAY)", [$logDays])->rowCount();
        $out['monitor_locks'] = DB::query("DELETE FROM monitor_locks WHERE locked_until < NOW()")->rowCount();
        $out['webhook_deliveries'] = $chunked("DELETE FROM webhook_deliveries WHERE created_at < ?", [date('Y-m-d H:i:s', time() - $logDays * 86400)]);
        $out['form_scans'] = $chunked("DELETE FROM form_scans WHERE started_at < ?", [date('Y-m-d H:i:s', time() - $logDays * 86400)]);
        $out['email_queue_failed'] = $chunked("DELETE FROM email_queue WHERE status = 'failed' AND created_at < ?", [date('Y-m-d H:i:s', time() - $logDays * 86400)]);
        return $out;
    }

    /**
     * Plan-based monitoring history (plans.retention_days, e.g. 7 / 30 / 90 / 365 / 730): every workspace keeps only
     * the uptime rollups, resolved incidents, form test results, SSL history and recovered alerts its plan includes.
     * Raw check rows are handled by rollupDaily() (global short window). Runs daily from housekeeping.
     */
    public static function pruneHistoryByPlan(): int
    {
        $n = 0;
        foreach (DB::fetchAll("SELECT id FROM tenants") as $t) {
            $tid = (int) $t['id'];
            $days = max(1, min(730, (int) (Tenant::plan($tid)['retention_days'] ?? 7)));
            $cutDate = date('Y-m-d', time() - $days * 86400);
            $cutDt = $cutDate . ' 00:00:00';
            $sites = "SELECT id FROM websites WHERE tenant_id = $tid";
            try {
                $n += DB::query("DELETE FROM website_uptime_daily WHERE day < ? AND website_id IN ($sites)", [$cutDate])->rowCount();
                $n += DB::query("DELETE FROM website_incidents WHERE tenant_id = ? AND resolved_at IS NOT NULL AND resolved_at < ?", [$tid, $cutDt])->rowCount();
                $n += DB::query("DELETE FROM page_incidents WHERE tenant_id = ? AND resolved_at IS NOT NULL AND resolved_at < ?", [$tid, $cutDt])->rowCount();
                $n += DB::query("DELETE FROM ssl_incidents WHERE tenant_id = ? AND resolved_at IS NOT NULL AND resolved_at < ?", [$tid, $cutDt])->rowCount();
                $n += DB::query("DELETE FROM form_incidents WHERE tenant_id = ? AND resolved_at IS NOT NULL AND resolved_at < ?", [$tid, $cutDt])->rowCount();
                $n += DB::query("DELETE FROM form_tests WHERE tested_at < ? AND form_id IN (SELECT id FROM forms WHERE tenant_id = ?)", [$cutDt, $tid])->rowCount();
                $n += DB::query("DELETE FROM ssl_monitoring WHERE checked_at < ? AND website_id IN ($sites)", [$cutDt])->rowCount();
                $n += DB::query("DELETE FROM page_monitoring WHERE checked_at < ? AND website_id IN ($sites)", [$cutDt])->rowCount();
                $n += DB::query("DELETE FROM alerts WHERE tenant_id = ? AND status = 'recovered' AND recovered_at < ?", [$tid, $cutDt])->rowCount();
            } catch (Throwable $e) { app_log('warning', "Plan history prune failed for tenant $tid: " . $e->getMessage()); }
        }
        return $n;
    }

    /** Delete failure screenshots older than N days (uploads/form-tests). */
    public static function pruneScreenshots(int $days): int
    {
        $dir = UPLOAD_PATH . '/form-tests';
        if (!is_dir($dir)) return 0;
        $n = 0;
        foreach (glob($dir . '/form-*.jpg') ?: [] as $f) {
            if (filemtime($f) < time() - $days * 86400 && @unlink($f)) $n++;
        }
        return $n;
    }

    /* =====================================================================
     * CRON HEALTH
     * ===================================================================== */

    public static function cronStart(string $name): void
    {
        DB::query("INSERT INTO cron_runs (name, last_started_at, last_status, run_count) VALUES (?, NOW(), 'running', 1)
                   ON DUPLICATE KEY UPDATE last_started_at = NOW(), last_status = 'running', run_count = run_count + 1", [$name]);
    }

    public static function cronFinish(string $name, bool $ok, string $message = ''): void
    {
        DB::query("UPDATE cron_runs SET last_finished_at = NOW(), last_status = ?, last_message = ?, last_duration = TIMESTAMPDIFF(SECOND, last_started_at, NOW()) WHERE name = ?",
            [$ok ? 'success' : 'failed', mb_substr($message, 0, 500), $name]);
        // The header/dashboard cache the cron status briefly; refresh it as soon as a job completes
        Cache::forget('cron:status');
        Cache::forget('health:lastscan');
        Cache::forget('forms:lastscan');
        Cache::forget('pages:lastscan');
    }

    /**
     * Health of each monitoring queue (label, last completion, pending / due jobs, status active|stale|never).
     * v3.4: derived from the job queue + per-queue state instead of cron_runs, so it is true for workers, cron and the web heartbeat alike.
     */
    public static function cronHealth(): array
    {
        $h = Scheduler::health();
        $map = [
            ['name' => 'check-websites', 'queue' => 'website', 'label' => 'Website Monitoring', 'interval' => max(1, (int) setting('website_check_interval', 5))],
            ['name' => 'check-pages', 'queue' => 'page', 'label' => 'Page Monitoring', 'interval' => max(1, (int) setting('website_check_interval', 5))],
            ['name' => 'check-forms', 'queue' => 'form', 'label' => 'Form Monitoring', 'interval' => max(1, (int) setting('form_check_interval', 10))],
            ['name' => 'check-ssl', 'queue' => 'ssl', 'label' => 'SSL Monitoring', 'interval' => max(1, (int) setting('ssl_check_interval', 5))],
            ['name' => 'discover-forms', 'queue' => 'discovery', 'label' => 'Form Discovery', 'interval' => max(1, (int) setting('form_scan_interval_hours', 24)) * 60],
            ['name' => 'check-expiry', 'queue' => 'maintenance', 'label' => 'Expiry Monitoring', 'interval' => 1440],
            ['name' => 'process-notifications', 'queue' => 'notification', 'label' => 'Email Delivery', 'interval' => 2],
        ];
        $jobs = [];
        foreach ($map as $j) {
            $qs = $h['queue']['queues'][$j['queue']] ?? [];
            $st = $h['queue_state'][$j['queue']] ?? [];
            $last = $st['last_done_at'] ?? null;
            $j['last'] = $last;
            $j['last_status'] = $last ? (($st['last_failed_at'] ?? null) && strtotime($st['last_failed_at']) > strtotime($last) ? 'failed' : 'success') : null;
            $j['last_message'] = $st['last_error'] ?? null;
            $j['run_count'] = (int) ($qs['done'] ?? 0);
            $j['pending'] = (int) ($qs['pending'] ?? 0) + (int) ($qs['running'] ?? 0);
            $j['lag_seconds'] = (int) ($qs['oldest_due_seconds'] ?? 0);
            $j['minutes_since'] = $last ? (int) floor((time() - strtotime($last)) / 60) : null;
            $j['next'] = $h['next_dispatch'];
            // a queue is stale only when work is waiting and nothing is processing it – an empty queue is healthy
            $j['status'] = $h['status'] === 'never' && !$last ? 'never' : ((!$h['healthy'] || $j['lag_seconds'] > 600) ? 'stale' : 'active');
            $jobs[] = $j;
        }
        return $jobs;
    }

    /** Overall scheduler status: running | stale | never, with the most important message (used by the header banner, health-check.php, notifier). */
    public static function cronStatus(): array
    {
        $h = Scheduler::health();
        $status = $h['status'] === 'never' ? 'never' : ($h['healthy'] ? 'running' : 'stale');
        return ['status' => $status, 'message' => $h['message'], 'label' => $h['label'], 'jobs' => self::cronHealth()];
    }
}
