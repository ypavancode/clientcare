<?php
/**
 * FORM TESTING ENGINE – actually submits forms and verifies the result.
 *
 *   HTTP engine     – loads the page, finds the form (CSS selector or heuristics), fills every field with test data,
 *                     submits it exactly like the browser would (same action / method / hidden fields / nonces) and
 *                     inspects the HTTP + AJAX/JSON response. Knows the AJAX endpoints of the common WordPress form
 *                     plugins (Contact Form 7, WPForms, Elementor Pro, Gravity Forms, Fluent Forms, Formidable).
 *   Browser engine  – headless Chrome (includes/Browser.php): opens the page, clicks the popup trigger, waits for the
 *                     popup, fills the form in the live DOM, clicks submit and watches the AJAX requests, redirects,
 *                     success / error messages and the popup closing. Used for popup, modal and JavaScript forms.
 *
 * Both engines produce the same result structure and the same precise failure reasons
 * ("Popup did not open", "Form selector not found", "AJAX request returned HTTP 500", ...).
 */
class FormTester
{
    const REASON_PAGE = 'Form page unreachable';

    /** Analytics / third-party requests that never count as the form submission */
    const IGNORE_HOSTS = ['google-analytics.com', 'googletagmanager.com', 'doubleclick.net', 'facebook.com', 'facebook.net', 'hotjar.com', 'clarity.ms', 'gstatic.com', 'googleapis.com',
        'google.com/recaptcha', 'hcaptcha.com', 'cloudflare.com', 'cloudflareinsights.com', 'linkedin.com', 'bing.com', 'twitter.com', 'tiktok.com', 'sentry.io', 'newrelic.com', 'nr-data.net', 'stats.wp.com', 'wp.com/stats', 'youtube.com', 'vimeo.com', 'fonts.'];

    const FAIL_PHRASES = ['smtp error', 'mailer error', 'could not send', 'couldn\'t send', 'failed to send', 'error sending', 'message could not be sent', 'was not sent', 'not sent', 'fatal error', 'warning:', 'there was an error', 'an error occurred', 'something went wrong', 'please try again later', 'error occurred while', 'submission failed', 'invalid nonce', 'security check failed', 'spam', 'captcha', 'validation error', 'required field', 'is required', 'please fill', 'please complete', 'field is required', 'invalid email', 'one or more fields have an error', 'unable to process', 'form has been disabled', 'the form is not active', 'access denied', 'forbidden', 'not found', 'internal server error', 'service unavailable', 'bad gateway'];
    const OK_PHRASES = ['thank you', 'thanks for', 'successfully', 'has been sent', 'was sent', 'been submitted', 'we will get back', 'we\'ll get back', 'we will contact', 'received your', 'submission received', 'message sent', 'your message has been', 'we have received', 'sent successfully', 'submitted successfully', 'your request', 'be in touch', 'get in touch shortly', 'contact you shortly', 'thankyou'];

    /* =====================================================================
     * Entry point
     * ===================================================================== */

    /**
     * @return array{success:bool, reason:?string, error:?string, http_code:?int, ajax_status:?int, response_ms:?int, excerpt:?string, final_url:?string,
     *               email_received:string, mode:string, engine:string, steps:array, screenshot:?string, wp_plugin:?string, token:string}
     */
    public static function run(array $form, array $website, ?string $token = null): array
    {
        $token = $token ?: 'CRMTEST-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
        $isPopup = ($form['form_kind'] ?? 'normal') === 'popup';
        $kind = $form['form_kind'] ?? 'normal';
        $engineSetting = $form['engine'] ?? 'auto';
        $r = self::blank($token, $isPopup ? 'popup' : 'submission');
        $form = self::applyTestConfig($form, $r);
        $preSteps = $r['steps'];

        $wantBrowser =$engineSetting === 'browser' || ($engineSetting === 'auto' && ($isPopup || $kind === 'ajax' || strtolower((string) ($form['wp_plugin'] ?? '')) === 'ninja'));
        $browser = null;
        if ($wantBrowser) {
            $browser = Browser::shared();
            if (!$browser && $engineSetting === 'browser') {
                $r['reason'] = 'Browser engine unavailable';
                $r['error'] = 'This form is set to be tested with the browser engine, but no headless browser could be started: ' . (Browser::lastError() ?: Browser::status()['message']);
                $r['steps'][] = self::step('engine', false, $r['error']);
                return $r;
            }
        }

        try {
            if ($browser) {
                $r = self::runBrowser($browser, $form, $website, $token);
                if ($preSteps) $r['steps'] = array_merge($preSteps, $r['steps']);
            } else {
                if ($wantBrowser) {
                    $r['steps'][] = self::step('engine', null, 'Browser engine unavailable (' . (Browser::lastError() ?: Browser::status()['message']) . ') – the form is submitted directly over HTTP instead.' . ($isPopup ? ' The popup interaction itself is not verified.' : ''));
                }
                $r = self::runHttp($form, $website, $token, $r['steps']);
            }
        } catch (Throwable $e) {
            $r['success'] = false;
            $r['reason'] = $r['reason'] ?: 'Test error';
            $r['error'] = mb_substr($e->getMessage(), 0, 500);
            $r['steps'][] = self::step('error', false, $e->getMessage());
            app_log('error', 'Form test exception for form #' . ($form['id'] ?? '?') . ': ' . $e->getMessage());
        }

        // Email delivery verification (IMAP on the test mailbox)
        if ($r['success'] && (!empty($form['verify_email']) || setting('form_email_required', 0))) {
            $r['email_received'] = self::verifyEmail($token);
            if ($r['email_received'] === 'no') {
                $r['success'] = false;
                $r['reason'] = 'Test email was not received';
                $r['error'] = 'The form accepted the submission but no email containing the test token ' . $token . ' arrived in the test mailbox' . (!empty($form['test_email_recipient']) ? ' (' . $form['test_email_recipient'] . ')' : '') . ' within ' . (int) setting('imap_wait_seconds', 20) . ' seconds.';
                $r['steps'][] = self::step('email', false, $r['error']);
            } elseif ($r['email_received'] === 'yes') {
                $r['steps'][] = self::step('email', true, 'Test email received in the test mailbox');
            } else {
                $r['steps'][] = self::step('email', null, 'Email verification skipped – IMAP mailbox not configured (Settings → Monitoring)');
            }
        }
        $r['outcome'] = self::classifyOutcome($r);
        return $r;
    }

    private static function blank(string $token, string $mode): array
    {
        return ['success' => false, 'reason' => null, 'error' => null, 'http_code' => null, 'ajax_status' => null, 'response_ms' => null, 'excerpt' => null, 'final_url' => null,
            'email_received' => 'unknown', 'mode' => $mode, 'engine' => 'http', 'steps' => [], 'screenshot' => null, 'wp_plugin' => null, 'token' => $token,
            'outcome' => null, 'captcha' => null, 'interference' => null];
    }

    public static function step(string $name, ?bool $ok, string $detail): array
    {
        return ['step' => $name, 'ok' => $ok, 'detail' => mb_substr($detail, 0, 400), 'at' => date('H:i:s')];
    }

    /* =====================================================================
     * Test data
     * ===================================================================== */

    public static function values(array $form, string $token): array
    {
        $email = trim((string) ($form['test_email'] ?? '')) ?: setting('form_test_email', 'website-test@example.com');
        $name = trim((string) ($form['test_name'] ?? '')) ?: 'CRM Test ' . $token;
        $parts = preg_split('~\s+~', $name, 2);
        $message = trim((string) ($form['test_message'] ?? '')) ?: 'Automated form test from ' . company_name() . ' CRM. Please ignore this message.';
        if (!str_contains($message, $token)) $message .= ' [' . $token . ']';
        return [
            'name' => $name, 'first_name' => $parts[0], 'last_name' => $parts[1] ?? 'Test',
            'email' => $email, 'phone' => trim((string) ($form['test_phone'] ?? '')) ?: '9999999999', 'message' => $message,
            'subject' => 'Automated form test ' . $token, 'company' => company_name() . ' (form test)', 'city' => 'Test City',
            'website' => 'https://example.com', 'date' => date('Y-m-d'), 'text' => 'Test ' . $token, 'password' => 'Test@' . substr($token, -6), 'token' => $token,
        ];
    }

    /** "field=value" lines or JSON → overrides, with {{placeholders}} replaced. */
    public static function overrides(array $form, array $values): array
    {
        $payload = trim((string) ($form['test_payload'] ?? ''));
        if ($payload === '') return [];
        $fields = [];
        if ($payload[0] === '{') {
            $fields = json_decode($payload, true) ?: [];
        } else {
            foreach (preg_split('~\r?\n~', $payload) as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
                [$k, $v] = explode('=', $line, 2);
                $fields[trim($k)] = trim($v);
            }
        }
        $map = ['{{test_email}}' => $values['email'], '{{email}}' => $values['email'], '{{token}}' => $values['token'], '{{name}}' => $values['name'], '{{first_name}}' => $values['first_name'], '{{last_name}}' => $values['last_name'],
            '{{phone}}' => $values['phone'], '{{message}}' => $values['message'], '{{subject}}' => $values['subject'], '{{company}}' => $values['company'], '{{date}}' => $values['date']];
        foreach ($fields as $k => $v) if (is_string($v)) $fields[$k] = strtr($v, $map);
        return $fields;
    }

    /** Classify a field from its name / id / placeholder / label / type (same rules as the in-page JS). */
    public static function classify(string $hint, string $type, string $tag): string
    {
        $k = strtolower($hint);
        $type = strtolower($type);
        if ($type === 'email' || preg_match('~e-?mail~', $k)) return 'email';
        if ($type === 'tel' || preg_match('~phone|mobile|whatsapp|contact.?(no|number)|cell~', $k)) return 'phone';
        if ($tag === 'textarea' || preg_match('~message|comment|enquiry|inquiry|query|description|details|requirement|question|remarks|note~', $k)) return 'message';
        if (preg_match('~first.?name|fname~', $k)) return 'first_name';
        if (preg_match('~last.?name|lname|surname~', $k)) return 'last_name';
        if (preg_match('~name~', $k)) return 'name';
        if (preg_match('~subject|title~', $k)) return 'subject';
        if (preg_match('~company|organi[sz]ation|business|firm~', $k)) return 'company';
        if (preg_match('~city|town|location|address|state|country|pincode|zip|postal~', $k)) return 'city';
        if (preg_match('~website|url~', $k)) return 'website';
        if (preg_match('~captcha|security.?code|verification|human~', $k)) return 'captcha';
        if ($type === 'number' || preg_match('~qty|quantity|budget|age|amount~', $k)) return 'number';
        if ($type === 'date' || preg_match('~date~', $k)) return 'date';
        return 'text';
    }

    /* =====================================================================
     * HTTP engine
     * ===================================================================== */

    private static function runHttp(array $form, array $website, string $token, array $steps = []): array
    {
        $r = self::blank($token, ($form['form_kind'] ?? '') === 'popup' ? 'popup' : 'submission');
        $r['engine'] = 'http';
        $r['steps'] = $steps;
        $timeout = max(5, (int) setting('check_timeout', 15));
        $values = self::values($form, $token);
        $overrides = self::overrides($form, $values);
        $jar = tempnam(sys_get_temp_dir(), 'crmck');

        try {
            // 1) Load the page
            $page = self::http('GET', $form['page_url'], [], [], $timeout, $jar, $form['page_url']);
            $r['http_code'] = $page['status'] ?: null;
            $r['response_ms'] = $page['ms'];
            if ($page['errno'] || !$page['status']) {
                $r['reason'] = self::REASON_PAGE;
                $r['error'] = $page['error'] ?: 'No HTTP response';
                $r['steps'][] = self::step('page', false, 'Could not load ' . $form['page_url'] . ': ' . $r['error']);
                return $r;
            }
            if ($page['status'] >= 400) {
                $r['reason'] = 'Form page returned HTTP ' . $page['status'];
                $r['error'] = 'The page ' . $form['page_url'] . ' responded with HTTP ' . $page['status'] . ' ' . Monitor::httpText($page['status']);
                $r['steps'][] = self::step('page', false, $r['error']);
                return $r;
            }
            $r['steps'][] = self::step('page', true, 'Page loaded (HTTP ' . $page['status'] . ', ' . $page['ms'] . ' ms)');

            // 2) Find the form
            $parsed = self::findForm($page['body'], $form, $page['url']);
            if (!$parsed) {
                if (!empty($form['form_selector'])) {
                    $r['reason'] = 'Form selector not found';
                    $r['error'] = 'No <form> matching "' . $form['form_selector'] . '" was found in the page HTML.' . (($form['form_kind'] ?? '') !== 'normal' ? ' If the form is rendered by JavaScript, use the browser engine.' : '');
                } elseif (!empty($form['popup_selector']) && !self::selectorExists($page['body'], $form['popup_selector'])) {
                    $r['reason'] = 'Popup not found';
                    $r['error'] = 'No element matching the popup selector "' . $form['popup_selector'] . '" exists in the page HTML.';
                } else {
                    $r['reason'] = 'Form not found on page';
                    $r['error'] = 'The page loaded (HTTP ' . $page['status'] . ') but no suitable <form> element was found.';
                }
                $r['steps'][] = self::step('form', false, $r['error']);
                return $r;
            }
            $r['wp_plugin'] = $parsed['plugin'];
            $r['steps'][] = self::step('form', true, 'Form found' . ($parsed['selector'] ? ' (' . $parsed['selector'] . ')' : '') . ($parsed['plugin'] ? ' · ' . self::pluginLabel($parsed['plugin']) : '') . ' · ' . count($parsed['fields']) . ' field(s)');
            if ($parsed['plugin'] === 'ninja') {
                $r['reason'] = 'Browser engine required';
                $r['error'] = 'Ninja Forms renders its fields with JavaScript, so this form can only be tested with the browser engine (install Chrome on the server).';
                $r['steps'][] = self::step('submit', false, $r['error']);
                return $r;
            }
            // CAPTCHA / anti-bot gate: never submit when a human verification is required
            if (self::captchaGate($form, $parsed['captcha_info'], $r)) return $r;

            // 3) Fill
            [$data, $filled, $skipped] = self::fillFields($parsed, $values, $overrides);
            $r['steps'][] = self::step('fill', true, 'Filled: ' . implode(', ', array_slice($filled, 0, 12)) . (count($filled) > 12 ? ' +' . (count($filled) - 12) : '') . ($skipped ? ' · skipped: ' . implode(', ', array_slice($skipped, 0, 6)) : ''));

            // 4) Build + send the request (plugin aware)
            $req = self::buildRequest($parsed, $data, $form, $page['url'], $website);
            $r['steps'][] = self::step('submit', true, strtoupper($req['method']) . ' ' . $req['url'] . ($req['ajax'] ? ' (AJAX)' : ''));
            $res = self::http($req['method'], $req['url'], $req['data'], $req['headers'], $timeout, $jar, $form['page_url'], $req['multipart']);
            $r['response_ms'] = $res['ms'];
            $r['http_code'] = $res['status'] ?: null;
            $r['ajax_status'] = $req['ajax'] ? ($res['status'] ?: null) : null;
            $r['final_url'] = $res['url'];
            if ($res['errno']) {
                $r['reason'] = $res['errno'] === CURLE_OPERATION_TIMEDOUT ? 'Form submission timed out' : 'Submission connection failed';
                $r['error'] = $res['errno'] === CURLE_OPERATION_TIMEDOUT ? 'The form handler did not respond within ' . $timeout . ' seconds.' : $res['error'];
                $r['steps'][] = self::step('response', false, $r['error']);
                return $r;
            }
            $bodyText = self::textOf($res['body']);
            $r['excerpt'] = truncate($bodyText, 300);
            $json = self::jsonOf($res['body']);
            $ctx = [
                'expect_text' => trim((string) ($form['success_match'] ?? '')), 'expect_redirect' => trim((string) ($form['expect_redirect'] ?? '')),
                'expect_response' => trim((string) ($form['expect_response'] ?? '')), 'expect_code' => (int) ($form['expect_http_code'] ?? 0),
                'final_url' => $res['url'], 'navigated' => $res['url'] !== $form['page_url'] && $res['url'] !== $req['url'], 'timed_out' => false,
                'responses' => [['url' => $req['url'], 'status' => $res['status'], 'type' => $req['ajax'] ? 'XHR' : 'Document', 'body' => mb_substr($res['body'], 0, 4000), 'json' => $json]],
                'messages' => self::textByClass($res['body'], self::OK_CLASSES), 'errors' => self::textByClass($res['body'], self::ERR_CLASSES),
                'errors_before' => self::textByClass($page['body'], self::ERR_CLASSES), 'body_text' => $bodyText, 'body_before' => self::textOf($page['body']),
                'form_hidden' => false, 'popup_closed' => false, 'captcha' => $parsed['captcha'], 'ajax' => $req['ajax'], 'plugin' => $parsed['plugin'],
            ];
            [$ok, $reason, $error, $excerpt] = self::judge($ctx);
            $r['success'] = $ok;
            $r['reason'] = $reason;
            $r['error'] = $error;
            if ($excerpt) $r['excerpt'] = $excerpt;
            $r['steps'][] = self::step('response', $ok, 'HTTP ' . $res['status'] . ' · ' . ($ok ? ($excerpt ?: 'accepted') : $reason . ' – ' . $error));
            return $r;
        } finally {
            @unlink($jar);
        }
    }

    /** cURL request with a cookie jar. Returns status, body, url (final), ms, errno, error, headers. */
    public static function http(string $method, string $url, array $data, array $headers, int $timeout, string $jar, string $referer, bool $multipart = false): array
    {
        $ch = curl_init();
        $h = array_merge(['X-CRM-Form-Test: 1', 'Accept-Language: en-US,en;q=0.9'], $headers);
        $opts = [
            CURLOPT_URL => $url, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 6, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
            CURLOPT_USERAGENT => Monitor::UA, CURLOPT_REFERER => $referer, CURLOPT_HTTPHEADER => $h, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
            CURLOPT_ENCODING => '', CURLOPT_HEADER => false,
        ];
        if (strtoupper($method) === 'GET') {
            if ($data) $opts[CURLOPT_URL] = $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($data);
        } else {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = $multipart ? self::flatten($data) : http_build_query($data);
        }
        curl_setopt_array($ch, $opts);
        $t = microtime(true);
        $body = curl_exec($ch);
        $ms = (int) round((microtime(true) - $t) * 1000);
        $out = ['status' => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'body' => is_string($body) ? $body : '', 'url' => (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL), 'ms' => $ms, 'errno' => curl_errno($ch), 'error' => curl_error($ch), 'type' => (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE)];
        curl_close($ch);
        return $out;
    }

    /** Nested arrays → "a[b]" keys (multipart bodies cannot nest). */
    private static function flatten(array $data, string $prefix = ''): array
    {
        $out = [];
        foreach ($data as $k => $v) {
            $key = $prefix === '' ? (string) $k : $prefix . '[' . $k . ']';
            if (is_array($v)) $out += self::flatten($v, $key); else $out[$key] = (string) $v;
        }
        return $out;
    }

    /* ---------- HTML parsing ---------- */

    const OK_CLASSES = ['wpcf7-mail-sent-ok', 'wpcf7-response-output', 'wpforms-confirmation-container', 'wpforms-confirmation-container-full', 'gform_confirmation_message', 'elementor-message-success', 'nf-response-msg', 'frm_message', 'ff-message-success', 'alert-success', 'success-message', 'form-success', 'thank-you', 'thankyou', 'text-success'];
    const ERR_CLASSES = ['wpcf7-not-valid-tip', 'wpcf7-validation-errors', 'wpcf7-mail-sent-ng', 'wpcf7-spam-blocked', 'wpcf7-aborted', 'wpforms-error', 'wpforms-error-container', 'gfield_error', 'validation_error', 'validation_message', 'elementor-message-danger', 'nf-error-msg', 'frm_error', 'ff-errors-in-stack', 'error-message', 'alert-danger', 'form-error', 'invalid-feedback', 'field-error'];

    private static function dom(string $html): ?DOMDocument
    {
        if (trim($html) === '') return null;
        $doc = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $ok = $doc->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        return $ok ? $doc : null;
    }

    /** Small CSS → XPath converter: tag, #id, .class, [attr], [attr=v], [attr*=v], [attr^=v], descendant (space) and child (>) combinators, comma lists. */
    /**
     * Split a CSS selector list on commas ($on = ',') or a compound selector on combinators ($on = ' ': whitespace / ">",
     * returning ">" as its own token) while IGNORING separators inside quotes, [...] and (...).
     * Attribute values with spaces (form[name="New Form"]) must never be split – that made every such form "not found".
     */
    private static function splitSelector(string $sel, string $on): array
    {
        $out = []; $buf = ''; $depth = 0; $quote = null; $n = strlen($sel);
        for ($i = 0; $i < $n; $i++) {
            $ch = $sel[$i];
            if ($quote !== null) { $buf .= $ch; if ($ch === '\\' && $i + 1 < $n) { $buf .= $sel[++$i]; } elseif ($ch === $quote) $quote = null; continue; }
            if ($ch === '"' || $ch === "'") { $quote = $ch; $buf .= $ch; continue; }
            if ($ch === '[' || $ch === '(') { $depth++; $buf .= $ch; continue; }
            if ($ch === ']' || $ch === ')') { $depth = max(0, $depth - 1); $buf .= $ch; continue; }
            if ($depth === 0) {
                if ($on === ',' && $ch === ',') { if (trim($buf) !== '') $out[] = trim($buf); $buf = ''; continue; }
                if ($on === ' ' && ($ch === '>' || ctype_space($ch))) { if (trim($buf) !== '') $out[] = trim($buf); $buf = ''; if ($ch === '>') $out[] = '>'; continue; }
            }
            $buf .= $ch;
        }
        if (trim($buf) !== '') $out[] = trim($buf);
        return $out;
    }

    public static function cssToXPath(string $css): ?string
    {
        $css = trim($css);
        if ($css === '') return null;
        if (str_starts_with($css, 'xpath=')) return substr($css, 6);
        $paths = [];
        foreach (self::splitSelector($css, ',') as $sel) {
            $tokens = self::splitSelector($sel, ' ');
            $xp = '';
            $combinator = '//';
            foreach ($tokens as $tok) {
                if ($tok === '>') { $combinator = '/'; continue; }
                if (!preg_match('~^([a-zA-Z*][\w-]*)?((?:[#.][\w-]+|\[[^\]]+\]|:[\w-]+(?:\([^)]*\))?)*)$~', $tok, $m)) return null;
                $tag = $m[1] ?: '*';
                $pred = '';
                if (preg_match_all('~[#.][\w-]+|\[[^\]]+\]|:[\w-]+(?:\([^)]*\))?~', $m[2], $parts)) {
                    foreach ($parts[0] as $p) {
                        if ($p[0] === '#') $pred .= "[@id='" . substr($p, 1) . "']";
                        elseif ($p[0] === '.') $pred .= "[contains(concat(' ',normalize-space(@class),' '),' " . substr($p, 1) . " ')]";
                        elseif ($p[0] === '[') {
                            $inner = trim($p, '[]');
                            if (preg_match('#^([\w-]+)\s*([*^$~|]?=)\s*["\']?([^"\']*)["\']?$#', $inner, $a)) {
                                $v = str_replace("'", "\\'", $a[3]);
                                $pred .= match ($a[2]) { '=' => "[@{$a[1]}='$v']", '*=' => "[contains(@{$a[1]},'$v')]", '^=' => "[starts-with(@{$a[1]},'$v')]", '$=' => "[substring(@{$a[1]},string-length(@{$a[1]})-string-length('$v')+1)='$v']", '~=' => "[contains(concat(' ',normalize-space(@{$a[1]}),' '),' $v ')]", default => "[@{$a[1]}='$v']" };
                            } elseif (preg_match('~^[\w-]+$~', $inner)) $pred .= "[@$inner]";
                            else return null;
                        } elseif (preg_match('~^:nth-of-type\((\d+)\)$~', $p, $n) || preg_match('~^:nth-child\((\d+)\)$~', $p, $n)) $pred .= "[{$n[1]}]";
                        elseif ($p === ':first-child' || $p === ':first-of-type') $pred .= '[1]';
                        // other pseudo classes are ignored
                    }
                }
                $xp .= $combinator . $tag . $pred;
                $combinator = '//';
            }
            $paths[] = $xp;
        }
        return implode(' | ', $paths);
    }

    private static function selectorExists(string $html, string $selector): bool
    {
        $doc = self::dom($html);
        $xp = self::cssToXPath($selector);
        if (!$doc || !$xp) return false;
        $nodes = @(new DOMXPath($doc))->query($xp);
        return $nodes && $nodes->length > 0;
    }

    /** Locate the form to test in the page HTML and parse its fields. */
    public static function findForm(string $html, array $form, string $pageUrl): ?array
    {
        $doc = self::dom($html);
        if (!$doc) return null;
        $x = new DOMXPath($doc);
        $candidates = [];
        $selector = null;
        $scope = null;
        if (!empty($form['popup_selector'])) {
            $xp = self::cssToXPath($form['popup_selector']);
            $n = $xp ? @$x->query($xp) : null;
            if ($n && $n->length) $scope = $n->item(0);
        }
        if (!empty($form['form_selector'])) {
            $xp = self::cssToXPath($form['form_selector']);
            if ($xp) {
                $n = @$x->query($scope && !str_starts_with($xp, '/') ? '.' . $xp : $xp, $scope);
                if (!$n || !$n->length) $n = @$x->query($xp);
                if ($n && $n->length) {
                    foreach ($n as $el) {
                        $f = $el->nodeName === 'form' ? $el : $x->query('.//form', $el)->item(0);
                        if ($f) { $candidates[] = $f; break; }
                    }
                    $selector = $form['form_selector'];
                }
            }
            if (!$candidates) return null;
        } else {
            $list = $x->query('.//form', $scope ?: $doc);
            foreach ($list as $f) $candidates[] = $f;
            if (!$candidates && $scope) foreach ($x->query('//form') as $f) $candidates[] = $f;
        }
        if (!$candidates) return null;

        $overrideNames = array_keys(self::overrides($form, self::values($form, 'X')));
        $best = null;
        $bestScore = -999;
        foreach ($candidates as $f) {
            $p = self::parseForm($f, $x, $pageUrl, $html);
            $score = 0;
            $names = array_keys($p['fields']);
            $lowerNames = strtolower(implode(' ', $names));
            if ($overrideNames && array_intersect($overrideNames, $names)) $score += 50;
            if ($selector) $score += 100;
            if ($p['has_email']) $score += 20;
            if ($p['has_textarea']) $score += 15;
            if ($p['plugin']) $score += 10;
            if ($p['is_search']) $score -= 100;
            if ($p['is_login']) $score -= 100;
            if (preg_match('~newsletter|subscribe~', $lowerNames)) $score += 2;
            $score += min(count($p['fields']), 8);
            if ($score > $bestScore) { $bestScore = $score; $best = $p; }
        }
        if ($best) $best['selector'] = $selector;
        return $best;
    }

    public static function parseForm(DOMElement $f, DOMXPath $x, string $pageUrl, string $pageHtml): array
    {
        $fields = [];
        $hasEmail = false; $hasTextarea = false; $isSearch = false; $isLogin = false;
        $formId = $f->getAttribute('id');
        $nodes = $x->query('.//input|.//textarea|.//select|.//button', $f);
        $extra = $formId !== '' ? $x->query('//*[@form="' . $formId . '"][self::input or self::textarea or self::select]') : null;
        $all = [];
        foreach ($nodes as $n) $all[] = $n;
        if ($extra) foreach ($extra as $n) $all[] = $n;
        $submitCount = 0;
        foreach ($all as $n) {
            $tag = strtolower($n->nodeName);
            $type = strtolower($n->getAttribute('type') ?: ($tag === 'input' ? 'text' : $tag));
            $name = $n->getAttribute('name');
            if ($tag === 'button' || $type === 'submit' || $type === 'image') {
                $submitCount++;
                if ($name !== '' && ($type === 'submit' || $tag === 'button') && $submitCount === 1) {
                    $fields[$name] = ['type' => 'submit', 'value' => $n->getAttribute('value') ?: '', 'required' => false, 'tag' => $tag, 'hint' => $name, 'hidden' => false];
                }
                continue;
            }
            if ($name === '' || $type === 'reset' || $type === 'button' || $type === 'file') continue;
            if ($type === 'search' || $name === 's' && count($all) <= 3) $isSearch = true;
            if ($type === 'password') $isLogin = true;
            if ($type === 'email') $hasEmail = true;
            if ($tag === 'textarea') $hasTextarea = true;
            $label = '';
            $id = $n->getAttribute('id');
            if ($id !== '') {
                $l = $x->query('//label[@for="' . $id . '"]')->item(0);
                if ($l) $label = $l->textContent;
            }
            $parentLabel = $n->parentNode instanceof DOMElement && strtolower($n->parentNode->nodeName) === 'label' ? $n->parentNode->textContent : '';
            $hint = $name . ' ' . $id . ' ' . $n->getAttribute('placeholder') . ' ' . $n->getAttribute('aria-label') . ' ' . $n->getAttribute('class') . ' ' . $label . ' ' . $parentLabel;
            $style = strtolower($n->getAttribute('style') . ' ' . ($n->parentNode instanceof DOMElement ? $n->parentNode->getAttribute('style') : ''));
            $honeypot = $type !== 'hidden' && (str_contains($style, 'display:none') || str_contains($style, 'display: none') || preg_match('~honey|_hp\b|\bhp_|hpot|bot-?check|website_check|fax_number|^fax$|url_confirm~i', $name . ' ' . $id . ' ' . $n->getAttribute('class')));
            $value = $n->getAttribute('value');
            if ($tag === 'textarea') $value = $n->textContent;
            if ($tag === 'select') {
                $value = '';
                $options = [];
                foreach ($x->query('.//option', $n) as $o) {
                    $ov = trim($o->hasAttribute('value') ? $o->getAttribute('value') : $o->textContent);
                    $options[] = ['value' => $ov, 'text' => trim($o->textContent), 'selected' => $o->hasAttribute('selected'), 'disabled' => $o->hasAttribute('disabled')];
                    if ($o->hasAttribute('selected')) $value = $ov;
                }
            }
            $entry = ['type' => $type, 'value' => $value, 'required' => $n->hasAttribute('required') || str_contains($n->getAttribute('class'), 'required') || $n->getAttribute('aria-required') === 'true', 'tag' => $tag, 'hint' => trim(preg_replace('~\s+~', ' ', $hint)), 'hidden' => $type === 'hidden', 'honeypot' => (bool) $honeypot, 'checked' => $n->hasAttribute('checked')];
            if (isset($options)) { $entry['options'] = $options; unset($options); }
            if ($type === 'radio' || $type === 'checkbox') {
                $fields[$name]['type'] = $type;
                $fields[$name]['tag'] = $tag;
                $fields[$name]['hint'] = $entry['hint'];
                $fields[$name]['required'] = ($fields[$name]['required'] ?? false) || $entry['required'];
                $fields[$name]['hidden'] = false;
                $fields[$name]['honeypot'] = false;
                $fields[$name]['choices'][] = ['value' => $value !== '' ? $value : 'on', 'checked' => $entry['checked']];
                continue;
            }
            $fields[$name] = $entry;
        }
        // Elementor field groups whose input is rendered by JavaScript (add-on field types: phone with country code, date pickers…)
        // only contain a <label> in the server HTML. The handler still validates them, so synthesize form_fields[<id>].
        foreach ($x->query('.//*[contains(concat(" ",normalize-space(@class)," ")," elementor-field-group ")]', $f) as $g) {
            if ($x->query('.//input|.//select|.//textarea', $g)->length) continue;
            if (!preg_match('~(?:^|\s)elementor-field-group-([\w-]+)(?:\s|$)~', $g->getAttribute('class'), $gm)) continue;
            $gid = $gm[1];
            $fname = 'form_fields[' . $gid . ']';
            if (isset($fields[$fname]) || in_array($gid, ['submit', 'step', 'recaptcha', 'recaptcha_v3', 'honeypot', 'html', 'hidden'], true)) continue;
            $lbl = $x->query('.//label', $g)->item(0);
            $gtype = preg_match('~elementor-field-type-([\w-]+)~', $g->getAttribute('class'), $tm) ? $tm[1] : 'text';
            $fields[$fname] = ['type' => 'text', 'value' => '', 'required' => str_contains($g->getAttribute('class'), 'elementor-field-required'), 'tag' => 'input',
                'hint' => trim(preg_replace('~\s+~', ' ', $gid . ' ' . $gtype . ' ' . ($lbl ? $lbl->textContent : ''))), 'hidden' => false, 'honeypot' => false, 'checked' => false, 'synthetic' => true];
        }
        $action = trim($f->getAttribute('action'));
        $actionUrl = $action === '' ? $pageUrl : self::absolute($action, $pageUrl);
        $formHtml = $f->ownerDocument->saveHTML($f);
        $classAttr = strtolower($f->getAttribute('class') . ' ' . $formId);
        $plugin = self::detectPlugin($classAttr, $formHtml, $fields, $pageHtml);
        $captchaInfo = self::detectCaptcha($formHtml, $fields, $pageHtml);
        $captcha = (bool) $captchaInfo['type'];
        return ['action' => $actionUrl, 'method' => strtoupper($f->getAttribute('method') ?: 'GET'), 'enctype' => strtolower($f->getAttribute('enctype')), 'fields' => $fields, 'id' => $formId, 'class' => $f->getAttribute('class'),
            'has_email' => $hasEmail, 'has_textarea' => $hasTextarea, 'is_search' => $isSearch, 'is_login' => $isLogin, 'plugin' => $plugin, 'captcha' => $captcha, 'captcha_info' => $captchaInfo, 'html' => $formHtml, 'selector' => null];
    }

    public static function absolute(string $href, string $base): string
    {
        if (preg_match('~^https?://~i', $href)) return $href;
        $b = parse_url($base);
        $origin = ($b['scheme'] ?? 'https') . '://' . ($b['host'] ?? '') . (isset($b['port']) ? ':' . $b['port'] : '');
        if (str_starts_with($href, '//')) return ($b['scheme'] ?? 'https') . ':' . $href;
        if (str_starts_with($href, '/')) return $origin . $href;
        if (str_starts_with($href, '?')) return $origin . ($b['path'] ?? '/') . $href;
        $dir = preg_replace('~/[^/]*$~', '/', $b['path'] ?? '/');
        return $origin . $dir . $href;
    }

    public static function detectPlugin(string $classAttr, string $formHtml, array $fields, string $pageHtml = ''): ?string
    {
        $names = implode(' ', array_keys($fields));
        if (str_contains($classAttr, 'wpcf7-form') || isset($fields['_wpcf7'])) return 'cf7';
        if (str_contains($classAttr, 'wpforms-form') || str_contains($names, 'wpforms[')) return 'wpforms';
        if (str_contains($classAttr, 'elementor-form') || str_contains($names, 'form_fields[')) return 'elementor';
        if (preg_match('~gform_\d+|gform_wrapper~', $classAttr) || preg_match('~\binput_\d+~', $names)) return 'gravity';
        if (str_contains($classAttr, 'frm-fluent-form') || str_contains($classAttr, 'fluentform') || str_contains($formHtml, 'fluentform')) return 'fluent';
        if (str_contains($classAttr, 'nf-form') || str_contains($pageHtml, 'nfForms')) return 'ninja';
        if (str_contains($classAttr, 'frm-show-form') || str_contains($classAttr, 'frm_forms') || isset($fields['frm_action'])) return 'formidable';
        if (str_contains($classAttr, 'forminator')) return 'forminator';
        if (str_contains($classAttr, 'hs-form')) return 'hubspot';
        return null;
    }

    public static function pluginLabel(?string $p): string
    {
        return ['cf7' => 'Contact Form 7', 'wpforms' => 'WPForms', 'elementor' => 'Elementor Form', 'gravity' => 'Gravity Forms', 'fluent' => 'Fluent Forms', 'ninja' => 'Ninja Forms', 'formidable' => 'Formidable Forms', 'forminator' => 'Forminator', 'hubspot' => 'HubSpot'][$p] ?? ($p ? ucfirst($p) : '');
    }

    /** Decide a value for every field. Returns [data, filledLabels, skippedLabels]. */
    private static function fillFields(array $parsed, array $values, array $overrides): array
    {
        $data = [];
        $filled = [];
        $skipped = [];
        foreach ($parsed['fields'] as $name => $f) {
            if (array_key_exists($name, $overrides)) {
                $data[$name] = (string) $overrides[$name];
                $filled[] = $name . '=' . truncate((string) $overrides[$name], 30);
                continue;
            }
            if ($f['type'] === 'hidden' || $f['type'] === 'submit') { $data[$name] = $f['value']; continue; }
            if (!empty($f['honeypot'])) { $skipped[] = $name . ' (honeypot)'; continue; }
            if ($f['type'] === 'checkbox') {
                $k = strtolower($f['hint']);
                $checked = array_filter($f['choices'] ?? [], fn($c) => $c['checked']);
                if ($checked) { $data[$name] = array_values($checked)[0]['value']; }
                elseif ($f['required'] || preg_match('~agree|accept|consent|terms|privacy|policy|gdpr|subscribe|confirm~', $k)) { $data[$name] = $f['choices'][0]['value'] ?? 'on'; $filled[] = $name . '=on'; }
                continue;
            }
            if ($f['type'] === 'radio') {
                $checked = array_filter($f['choices'] ?? [], fn($c) => $c['checked']);
                $data[$name] = $checked ? array_values($checked)[0]['value'] : ($f['choices'][0]['value'] ?? '');
                if (!$checked) $filled[] = $name . '=' . $data[$name];
                continue;
            }
            if ($f['tag'] === 'select') {
                if (trim($f['value']) !== '') { $data[$name] = $f['value']; continue; }
                $opt = null;
                foreach ($f['options'] ?? [] as $o) { if ($o['value'] !== '' && !$o['disabled'] && !preg_match('~select|choose|--~i', $o['text'])) { $opt = $o; break; } }
                if (!$opt) foreach ($f['options'] ?? [] as $o) { if ($o['value'] !== '' && !$o['disabled']) { $opt = $o; break; } }
                if ($opt) { $data[$name] = $opt['value']; $filled[] = $name . '=' . truncate($opt['value'], 20); }
                continue;
            }
            if ($f['value'] !== '' && !$f['required'] && $f['type'] !== 'text' && $f['tag'] !== 'textarea') { $data[$name] = $f['value']; continue; }
            $cls = self::classify($f['hint'], $f['type'], $f['tag']);
            $v = match ($cls) {
                'email' => $values['email'], 'phone' => $values['phone'], 'message' => $values['message'], 'first_name' => $values['first_name'], 'last_name' => $values['last_name'], 'name' => $values['name'],
                'subject' => $values['subject'], 'company' => $values['company'], 'city' => $values['city'], 'website' => $values['website'], 'number' => '1', 'date' => $values['date'],
                'captcha' => null, default => ($f['type'] === 'password' ? $values['password'] : ($f['required'] || $f['value'] === '' ? $values['text'] : $f['value'])),
            };
            if ($cls === 'captcha') { $skipped[] = $name . ' (captcha)'; continue; }
            if ($v === null) { if ($f['value'] !== '') $data[$name] = $f['value']; continue; }
            $data[$name] = $v;
            $filled[] = $name . '=' . truncate($v, 30);
        }
        // Extra payload / owner-approved test parameters that are not fields of the form are sent as well
        foreach ($overrides as $k => $v) {
            if (!array_key_exists($k, $data) && is_scalar($v)) { $data[$k] = (string) $v; $filled[] = $k . '=' . truncate((string) $v, 30) . ' (extra)'; }
        }
        return [$data, $filled, $skipped];
    }

    /** Plugin-aware request: url, method, data, headers, ajax flag, multipart flag. */
    private static function buildRequest(array $parsed, array $data, array $form, string $pageUrl, array $website): array
    {
        $site = self::siteRoot($pageUrl, $website);
        $ajaxHeaders = ['X-Requested-With: XMLHttpRequest', 'Accept: application/json, text/javascript, */*; q=0.01', 'Origin: ' . rtrim($site, '/')];
        $ajaxFlag = !empty($form['ajax']) || in_array($form['form_kind'] ?? '', ['ajax', 'popup', 'wordpress'], true);
        $target = !empty($form['form_url']) ? $form['form_url'] : $parsed['action'];
        $method = strtoupper($form['method'] ?? '') === 'GET' && empty($form['form_url']) && $parsed['method'] === 'GET' ? 'GET' : ($parsed['method'] === 'GET' && empty($form['form_url']) ? 'GET' : 'POST');
        if (!empty($form['form_url'])) $method = strtoupper($form['method'] ?? 'POST') === 'GET' ? 'GET' : 'POST';

        switch ($parsed['plugin']) {
            case 'cf7':
                $id = $data['_wpcf7'] ?? null;
                if ($id && empty($form['form_url'])) {
                    $data['_wpcf7_unit_tag'] = $data['_wpcf7_unit_tag'] ?? ('wpcf7-f' . $id . '-p0-o1');
                    $data['_wpcf7_posted_data_hash'] = $data['_wpcf7_posted_data_hash'] ?? '';
                    return ['url' => $site . 'wp-json/contact-form-7/v1/contact-forms/' . (int) $id . '/feedback', 'method' => 'POST', 'data' => $data, 'headers' => $ajaxHeaders, 'ajax' => true, 'multipart' => true];
                }
                break;
            case 'wpforms':
                if (empty($form['form_url'])) {
                    $data['action'] = 'wpforms_submit';
                    if (!isset($data['wpforms[id]']) && preg_match('~wpforms-form-(\d+)~', $parsed['id'] . ' ' . $parsed['class'], $m)) $data['wpforms[id]'] = $m[1];
                    return ['url' => $site . 'wp-admin/admin-ajax.php', 'method' => 'POST', 'data' => self::nest($data), 'headers' => $ajaxHeaders, 'ajax' => true, 'multipart' => true];
                }
                break;
            case 'elementor':
                if (empty($form['form_url'])) {
                    $data['action'] = 'elementor_pro_forms_send_form';
                    $data['referrer'] = $pageUrl;
                    if (!isset($data['queried_id']) && isset($data['post_id'])) $data['queried_id'] = $data['post_id'];
                    return ['url' => $site . 'wp-admin/admin-ajax.php', 'method' => 'POST', 'data' => self::nest($data), 'headers' => $ajaxHeaders, 'ajax' => true, 'multipart' => true];
                }
                break;
            case 'fluent':
                if (empty($form['form_url'])) {
                    $formId = null;
                    if (preg_match('~data-form_id=["\'](\d+)~', $parsed['html'], $m)) $formId = $m[1];
                    if (!$formId && preg_match('~fluentform_(\d+)~', $parsed['id'] . ' ' . $parsed['class'], $m)) $formId = $m[1];
                    $payload = ['action' => 'fluentform_submit', 'form_id' => $formId, 'data' => http_build_query($data), 'url' => $pageUrl];
                    return ['url' => $site . 'wp-admin/admin-ajax.php', 'method' => 'POST', 'data' => $payload, 'headers' => $ajaxHeaders, 'ajax' => true, 'multipart' => false];
                }
                break;
            case 'gravity':
                if (empty($form['form_url'])) {
                    if (preg_match('~gform_(\d+)~', $parsed['id'], $m)) {
                        $data['is_submit_' . $m[1]] = $data['is_submit_' . $m[1]] ?? '1';
                        $data['gform_submit'] = $data['gform_submit'] ?? $m[1];
                    }
                    return ['url' => $parsed['action'] ?: $pageUrl, 'method' => 'POST', 'data' => $data, 'headers' => [], 'ajax' => false, 'multipart' => true];
                }
                break;
            case 'formidable':
                if (empty($form['form_url'])) {
                    $data['frm_action'] = $data['frm_action'] ?? 'create';
                    return ['url' => $parsed['action'] ?: $pageUrl, 'method' => 'POST', 'data' => $data, 'headers' => [], 'ajax' => false, 'multipart' => true];
                }
                break;
        }
        $multipart = $parsed['enctype'] === 'multipart/form-data';
        return ['url' => $target, 'method' => $method, 'data' => $method === 'GET' ? $data : ($multipart ? $data : $data), 'headers' => $ajaxFlag ? $ajaxHeaders : [], 'ajax' => $ajaxFlag, 'multipart' => $multipart];
    }

    /** "a[b][c]" keys → nested arrays (so http_build_query / multipart produce the same names). */
    private static function nest(array $flat): array
    {
        $out = [];
        foreach ($flat as $k => $v) {
            if (!str_contains($k, '[')) { $out[$k] = $v; continue; }
            parse_str($k . '=' . rawurlencode((string) $v), $tmp);
            $out = array_replace_recursive($out, $tmp);
        }
        return $out;
    }

    private static function siteRoot(string $pageUrl, array $website): string
    {
        $u = parse_url($pageUrl);
        $root = ($u['scheme'] ?? 'https') . '://' . ($u['host'] ?? '') . (isset($u['port']) ? ':' . $u['port'] : '') . '/';
        // WordPress in a sub-folder: keep the folder that precedes /wp-content or the website URL path
        $wp = $website['url'] ?? '';
        if ($wp && parse_url($wp, PHP_URL_HOST) === ($u['host'] ?? '')) {
            $path = trim((string) parse_url($wp, PHP_URL_PATH), '/');
            if ($path !== '') $root .= $path . '/';
        }
        return $root;
    }

    /* ---------- response analysis ---------- */

    public static function textOf(string $html): string
    {
        $html = preg_replace('~<(script|style|noscript)\b[^>]*>.*?</\1>~is', ' ', $html);
        return trim(preg_replace('~\s+~', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    public static function jsonOf(string $body): ?array
    {
        $b = trim($body);
        if ($b === '' || ($b[0] !== '{' && $b[0] !== '[')) return null;
        $j = json_decode($b, true);
        return is_array($j) ? $j : null;
    }

    private static function textByClass(string $html, array $classes): array
    {
        $doc = self::dom($html);
        if (!$doc) return [];
        $x = new DOMXPath($doc);
        $out = [];
        foreach ($classes as $c) {
            foreach ($x->query("//*[contains(concat(' ',normalize-space(@class),' '),' $c ')]") as $n) {
                $style = strtolower($n->getAttribute('style'));
                if (str_contains($style, 'display:none') || str_contains($style, 'display: none')) continue;
                $t = trim(preg_replace('~\s+~', ' ', $n->textContent));
                if ($t !== '' && mb_strlen($t) < 600 && !in_array($t, $out, true)) $out[] = $t;
            }
        }
        return array_slice($out, 0, 8);
    }

    /**
     * Shared verdict logic for both engines.
     * @return array{0:bool,1:?string,2:?string,3:?string} success, reason, error, excerpt
     */
    public static function judge(array $c): array
    {
        $bodyLower = strtolower($c['body_text'] ?? '');
        $newErrors = array_values(array_diff($c['errors'] ?? [], $c['errors_before'] ?? []));
        $newMessages = $c['messages'] ?? [];
        $msgText = strtolower(implode(' | ', array_merge($newMessages, $newErrors)));
        $responses = array_values(array_filter($c['responses'] ?? [], fn($r) => !self::ignoredUrl($r['url'] ?? '')));
        $main = $responses[0] ?? null;
        $mainStatus = (int) ($main['status'] ?? 0);
        $json = null;
        foreach ($responses as $rr) { if (!empty($rr['json'])) { $json = $rr['json']; break; } }
        $jsonVerdict = $json ? self::judgeJson($json, $c['plugin'] ?? null) : null; // [ok, message] | null
        $captchaHint = !empty($c['captcha']) ? ' A CAPTCHA is present on the form – it may be blocking automated submissions; ask the site owner to allow the CRM test address or disable CAPTCHA for the test.' : '';

        // Hard HTTP failures first
        foreach ($responses as $rr) {
            $st = (int) ($rr['status'] ?? 0);
            if ($st >= 500 || ($st >= 400 && ($rr['type'] ?? '') !== 'Document')) {
                $label = ($rr['type'] ?? '') === 'Document' ? 'Submission returned HTTP ' . $st : 'AJAX request returned HTTP ' . $st;
                $msg = $jsonVerdict[1] ?? truncate(self::textOf((string) ($rr['body'] ?? '')), 160);
                return [false, $label, ($rr['url'] ?? '') . ' responded with HTTP ' . $st . ' ' . Monitor::httpText($st) . ($msg ? ' – ' . $msg : ''), null];
            }
        }
        if (!empty($c['expect_code']) && $mainStatus && $mainStatus !== (int) $c['expect_code']) {
            return [false, 'Unexpected HTTP response', 'Expected HTTP ' . $c['expect_code'] . ' but received HTTP ' . $mainStatus . ' ' . Monitor::httpText($mainStatus), null];
        }
        if ($c['expect_redirect'] !== '') {
            if (stripos((string) $c['final_url'], $c['expect_redirect']) === false) {
                return [false, 'Expected redirect not reached', 'Expected a redirect to "' . $c['expect_redirect'] . '" but the final URL was ' . ($c['final_url'] ?: 'unchanged') . ($newErrors ? '. Errors shown: ' . truncate(implode(' | ', $newErrors), 200) : '') . $captchaHint, null];
            }
        }
        if ($c['expect_text'] !== '') {
            $needle = strtolower($c['expect_text']);
            $found = str_contains($bodyLower, $needle) || str_contains($msgText, $needle);
            if (!$found) foreach ($responses as $rr) { if (stripos((string) ($rr['body'] ?? ''), $c['expect_text']) !== false) { $found = true; break; } }
            if (!$found) {
                $detail = 'The page / response did not contain "' . $c['expect_text'] . '".';
                if ($newErrors) $detail .= ' Errors shown: ' . truncate(implode(' | ', $newErrors), 220);
                elseif ($jsonVerdict && !$jsonVerdict[0]) $detail .= ' Response: ' . truncate($jsonVerdict[1], 200);
                elseif ($newMessages) $detail .= ' Message shown instead: ' . truncate(implode(' | ', $newMessages), 200);
                elseif ($c['timed_out']) $detail .= ' No response was observed within the wait time.';
                else $detail .= ' Response: ' . truncate($c['body_text'] ?? '', 160);
                return [false, 'Expected success message not found', $detail . $captchaHint, null];
            }
        }
        if ($c['expect_response'] !== '') {
            $found = false;
            foreach ($responses as $rr) { if (stripos((string) ($rr['body'] ?? ''), $c['expect_response']) !== false) { $found = true; break; } }
            if (!$found && str_contains($bodyLower, strtolower($c['expect_response']))) $found = true;
            if (!$found) return [false, 'Expected response not found', 'The submission response did not contain "' . $c['expect_response'] . '". Response: ' . truncate($main['body'] ?? ($c['body_text'] ?? ''), 200), null];
        }
        // Explicit expectations satisfied → success unless the response itself reports an error
        $explicit = $c['expect_text'] !== '' || $c['expect_redirect'] !== '' || $c['expect_response'] !== '';
        if ($jsonVerdict && !$jsonVerdict[0] && !$explicit) {
            return [false, self::reasonFromMessage($jsonVerdict[1]), 'The form handler reported an error: ' . truncate($jsonVerdict[1], 300) . $captchaHint, null];
        }
        if ($newErrors && !$explicit) {
            return [false, self::reasonFromMessage(implode(' ', $newErrors)), 'The form showed an error after submission: ' . truncate(implode(' | ', $newErrors), 300) . $captchaHint, null];
        }
        if ($explicit) return [true, null, null, 'Submitted (HTTP ' . $mainStatus . ')' . ($c['expect_text'] !== '' ? ' · success text found' : '') . ($c['expect_redirect'] !== '' ? ' · redirect OK' : '') . ($c['expect_response'] !== '' ? ' · response OK' : '')];

        // Automatic verdict
        if ($jsonVerdict && $jsonVerdict[0]) return [true, null, null, 'Accepted (HTTP ' . $mainStatus . ') · ' . truncate($jsonVerdict[1] ?: 'success response', 160)];
        $bodyDiff = $bodyLower;
        if (!empty($c['body_before'])) {
            $before = strtolower($c['body_before']);
            $bodyDiff = trim(str_replace(preg_split('~\s+~', $before), '', $bodyLower)) ?: $bodyLower;
        }
        foreach (self::FAIL_PHRASES as $p) {
            if (str_contains($msgText, $p) || ($responses && !empty($main['type']) && $main['type'] === 'XHR' && str_contains(strtolower((string) ($main['body'] ?? '')), $p))) {
                $full = $msgText ?: self::textOf((string) ($main['body'] ?? ''));
                return [false, self::reasonFromMessage($full . ' ' . $p), 'The response contains failure text "' . $p . '": ' . truncate($full, 200) . $captchaHint, null];
            }
        }
        foreach (self::OK_PHRASES as $p) {
            if (str_contains($msgText, $p)) return [true, null, null, 'Submitted (HTTP ' . $mainStatus . ') · ' . truncate(implode(' | ', $newMessages), 160)];
        }
        if (!empty($c['navigated'])) {
            // Redirected somewhere else: make sure it is not an error page
            foreach (['fatal error', 'internal server error', 'not found', 'forbidden', 'error 5'] as $p) {
                if (str_contains($bodyLower, $p) && mb_strlen($bodyLower) < 600) return [false, 'Redirected to an error page', 'After submission the browser landed on ' . $c['final_url'] . ' which shows: ' . truncate($c['body_text'], 160), null];
            }
            return [true, null, null, 'Submitted (HTTP ' . $mainStatus . ') · redirected to ' . truncate($c['final_url'], 80)];
        }
        if ($newMessages) {
            foreach (self::FAIL_PHRASES as $p) if (str_contains($msgText, $p)) return [false, self::reasonFromMessage($msgText . ' ' . $p), 'The form showed: ' . truncate(implode(' | ', $newMessages), 250) . $captchaHint, null];
            return [true, null, null, 'Submitted (HTTP ' . $mainStatus . ') · ' . truncate(implode(' | ', $newMessages), 160)];
        }
        if (!empty($c['popup_closed']) || !empty($c['form_hidden'])) return [true, null, null, 'Submitted (HTTP ' . $mainStatus . ') · form closed after submission'];
        foreach (self::OK_PHRASES as $p) {
            if (str_contains($bodyDiff, $p)) return [true, null, null, 'Submitted (HTTP ' . $mainStatus . ') · success text found on page'];
        }
        foreach (self::FAIL_PHRASES as $p) {
            // failure text that appeared only after the submission (it was not part of the page before)
            if (str_contains($bodyDiff, $p) && !str_contains(strtolower($c['body_before'] ?? ''), $p)) return [false, self::reasonFromMessage(truncate($bodyDiff, 600) . ' ' . $p), 'The page shows failure text "' . $p . '" after submission: ' . truncate($bodyDiff, 200) . $captchaHint, null];
        }
        if ($responses && $mainStatus >= 200 && $mainStatus < 400) {
            if (($main['type'] ?? '') === 'XHR' || ($c['ajax'] ?? false)) {
                $txt = self::textOf((string) ($main['body'] ?? ''));
                if ($txt === '' || $txt === '0' || preg_match('~^(ok|success|true|1|sent)$~i', $txt) || $json) return [true, null, null, 'Accepted (HTTP ' . $mainStatus . ')' . ($txt !== '' ? ' · ' . truncate($txt, 120) : '')];
                return [true, null, null, 'Accepted (HTTP ' . $mainStatus . ') · ' . truncate($txt, 120)];
            }
            if (empty($c['browser'])) {
                // HTTP engine: a 2xx page response with no error indicators counts as accepted
                return [true, null, null, 'Submitted (HTTP ' . $mainStatus . ') · ' . truncate($c['body_text'] ?? '', 120)];
            }
        }
        if (!empty($c['timed_out'])) return [false, 'Form submission timed out', 'No response, redirect or message was observed within ' . ($c['wait'] ?? 20) . ' seconds after clicking submit.' . $captchaHint, null];
        return [false, 'No response after submit', 'Submit was clicked but the page did not navigate, no request was sent and no message appeared.' . ($newErrors ? ' Errors: ' . truncate(implode(' | ', $newErrors), 200) : '') . $captchaHint, null];
    }

    /** Chat / support widgets and other third-party scripts: their requests never count as the form submission. */
    const CHAT_HOSTS = ['tawk.to', 'crisp.chat', 'intercom.io', 'intercomcdn.com', 'drift.com', 'driftt.com', 'tidio.co', 'tidiochat.com', 'zendesk.com', 'zdassets.com', 'zopim.com', 'hubspot.com', 'hs-scripts.com', 'hsforms.net',
        'freshchat.com', 'freshworks.com', 'livechatinc.com', 'livechat.com', 'smartsupp.com', 'jivosite.com', 'jivochat.com', 'olark.com', 'kommunicate.io', 'botpress.cloud', 'manychat.com', 'chatra.io', 'whatsapp.com', 'wa.me',
        'elfsight.com', 'getbutton.io', 'callbell.eu', 'respond.io', 'landbot.io', 'collect.chat', 'chatbot.com', 'chaport.com', 'userlike.com', 'purechat.com', 'gorgias.chat', 'reamaze.com', 'helpscout.net', 'onesignal.com', 'pushengage.com', 'cookieyes.com', 'cookiebot.com', 'termly.io'];

    private static function ignoredUrl(string $url): bool
    {
        foreach (self::IGNORE_HOSTS as $h) if (stripos($url, $h) !== false) return true;
        foreach (self::CHAT_HOSTS as $h) if (stripos($url, $h) !== false) return true;
        return false;
    }

    /* =====================================================================
     * CAPTCHA / anti-bot detection and classification (the CRM NEVER bypasses or solves a CAPTCHA)
     * ===================================================================== */

    /**
     * Detect CAPTCHA / anti-bot protection from the form HTML, its field names and the page HTML.
     * @return array{type:?string, in_form:bool, label:string, detail:string}
     */
    public static function detectCaptcha(string $formHtml, array $fields, string $pageHtml = ''): array
    {
        $f = strtolower($formHtml);
        $names = strtolower(implode(' ', array_keys($fields)));
        $p = strtolower($pageHtml);
        $hints = '';
        foreach ($fields as $n => $fd) $hints .= ' ' . strtolower((string) ($fd['hint'] ?? ''));
        $inForm = fn(string $re) => (bool) preg_match($re, $f . ' ' . $names);

        if ($inForm('~h-captcha|h-captcha-response|hcaptcha\.com|data-hcaptcha~')) return ['type' => 'hcaptcha', 'in_form' => true, 'label' => 'hCaptcha', 'detail' => 'hCaptcha widget inside the form'];
        if ($inForm('~cf-turnstile|cf-turnstile-response|turnstile~')) return ['type' => 'turnstile', 'in_form' => true, 'label' => 'Cloudflare Turnstile', 'detail' => 'Cloudflare Turnstile widget inside the form'];
        if ($inForm('~class="[^"]*g-recaptcha|g-recaptcha-response|data-sitekey|recaptcha_response|wpcf7-recaptcha|gglcptch|anr_captcha|wpforms-recaptcha|ginput_recaptcha|elementor-g-recaptcha|frm-g-recaptcha~')) {
            $v3 = preg_match('~recaptcha/api\.js\?render=|grecaptcha\.execute|recaptcha-v3|g-recaptcha-response-100~', $p . ' ' . $f) && !preg_match('~class="[^"]*g-recaptcha[^"]*"|data-size=|recaptcha/api\.js\?(?!render)~', $f);
            return ['type' => $v3 ? 'recaptcha_v3' : 'recaptcha_v2', 'in_form' => true, 'label' => 'Google reCAPTCHA ' . ($v3 ? 'v3' : 'v2'), 'detail' => 'Google reCAPTCHA ' . ($v3 ? 'v3 (invisible score check)' : 'v2 widget') . ' protects this form'];
        }
        // Plain / image / math CAPTCHA fields the visitor must type (Really Simple CAPTCHA, Contact Form 7 [captchac], BestWebSoft, SI CAPTCHA, custom "security code")
        foreach ($fields as $n => $fd) {
            $type = $fd['type'] ?? 'text';
            if (in_array($type, ['hidden', 'submit', 'checkbox', 'radio'], true)) continue;
            $k = strtolower($n . ' ' . ($fd['hint'] ?? ''));
            if (preg_match('~captcha|security.?code|verification.?code|are you human|human.?check|anti.?spam.?(answer|question)|math.?(question|answer)|\bsum\b.*\?|cptch_|si_captcha|wpcf7-captchar|_bwg_captcha~', $k)) {
                return ['type' => 'captcha_field', 'in_form' => true, 'label' => 'CAPTCHA field', 'detail' => 'The form contains a CAPTCHA / verification field ("' . $n . '") that a human must fill in'];
            }
        }
        if ($inForm('~ct_checkjs|cleantalk|wpa_|wp-armour|antispam-bee|cf7-antispam|ozh_|akismet_comment_nonce~')) return ['type' => 'antibot', 'in_form' => true, 'label' => 'Anti-bot plugin', 'detail' => 'An anti-bot / anti-spam plugin protects this form and may silently reject automated submissions'];
        // CAPTCHA scripts on the page but not inside this form (e.g. site-wide reCAPTCHA v3): the submission is attempted and the response decides
        if (preg_match('~recaptcha/api\.js|grecaptcha|google\.com/recaptcha~', $p)) return ['type' => 'recaptcha_v3', 'in_form' => false, 'label' => 'Google reCAPTCHA v3', 'detail' => 'A reCAPTCHA script is loaded on the page (not inside the form) – the submission is attempted and the response decides'];
        if (preg_match('~hcaptcha\.com/1/api\.js~', $p)) return ['type' => 'hcaptcha', 'in_form' => false, 'label' => 'hCaptcha', 'detail' => 'hCaptcha script loaded on the page'];
        if (preg_match('~challenges\.cloudflare\.com/turnstile~', $p)) return ['type' => 'turnstile', 'in_form' => false, 'label' => 'Cloudflare Turnstile', 'detail' => 'Turnstile script loaded on the page'];
        return ['type' => null, 'in_form' => false, 'label' => '', 'detail' => ''];
    }

    /**
     * Decide BEFORE submitting whether the test must stop because human CAPTCHA verification is required.
     * Returns true (and fills $r) when the form is blocked; false when the submission may proceed.
     */
    public static function captchaGate(array $form, array $det, array &$r): bool
    {
        $configured = $form['captcha_type'] ?? 'none';
        $mode = $form['captcha_mode'] ?? 'detect';
        $r['captcha'] = $det['type'] ?: ($configured !== 'none' ? $configured : null);
        if ($det['type']) $r['steps'][] = self::step('captcha', null, $det['detail']);
        if ($mode === 'test_field' && !empty($form['captcha_test_field'])) {
            $r['steps'][] = self::step('captcha', null, 'Owner-approved test parameter added to the submission (configured by the site owner) – the CAPTCHA itself is never bypassed');
            return false;
        }
        if ($mode === 'test_url' && !empty($form['captcha_test_url'])) {
            if ($det['type'] && $det['in_form']) {
                $r['reason'] = 'Requires CAPTCHA verification';
                $r['outcome'] = 'captcha_blocked';
                $r['error'] = 'The approved test page ' . $form['captcha_test_url'] . ' still contains ' . $det['label'] . ', so the form cannot be submitted automatically. Action: use a test page without CAPTCHA, or verify the form manually.';
                $r['steps'][] = self::step('captcha', null, 'Blocked – CAPTCHA present on the approved test page');
                return true;
            }
            return false;
        }
        $blockedBy = null;
        if ($configured !== 'none') $blockedBy = form_captcha_label($configured) . ' (configured for this form)';
        elseif ($det['type'] && $det['in_form']) $blockedBy = $det['label'];
        if ($blockedBy === null) return false;
        $r['reason'] = 'Requires CAPTCHA verification';
        $r['outcome'] = 'captcha_blocked';
        $r['error'] = 'The form requires human CAPTCHA verification (' . $blockedBy . '). The automated submission was not attempted – the CRM never bypasses or solves CAPTCHA. '
            . 'Action: verify the form manually, or configure an owner-approved test method (test/staging page without CAPTCHA, or a test parameter approved by the site owner) under Form → Automated Testing → CAPTCHA / Anti-Bot Protection.';
        $r['steps'][] = self::step('captcha', null, 'Automated test BLOCKED BY CAPTCHA – ' . $blockedBy . '. The page loads and the form is present; submission requires a human.');
        return true;
    }

    /** Apply the owner-approved test configuration (test page / test parameter) before the engines run. */
    private static function applyTestConfig(array $form, array &$r): array
    {
        $mode = $form['captcha_mode'] ?? 'detect';
        if ($mode === 'test_url' && !empty($form['captcha_test_url']) && valid_url($form['captcha_test_url'])) {
            $r['steps'][] = self::step('config', null, 'Using the owner-approved test page ' . $form['captcha_test_url'] . ' instead of the live page (CAPTCHA-free test environment)');
            $form['page_url'] = $form['captcha_test_url'];
        }
        if ($mode === 'test_field' && !empty($form['captcha_test_field'])) {
            $form['test_payload'] = trim((string) ($form['test_payload'] ?? '') . "\n" . $form['captcha_test_field']);
        }
        return $form;
    }

    /** Map a finished test result to one precise outcome (see form_outcomes()). */
    public static function classifyOutcome(array $r): string
    {
        if ($r['success']) return ($r['email_received'] ?? 'unknown') === 'yes' ? 'working' : 'email_unknown';
        if (!empty($r['outcome'])) return $r['outcome'];
        $reason = (string) ($r['reason'] ?? '');
        if (in_array($reason, ['Requires CAPTCHA verification', 'CAPTCHA blocked the submission', 'Submission rejected by anti-bot / spam protection'], true)) return 'captcha_blocked';
        // a configured selector / popup that does not exist is a monitoring configuration problem (in-app notice, no client alert)
        if (in_array($reason, ['Form selector not found', 'Popup not found'], true)) return 'config_error';
        if (in_array($reason, ['Form not found on page', 'Form is not visible'], true) || preg_match('~^Form page returned HTTP 4~', $reason)) return 'not_found';
        if (in_array($reason, Monitor::FORM_CONFIG_REASONS, true)) return 'config_error';
        if (preg_match('~returned HTTP 5|^Server error during submission|^Redirected to an error page~', $reason)) return 'server_error';
        if (stripos($reason, 'timed out') !== false) return 'timeout';
        if ($reason === 'JavaScript error on submit') return 'js_error';
        if (in_array($reason, [self::REASON_PAGE, 'Submission connection failed', 'AJAX request failed'], true)) return 'network_error';
        return 'failed';
    }

    /** Interpret a JSON response (WordPress plugins + generic). Returns [ok, message] or null when undecidable. */
    public static function judgeJson(array $j, ?string $plugin): ?array
    {
        $msg = null;
        foreach (['message', 'msg', 'data.message', 'data.msg', 'data.confirmation', 'data.result.message', 'error', 'errors', 'data.errors', 'data.data.message'] as $path) {
            $v = self::dig($j, $path);
            if ($v !== null && $v !== '' && $v !== []) { $msg = is_array($v) ? self::textOf(json_encode($v)) : self::textOf((string) $v); break; }
        }
        if (isset($j['status']) && is_string($j['status'])) {
            $s = strtolower($j['status']);
            if (in_array($s, ['mail_sent', 'success', 'ok', 'sent', 'completed', 'succeeded'], true)) return [true, $msg ?: $s];
            if (in_array($s, ['mail_failed', 'validation_failed', 'spam', 'aborted', 'acceptance_missing', 'error', 'fail', 'failed', 'invalid'], true)) return [false, $msg ?: $s];
        }
        if (array_key_exists('success', $j)) {
            $ok = filter_var($j['success'], FILTER_VALIDATE_BOOLEAN);
            return [$ok, $msg ?: ($ok ? 'success' : 'error')];
        }
        if (isset($j['errors']) && $j['errors']) return [false, $msg ?: 'errors'];
        if (isset($j['error']) && $j['error'] && !is_bool($j['error'])) return [false, $msg ?: 'error'];
        if (isset($j['error']) && $j['error'] === false) return [true, $msg ?: 'success'];
        if (isset($j['data']['result']) && $plugin === 'fluent') return [true, $msg ?: 'submitted'];
        if (isset($j['result']) && is_string($j['result'])) return [in_array(strtolower($j['result']), ['success', 'ok', 'sent'], true), $msg ?: $j['result']];
        if (isset($j['sent'])) return [(bool) $j['sent'], $msg ?: 'sent'];
        return null;
    }

    private static function dig(array $a, string $path)
    {
        foreach (explode('.', $path) as $k) {
            if (!is_array($a) || !array_key_exists($k, $a)) return null;
            $a = $a[$k];
        }
        return $a;
    }

    private static function reasonFromMessage(string $m): string
    {
        $l = strtolower($m);
        if (preg_match('~captcha|recaptcha|hcaptcha|turnstile|verify (that )?you are|not a robot|are you human|human verification|bot verification|security code|verification code~', $l)) return 'CAPTCHA blocked the submission';
        if (preg_match('~spam|anti-?bot|bot detected|automated (request|submission)|suspicious~', $l)) return 'Submission rejected by anti-bot / spam protection';
        if (preg_match('~nonce|security|token|csrf~', $l)) return 'Security check rejected the submission';
        if (preg_match('~smtp|mail|send|sent~', $l)) return 'SMTP/Email delivery failure';
        if (preg_match('~required|fill|invalid|valid|complete|field|format~', $l)) return 'Form validation error';
        if (preg_match('~not found|404~', $l)) return 'Form handler not found';
        if (preg_match('~forbidden|denied|403~', $l)) return 'Submission forbidden';
        if (preg_match('~fatal|internal|500~', $l)) return 'Server error during submission';
        return 'Error message after submission';
    }

    /* =====================================================================
     * Browser engine (headless Chrome)
     * ===================================================================== */

    const JS_HELPERS = <<<'JS'
window.__crm = window.__crm || (function () {
  function visible(el) { if (!el || !(el instanceof Element)) return false; const s = getComputedStyle(el); if (s.display === 'none' || s.visibility === 'hidden' || parseFloat(s.opacity) === 0) return false; const r = el.getBoundingClientRect(); return r.width > 0 && r.height > 0; }
  function q(sel, root) { root = root || document; if (!sel) return null; sel = String(sel).trim();
    if (sel.startsWith('text=')) { const t = sel.slice(5).trim().toLowerCase(); let best = null; for (const el of root.querySelectorAll('a,button,[role=button],input[type=submit],input[type=button],label,span,div,li,p,h1,h2,h3,h4')) { const tx = (el.innerText || el.value || '').trim().toLowerCase(); if (!tx) continue; if (tx === t && visible(el)) return el; if (!best && tx.includes(t) && visible(el) && tx.length < t.length + 40) best = el; } return best; }
    if (sel.startsWith('xpath=')) { try { const r = document.evaluate(sel.slice(6), root, null, XPathResult.ORDERED_NODE_SNAPSHOT_TYPE, null); for (let i = 0; i < r.snapshotLength; i++) if (visible(r.snapshotItem(i))) return r.snapshotItem(i); return r.snapshotLength ? r.snapshotItem(0) : null; } catch (e) { return null; } }
    let list; try { list = root.querySelectorAll(sel); } catch (e) { return q('text=' + sel, root); }
    for (const el of list) if (visible(el)) return el; return list.length ? list[0] : null; }
  function qAll(sel, root) { try { return Array.from((root || document).querySelectorAll(sel)); } catch (e) { return []; } }
  function text(el) { el = el || document.body; if (!el) return ''; return (el.innerText || el.textContent || '').replace(/\s+/g, ' ').trim(); }
  function label(el) { let t = ''; try { if (el.id) { const l = document.querySelector('label[for="' + CSS.escape(el.id) + '"]'); if (l) t += ' ' + l.innerText; } const p = el.closest('label'); if (p) t += ' ' + p.innerText; } catch (e) {} return (el.name || '') + ' ' + (el.id || '') + ' ' + (el.placeholder || '') + ' ' + (el.getAttribute('aria-label') || '') + ' ' + (el.className || '') + ' ' + t; }
  function setVal(el, v) { const proto = el instanceof HTMLTextAreaElement ? HTMLTextAreaElement.prototype : (el instanceof HTMLSelectElement ? HTMLSelectElement.prototype : HTMLInputElement.prototype); const d = Object.getOwnPropertyDescriptor(proto, 'value'); try { el.focus(); } catch (e) {} if (d && d.set) d.set.call(el, v); else el.value = v; for (const ev of ['input', 'change', 'keyup', 'blur']) el.dispatchEvent(new Event(ev, { bubbles: true })); }
  function classify(el) { const k = label(el).toLowerCase(); const type = (el.type || '').toLowerCase(); if (type === 'email' || /e-?mail/.test(k)) return 'email'; if (type === 'tel' || /phone|mobile|whatsapp|contact.?(no|number)|cell/.test(k)) return 'phone'; if (el.tagName === 'TEXTAREA' || /message|comment|enquiry|inquiry|query|description|details|requirement|question|remarks|note/.test(k)) return 'message'; if (/first.?name|fname/.test(k)) return 'first_name'; if (/last.?name|lname|surname/.test(k)) return 'last_name'; if (/name/.test(k)) return 'name'; if (/subject|title/.test(k)) return 'subject'; if (/company|organi[sz]ation|business|firm/.test(k)) return 'company'; if (/city|town|location|address|state|country|pincode|zip|postal/.test(k)) return 'city'; if (/website|url/.test(k)) return 'website'; if (/captcha|security.?code|verification|human/.test(k)) return 'captcha'; if (type === 'number' || /qty|quantity|budget|age|amount/.test(k)) return 'number'; if (type === 'date' || /date/.test(k)) return 'date'; return 'text'; }
  function fill(form, values, overrides) { const filled = [], skipped = []; overrides = overrides || {}; const els = Array.from(form.elements || form.querySelectorAll('input,textarea,select'));
    for (const key in overrides) { let el = null; try { if (/^[#.\[]/.test(key)) el = q(key, form); else el = form.querySelector('[name="' + key.replace(/"/g, '\\"') + '"]') || document.getElementById(key); } catch (e) {} if (el) { if (el.type === 'checkbox' || el.type === 'radio') { const want = !['0', 'false', 'no', ''].includes(String(overrides[key]).toLowerCase()); if (el.checked !== want) el.click(); if (el.checked !== want) { el.checked = want; el.dispatchEvent(new Event('change', { bubbles: true })); } } else setVal(el, overrides[key]); filled.push((el.name || key) + '=' + String(overrides[key]).slice(0, 30)); el.dataset.crmFilled = '1'; } }
    const radios = {};
    for (const el of els) { if (el.dataset.crmFilled) continue; const type = (el.type || '').toLowerCase(); const tag = el.tagName; if (['hidden', 'submit', 'button', 'reset', 'image', 'file'].includes(type)) continue; if (el.disabled || el.readOnly) continue;
      if (!visible(el) && type !== 'checkbox' && type !== 'radio') { skipped.push(el.name || el.id || tag.toLowerCase()); continue; }
      if (type === 'checkbox') { const k = label(el).toLowerCase(); if (el.required || /agree|accept|consent|terms|privacy|policy|gdpr|subscribe|confirm/.test(k)) { if (!el.checked) { el.click(); if (!el.checked) { el.checked = true; el.dispatchEvent(new Event('change', { bubbles: true })); } filled.push((el.name || 'checkbox') + '=on'); } } continue; }
      if (type === 'radio') { if (!el.name || radios[el.name]) continue; const group = els.filter(e => e.type === 'radio' && e.name === el.name); radios[el.name] = 1; if (group.some(e => e.checked)) continue; const first = group.find(e => visible(e)) || group[0]; if (first) { first.click(); if (!first.checked) { first.checked = true; first.dispatchEvent(new Event('change', { bubbles: true })); } filled.push(el.name + '=' + first.value); } continue; }
      if (tag === 'SELECT') { if (el.value && el.value.trim() !== '' && el.selectedIndex > 0) continue; const opts = Array.from(el.options); const opt = opts.find(o => o.value.trim() !== '' && !o.disabled && !/select|choose|--/.test(o.text.toLowerCase())) || opts.find(o => o.value.trim() !== '' && !o.disabled); if (opt) { setVal(el, opt.value); filled.push((el.name || 'select') + '=' + opt.value.slice(0, 20)); } continue; }
      if (el.value && el.value.trim() !== '' && !el.required) continue;
      const cls = classify(el); let v = null;
      switch (cls) { case 'email': v = values.email; break; case 'phone': v = values.phone; break; case 'message': v = values.message; break; case 'first_name': v = values.first_name; break; case 'last_name': v = values.last_name; break; case 'name': v = values.name; break; case 'subject': v = values.subject; break; case 'company': v = values.company; break; case 'city': v = values.city; break; case 'website': v = values.website; break; case 'number': v = '1'; break; case 'date': v = values.date; break; case 'captcha': skipped.push('captcha:' + (el.name || el.id)); continue; default: v = type === 'password' ? values.password : (el.required || !el.value ? values.text : null); }
      if (v === null || v === undefined) continue; v = String(v); if (el.maxLength > 0 && v.length > el.maxLength) v = v.slice(0, el.maxLength); setVal(el, v); filled.push((el.name || el.id || cls) + '=' + v.slice(0, 30)); }
    for (const k in overrides) { if (!Object.prototype.hasOwnProperty.call(overrides, k) || (form.elements && form.elements[k])) continue; const h = document.createElement('input'); h.type = 'hidden'; h.name = k; h.value = String(overrides[k]); form.appendChild(h); filled.push(k + '=' + String(overrides[k]).slice(0, 30) + ' (extra)'); }
    return { filled: filled, skipped: skipped }; }
  function findSubmit(form, sel) { if (sel) { return q(sel, form) || q(sel, document); } const cands = Array.from(form.querySelectorAll('button[type=submit],input[type=submit],button:not([type]),input[type=image],button.submit,.submit-btn,[role=button].submit,button')).filter(visible); if (cands.length) return cands[0]; if (form.id) { try { const ext = q('button[form="' + CSS.escape(form.id) + '"],input[type=submit][form="' + CSS.escape(form.id) + '"]'); if (ext) return ext; } catch (e) {} } return null; }
  function pickForm() { const forms = Array.from(document.querySelectorAll('form')).filter(f => visible(f) && !f.querySelector('input[type=password],input[type=search]') && !(f.getAttribute('role') === 'search')); let best = null, bestScore = -1; for (const f of forms) { const inputs = f.querySelectorAll('input:not([type=hidden]),textarea,select'); let s = inputs.length; if (f.querySelector('input[type=email]')) s += 20; if (f.querySelector('textarea')) s += 15; if (/newsletter|subscribe/i.test(f.className + ' ' + f.id)) s += 1; if (s > bestScore) { bestScore = s; best = f; } } return best; }
  const ERR = '.error, .errors, .invalid-feedback, .is-invalid, [aria-invalid="true"], .wpcf7-not-valid-tip, .wpcf7-validation-errors, .wpcf7-mail-sent-ng, .wpcf7-spam-blocked, .wpcf7-aborted, .wpforms-error, .wpforms-error-container, .gfield_error, .validation_error, .validation_message, .elementor-message-danger, .elementor-field-warning, .nf-error, .nf-error-msg, .frm_error, .frm_error_style, .ff-errors-in-stack, .error-message, .alert-danger, .form-error, .parsley-error, .help-block.error, .hs-error-msg, .field-error, .forminator-error-message, .wpcf7-response-output';
  const OK = '.wpcf7-mail-sent-ok, .wpcf7-response-output, .wpforms-confirmation-container, .wpforms-confirmation-container-full, .gform_confirmation_message, .elementor-message-success, .nf-response-msg, .frm_message, .ff-message-success, .forminator-response-message, .alert-success, .success-message, .form-success, .thank-you, .thankyou, .success, .text-success, [role=alert], [role=status], .swal2-popup, .toast, .notification, .message';
  function errors(scope) { const out = []; for (const el of qAll(ERR, scope || document)) { if (!visible(el)) continue; const t = text(el); if (t && t.length < 400 && !out.includes(t)) out.push(t); } return out.slice(0, 6); }
  function messages(scope) { const out = []; for (const el of qAll(OK, scope || document)) { if (!visible(el)) continue; const t = text(el); if (t && t.length < 600 && !out.includes(t)) out.push(t); } return out.slice(0, 6); }
  function captcha(scope) { return !!((scope || document).querySelector('.g-recaptcha, .h-captcha, .cf-turnstile, iframe[src*="recaptcha"], iframe[src*="hcaptcha"], iframe[src*="turnstile"], [data-sitekey]')); }
  function captchaInfo(form) {
    const scope = form || document;
    const has = (sel, root) => { try { return !!(root || document).querySelector(sel); } catch (e) { return false; } };
    const html = (scope.outerHTML || '').toLowerCase();
    if (has('.h-captcha, [name="h-captcha-response"], iframe[src*="hcaptcha"]', scope)) return { type: 'hcaptcha', inForm: true, label: 'hCaptcha', detail: 'hCaptcha widget inside the form' };
    if (has('.cf-turnstile, [name="cf-turnstile-response"], iframe[src*="turnstile"]', scope)) return { type: 'turnstile', inForm: true, label: 'Cloudflare Turnstile', detail: 'Cloudflare Turnstile widget inside the form' };
    if (has('.g-recaptcha, [name="g-recaptcha-response"], iframe[src*="recaptcha"], [data-sitekey], .wpcf7-recaptcha, .wpforms-recaptcha-container, .ginput_recaptcha, .elementor-g-recaptcha, .frm-g-recaptcha', scope)) {
      const widget = has('.g-recaptcha:not([data-size="invisible"]), iframe[src*="recaptcha/api2/anchor"]', scope) && !has('iframe[src*="recaptcha/api2/anchor"][src*="size=invisible"]', scope);
      return { type: widget ? 'recaptcha_v2' : 'recaptcha_v3', inForm: true, label: 'Google reCAPTCHA ' + (widget ? 'v2' : 'v3'), detail: 'Google reCAPTCHA ' + (widget ? 'v2 widget' : 'v3 (invisible score check)') + ' protects this form' };
    }
    for (const el of Array.from(scope.querySelectorAll('input:not([type=hidden]):not([type=submit]):not([type=checkbox]):not([type=radio]), textarea'))) {
      const k = ((el.name || '') + ' ' + (el.id || '') + ' ' + (el.placeholder || '') + ' ' + label(el)).toLowerCase();
      if (/captcha|security.?code|verification.?code|are you human|human.?check|anti.?spam|math.?(question|answer)|cptch_|si_captcha/.test(k)) return { type: 'captcha_field', inForm: true, label: 'CAPTCHA field', detail: 'The form contains a CAPTCHA / verification field ("' + (el.name || el.id) + '") that a human must fill in' };
    }
    if (/ct_checkjs|cleantalk|wp-armour|antispam-bee|cf7-antispam/.test(html)) return { type: 'antibot', inForm: true, label: 'Anti-bot plugin', detail: 'An anti-bot / anti-spam plugin protects this form and may silently reject automated submissions' };
    if (typeof window.grecaptcha !== 'undefined' || has('script[src*="recaptcha"]')) return { type: 'recaptcha_v3', inForm: false, label: 'Google reCAPTCHA v3', detail: 'A reCAPTCHA script is loaded on the page (not inside the form) – the submission is attempted and the response decides' };
    if (typeof window.hcaptcha !== 'undefined') return { type: 'hcaptcha', inForm: false, label: 'hCaptcha', detail: 'hCaptcha script loaded on the page' };
    if (typeof window.turnstile !== 'undefined') return { type: 'turnstile', inForm: false, label: 'Cloudflare Turnstile', detail: 'Turnstile script loaded on the page' };
    return { type: null, inForm: false, label: '', detail: '' };
  }
  const WIDGETS = [['tawk.to', 'Tawk.to chat'], ['crisp', 'Crisp chat'], ['intercom', 'Intercom'], ['drift', 'Drift'], ['tidio', 'Tidio chat'], ['zendesk', 'Zendesk widget'], ['zopim', 'Zendesk chat'], ['hubspot', 'HubSpot chat'], ['freshchat', 'Freshchat'], ['livechat', 'LiveChat'], ['smartsupp', 'Smartsupp'], ['jivo', 'JivoChat'], ['olark', 'Olark'], ['kommunicate', 'Kommunicate'], ['botpress', 'Botpress'], ['manychat', 'ManyChat'], ['chatra', 'Chatra'], ['whatsapp', 'WhatsApp widget'], ['wa.me', 'WhatsApp widget'], ['elfsight', 'Elfsight widget'], ['landbot', 'Landbot'], ['collect.chat', 'Collect.chat'], ['chatbot', 'Chatbot widget'], ['chaport', 'Chaport'], ['userlike', 'Userlike'], ['cookieyes', 'Cookie banner'], ['cookiebot', 'Cookie banner'], ['onesignal', 'OneSignal prompt']];
  function widgetName(el) {
    let cur = el;
    for (let i = 0; i < 6 && cur && cur !== document.body; i++, cur = cur.parentElement) {
      const sig = ((cur.id || '') + ' ' + String(cur.className || '') + ' ' + (cur.getAttribute && (cur.getAttribute('src') || cur.getAttribute('title') || '') || '')).toLowerCase();
      for (const w of WIDGETS) if (sig.indexOf(w[0]) > -1) return w[1];
      if (/chat|messenger|bot-|chatbot|widget-launcher|cookie|consent/.test(sig) && cur.tagName === 'IFRAME') return 'third-party iframe';
    }
    return null;
  }
  function widgets() {
    const found = [];
    for (const el of Array.from(document.querySelectorAll('iframe[src], script[src]'))) { const s = (el.getAttribute('src') || '').toLowerCase(); for (const w of WIDGETS) if (s.indexOf(w[0]) > -1 && !found.includes(w[1])) found.push(w[1]); }
    for (const el of Array.from(document.querySelectorAll('[id*="chat" i], [class*="chat-widget" i], [id*="tawk" i], [class*="crisp" i], [id*="intercom" i], [id*="tidio" i], [class*="drift" i]'))) { const n = widgetName(el); if (n && !found.includes(n)) found.push(n); }
    return found.slice(0, 6);
  }
  function occluder(btn) {
    if (!btn || !(btn instanceof Element)) return null;
    try { btn.scrollIntoView({ block: 'center', inline: 'center' }); } catch (e) {}
    const r = btn.getBoundingClientRect(); if (!r.width || !r.height) return null;
    const top = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2);
    if (!top || top === btn || btn.contains(top) || top.contains(btn)) return null;
    const form = btn.closest('form'); if (form && form.contains(top)) return null;
    return { widget: widgetName(top) || 'overlay', tag: top.tagName.toLowerCase(), id: top.id || '', cls: String(top.className || '').slice(0, 60) };
  }
  function click(el) { try { el.scrollIntoView({ block: 'center', inline: 'center' }); } catch (e) {} const r = el.getBoundingClientRect(); const x = r.left + r.width / 2, y = r.top + r.height / 2; for (const t of ['pointerdown', 'mousedown', 'pointerup', 'mouseup']) { try { el.dispatchEvent(new MouseEvent(t, { bubbles: true, cancelable: true, clientX: x, clientY: y, view: window })); } catch (e) {} } el.click(); return true; }
  return { visible, q, qAll, text, fill, findSubmit, pickForm, errors, messages, captcha, captchaInfo, widgets, occluder, click, classify };
})();
JS;

    private static function runBrowser(Browser $b, array $form, array $website, string $token): array
    {
        $isPopup = ($form['form_kind'] ?? '') === 'popup';
        $r = self::blank($token, $isPopup ? 'popup' : 'submission');
        $r['engine'] = 'browser';
        $timeout = max(10, (int) setting('check_timeout', 15) + 10);
        $wait = max(5, min(90, (int) setting('form_wait_seconds', 20)));
        $values = self::values($form, $token);
        $overrides = self::overrides($form, $values);
        $jsSel = fn(?string $s) => json_encode((string) $s);

        $b->reset();
        $b->setExtraHeaders(['X-CRM-Form-Test' => '1']);
        try { $b->send('Page.addScriptToEvaluateOnNewDocument', ['source' => self::JS_HELPERS]); } catch (Throwable $e) {}

        // 1) Open the page
        $t0 = microtime(true);
        $nav = $b->navigate($form['page_url'], $timeout);
        $r['response_ms'] = (int) round((microtime(true) - $t0) * 1000);
        $r['http_code'] = $nav['status'] ?: null;
        if (!$nav['loaded'] && !$nav['status']) {
            $r['reason'] = self::REASON_PAGE;
            $r['error'] = $nav['error'] ?: 'The page did not load within ' . $timeout . ' seconds.';
            $r['steps'][] = self::step('page', false, 'Could not open ' . $form['page_url'] . ': ' . $r['error']);
            return $r;
        }
        if ($nav['status'] >= 400) {
            $r['reason'] = 'Form page returned HTTP ' . $nav['status'];
            $r['error'] = 'The page ' . $form['page_url'] . ' responded with HTTP ' . $nav['status'] . ' ' . Monitor::httpText($nav['status']);
            $r['steps'][] = self::step('page', false, $r['error']);
            return $r;
        }
        $r['steps'][] = self::step('page', true, 'Page opened in the browser (HTTP ' . $nav['status'] . ', ' . $r['response_ms'] . ' ms)' . (!$nav['loaded'] ? ' – load event not fired, continuing' : ''));
        $b->pump(1200);
        $b->evaluate(self::JS_HELPERS . '; true');
        $widgets = [];
        try { $widgets = $b->evaluate('window.__crm.widgets()') ?: []; } catch (Throwable $e) {}
        if ($widgets) $r['steps'][] = self::step('widgets', null, 'Third-party widgets on the page: ' . implode(', ', $widgets) . ' – ignored unless one of them blocks the form');

        // 2) Popup
        if ($isPopup) {
            $trigger = trim((string) ($form['popup_trigger'] ?? ''));
            $popupSel = trim((string) ($form['popup_selector'] ?? ''));
            if ($trigger !== '') {
                $has = $b->evaluate('!!window.__crm.q(' . $jsSel($trigger) . ')');
                if (!$has) {
                    $r['reason'] = 'Popup trigger not found';
                    $r['error'] = 'No element matching the popup trigger "' . $trigger . '" was found on ' . $form['page_url'] . '.';
                    $r['steps'][] = self::step('popup', false, $r['error']);
                    $r['screenshot'] = $b->screenshot();
                    return $r;
                }
                $b->evaluate('window.__crm.click(window.__crm.q(' . $jsSel($trigger) . '))');
                $r['steps'][] = self::step('popup', true, 'Clicked popup trigger ' . $trigger);
            } else {
                $r['steps'][] = self::step('popup', null, 'No popup trigger configured – waiting for the popup to open by itself');
            }
            $cond = $popupSel !== '' ? 'window.__crm.visible(window.__crm.q(' . $jsSel($popupSel) . '))'
                : (!empty($form['form_selector']) ? 'window.__crm.visible(window.__crm.q(' . $jsSel($form['form_selector']) . '))' : '(function(){const f=window.__crm.pickForm();return !!f;})()');
            $opened = $b->waitFor($cond, ($trigger !== '' ? 10 : 20) * 1000);
            if (!$opened) {
                $r['reason'] = 'Popup did not open';
                $r['error'] = ($trigger !== '' ? 'The popup trigger was clicked but ' : 'Waited 20 seconds but ') . ($popupSel !== '' ? 'the popup "' . $popupSel . '" never became visible.' : 'no form became visible.');
                $r['steps'][] = self::step('popup', false, $r['error']);
                $r['screenshot'] = $b->screenshot();
                return $r;
            }
            $r['steps'][] = self::step('popup', true, 'Popup opened' . ($popupSel !== '' ? ' (' . $popupSel . ')' : ''));
            $b->pump(600);
        }

        // 3) Find the form
        $formSel = trim((string) ($form['form_selector'] ?? ''));
        $popupSel = trim((string) ($form['popup_selector'] ?? ''));
        $findJs = 'window.__crmForm = (function(){ var root = ' . ($popupSel !== '' ? 'window.__crm.q(' . $jsSel($popupSel) . ') || document' : 'document') . ';'
            . ($formSel !== '' ? ' var el = window.__crm.q(' . $jsSel($formSel) . ', root) || window.__crm.q(' . $jsSel($formSel) . '); if (el && el.tagName !== "FORM") { el = el.querySelector("form") || el.closest("form") || el; } return el;'
                : ' if (root !== document) { var f = Array.from(root.querySelectorAll("form")).find(window.__crm.visible) || root.querySelector("form"); if (f) return f; } return window.__crm.pickForm();')
            . ' })(); window.__crmForm ? { tag: window.__crmForm.tagName, id: window.__crmForm.id, cls: String(window.__crmForm.className), visible: window.__crm.visible(window.__crmForm), fields: (window.__crmForm.elements ? window.__crmForm.elements.length : window.__crmForm.querySelectorAll("input,textarea,select").length), captcha: window.__crm.captchaInfo(window.__crmForm), html: window.__crmForm.outerHTML.slice(0, 3000) } : null';
        $info = $b->evaluate($findJs);
        if (!$info) {
            $r['reason'] = $formSel !== '' ? 'Form selector not found' : 'Form not found on page';
            $r['error'] = $formSel !== '' ? 'No element matching "' . $formSel . '" exists on the page' . ($isPopup ? ' after opening the popup' : '') . '.' : 'No visible form was found on the page' . ($isPopup ? ' inside the popup' : '') . '.';
            $r['steps'][] = self::step('form', false, $r['error']);
            $r['screenshot'] = $b->screenshot();
            return $r;
        }
        if (!$info['visible']) {
            $r['reason'] = 'Form is not visible';
            $r['error'] = 'The form ' . ($formSel ?: ('#' . $info['id'])) . ' exists but is hidden (display:none / zero size), so a visitor could not use it.';
            $r['steps'][] = self::step('form', false, $r['error']);
            $r['screenshot'] = $b->screenshot();
            return $r;
        }
        $plugin = self::detectPlugin(strtolower(($info['cls'] ?? '') . ' ' . ($info['id'] ?? '')), (string) ($info['html'] ?? ''), [], '');
        $r['wp_plugin'] = $plugin;
        $r['steps'][] = self::step('form', true, 'Form found (' . ($formSel ?: ($info['id'] ? '#' . $info['id'] : 'auto-detected')) . ')' . ($plugin ? ' · ' . self::pluginLabel($plugin) : '') . ' · ' . (int) $info['fields'] . ' element(s)');
        $ci = is_array($info['captcha'] ?? null) ? $info['captcha'] : ['type' => null];
        $det = ['type' => $ci['type'] ?? null, 'in_form' => !empty($ci['inForm']), 'label' => (string) ($ci['label'] ?? ''), 'detail' => (string) ($ci['detail'] ?? '')];
        if (self::captchaGate($form, $det, $r)) { $r['screenshot'] = $b->screenshot(); return $r; }

        // 4) Fill
        $fillRes = $b->evaluate('window.__crm.fill(window.__crmForm, ' . json_encode($values) . ', ' . json_encode($overrides ?: new stdClass()) . ')');
        $filled = $fillRes['filled'] ?? [];
        $r['steps'][] = self::step('fill', true, 'Filled: ' . implode(', ', array_slice($filled, 0, 12)) . (count($filled) > 12 ? ' +' . (count($filled) - 12) : '') . (!empty($fillRes['skipped']) ? ' · skipped: ' . implode(', ', array_slice($fillRes['skipped'], 0, 6)) : ''));
        $b->pump(300);

        // 5) Submit
        $submitSel = trim((string) ($form['submit_selector'] ?? ''));
        $btn = $b->evaluate('(function(){ var b = window.__crm.findSubmit(window.__crmForm, ' . $jsSel($submitSel) . '); if (!b) return null; window.__crmSubmit = b; return (b.innerText || b.value || b.tagName).trim().slice(0, 60); })()');
        if ($btn === null && $submitSel !== '') {
            $r['reason'] = 'Submit button not found';
            $r['error'] = 'No element matching the submit button selector "' . $submitSel . '" was found in the form.';
            $r['steps'][] = self::step('submit', false, $r['error']);
            $r['screenshot'] = $b->screenshot();
            return $r;
        }
        $before = $b->evaluate('({ url: location.href, text: window.__crm.text(), errors: window.__crm.errors(), messages: window.__crm.messages(), popup: ' . ($popupSel !== '' ? 'window.__crm.visible(window.__crm.q(' . $jsSel($popupSel) . '))' : 'null') . ' })');
        if ($btn !== null) {
            // Is a chat widget / cookie banner / overlay covering the submit button? (recorded, and the button is still activated)
            try { $occ = $b->evaluate('window.__crm.occluder(window.__crmSubmit)'); } catch (Throwable $e) { $occ = null; }
            if (is_array($occ) && !empty($occ['widget'])) {
                $r['interference'] = mb_substr($occ['widget'] . ' (<' . $occ['tag'] . ($occ['id'] ? ' id="' . $occ['id'] . '"' : '') . ($occ['cls'] ? ' class="' . $occ['cls'] . '"' : '') . '>)', 0, 190);
                $r['steps'][] = self::step('widget', null, 'The submit button is covered by ' . $r['interference'] . ' at 1366×900 – a visitor may not be able to click it. The button is activated directly to test the form handler.');
            }
        }
        $b->drain();
        $clickAt = microtime(true);
        if ($btn !== null) {
            $b->evaluate('window.__crm.click(window.__crmSubmit)');
            $r['steps'][] = self::step('submit', true, 'Clicked submit button "' . $btn . '"');
        } else {
            $b->evaluate('(function(){ var f = window.__crmForm; if (f.requestSubmit) f.requestSubmit(); else f.submit(); return true; })()');
            $r['steps'][] = self::step('submit', null, 'No submit button found – submitted the form directly');
        }

        // 6) Observe: AJAX responses, navigation, DOM messages
        $expectText = trim((string) ($form['success_match'] ?? ''));
        $expectRedirect = trim((string) ($form['expect_redirect'] ?? ''));
        $requests = [];
        $jsErrors = [];
        $navigated = false;
        $loadedAfter = false;
        $state = null;
        $firstSignal = null;
        $deadline = microtime(true) + $wait;
        $timedOut = true;
        while (microtime(true) < $deadline) {
            $b->pump(500);
            foreach ($b->drain() as $e) {
                $p = $e['params'];
                if ($e['method'] === 'Network.requestWillBeSent' && $e['at'] >= $clickAt - 0.2) {
                    $type = $p['type'] ?? '';
                    $method = $p['request']['method'] ?? 'GET';
                    if (in_array($type, ['XHR', 'Fetch', 'Document'], true) && ($method !== 'GET' || $type === 'Document' || str_contains($p['request']['url'] ?? '', 'admin-ajax') || str_contains($p['request']['url'] ?? '', 'wp-json'))) {
                        $requests[$p['requestId']] = ['url' => $p['request']['url'] ?? '', 'method' => $method, 'type' => $type, 'status' => 0, 'body' => null, 'done' => false, 'failed' => null];
                    }
                }
                if ($e['method'] === 'Network.responseReceived' && isset($requests[$p['requestId']])) {
                    $requests[$p['requestId']]['status'] = (int) ($p['response']['status'] ?? 0);
                    $requests[$p['requestId']]['mime'] = $p['response']['mimeType'] ?? '';
                }
                if ($e['method'] === 'Network.loadingFinished' && isset($requests[$p['requestId']])) {
                    $requests[$p['requestId']]['done'] = true;
                    if ($firstSignal === null) $firstSignal = microtime(true);
                }
                if ($e['method'] === 'Network.loadingFailed' && isset($requests[$p['requestId']])) {
                    $requests[$p['requestId']]['failed'] = $p['errorText'] ?? 'failed';
                    $requests[$p['requestId']]['done'] = true;
                    if ($firstSignal === null) $firstSignal = microtime(true);
                }
                if ($e['method'] === 'Page.frameNavigated' && empty($p['frame']['parentId'])) { $navigated = true; if ($firstSignal === null) $firstSignal = microtime(true); }
                if ($e['method'] === 'Page.loadEventFired' && $e['at'] > $clickAt) { $loadedAfter = true; }
                if ($e['method'] === 'Runtime.exceptionThrown' && $e['at'] >= $clickAt) {
                    $d = $p['exceptionDetails'] ?? [];
                    $desc = $d['exception']['description'] ?? $d['text'] ?? 'JavaScript error';
                    $src = $d['url'] ?? ($d['scriptId'] ?? '');
                    $jsErrors[] = ['text' => mb_substr(strtok($desc, "\n") ?: $desc, 0, 200), 'url' => is_string($src) ? $src : '', 'third_party' => is_string($src) && $src !== '' && self::ignoredUrl($src)];
                }
            }
            try {
                $state = $b->evaluate('({ url: location.href, text: window.__crm ? window.__crm.text() : (document.body ? document.body.innerText : ""), errors: window.__crm ? window.__crm.errors() : [], messages: window.__crm ? window.__crm.messages() : [], formVisible: !!(window.__crmForm && document.contains(window.__crmForm) && window.__crm.visible(window.__crmForm)), popup: ' . ($popupSel !== '' ? '(window.__crm ? window.__crm.visible(window.__crm.q(' . $jsSel($popupSel) . ')) : null)' : 'null') . ' })', 5);
            } catch (Throwable $e) {
                $state = null; // navigating
                continue;
            }
            $newErrors = array_values(array_diff($state['errors'] ?? [], $before['errors'] ?? []));
            $newMsgs = array_values(array_diff($state['messages'] ?? [], $before['messages'] ?? []));
            $done = false;
            if ($expectText !== '' && (stripos($state['text'] ?? '', $expectText) !== false)) $done = true;
            if ($expectRedirect !== '' && stripos($state['url'] ?? '', $expectRedirect) !== false && $loadedAfter) $done = true;
            if ($navigated && $loadedAfter && $expectRedirect === '') $done = true;
            $pending = array_filter($requests, fn($q) => !$q['done']);
            if (!$pending && $requests && ($newErrors || $newMsgs) && $firstSignal && microtime(true) - $firstSignal > 1.5) $done = true;
            if ($newErrors && $firstSignal === null) { $firstSignal = microtime(true); }
            if ($newErrors && microtime(true) - $firstSignal > 2.5 && !$pending) $done = true;
            if (!$pending && $requests && $firstSignal && microtime(true) - $firstSignal > 4) $done = true;
            if ($popupSel !== '' && $before['popup'] && $state['popup'] === false && microtime(true) - $clickAt > 1.5) $done = true;
            if ($done) { $timedOut = false; break; }
        }
        if ($state === null) {
            $b->pump(1500);
            try { $state = $b->evaluate('({ url: location.href, text: document.body ? document.body.innerText : "", errors: window.__crm ? window.__crm.errors() : [], messages: window.__crm ? window.__crm.messages() : [], formVisible: false, popup: null })', 5); } catch (Throwable $e) { $state = ['url' => $form['page_url'], 'text' => '', 'errors' => [], 'messages' => [], 'formVisible' => true, 'popup' => null]; }
        }
        // Response bodies of the submission requests
        $responses = [];
        foreach ($requests as $id => $q) {
            if (self::ignoredUrl($q['url'])) continue;
            $body = $q['done'] && !$q['failed'] ? ($b->responseBody($id) ?? '') : '';
            $responses[] = ['url' => $q['url'], 'status' => $q['failed'] ? 0 : $q['status'], 'type' => $q['type'], 'method' => $q['method'], 'body' => $body, 'json' => self::jsonOf($body), 'failed' => $q['failed']];
        }
        usort($responses, fn($a, $b2) => ($b2['method'] === 'POST') <=> ($a['method'] === 'POST'));
        $r['final_url'] = $state['url'] ?? null;
        $mainReq = $responses[0] ?? null;
        $r['ajax_status'] = $mainReq && $mainReq['type'] !== 'Document' ? ($mainReq['status'] ?: null) : null;
        if ($mainReq && $mainReq['type'] === 'Document' && $mainReq['status']) $r['http_code'] = $mainReq['status'];
        $r['steps'][] = self::step('observe', null, ($responses ? count($responses) . ' request(s): ' . implode(', ', array_map(fn($q) => $q['method'] . ' ' . truncate(preg_replace('~^https?://[^/]+~', '', $q['url']), 40) . ' → ' . ($q['failed'] ? 'FAILED ' . $q['failed'] : 'HTTP ' . $q['status']), array_slice($responses, 0, 3))) : 'no submission request observed') . ($navigated ? ' · navigated to ' . truncate($state['url'] ?? '', 60) : '') . (($state['errors'] ?? []) ? ' · errors: ' . truncate(implode(' | ', array_diff($state['errors'], $before['errors'] ?? [])), 120) : '') . (($state['messages'] ?? []) ? ' · messages: ' . truncate(implode(' | ', array_diff($state['messages'], $before['messages'] ?? [])), 120) : ''));

        $failedReq = array_filter($responses, fn($q) => $q['failed'] && $q['method'] === 'POST');
        if ($failedReq && !$expectText && !$expectRedirect) {
            $f = array_values($failedReq)[0];
            $r['reason'] = 'AJAX request failed';
            $r['error'] = 'The submission request to ' . $f['url'] . ' failed in the browser: ' . $f['failed'];
            $r['steps'][] = self::step('response', false, $r['error']);
            $r['screenshot'] = $b->screenshot();
            return $r;
        }
        $ctx = [
            'expect_text' => $expectText, 'expect_redirect' => $expectRedirect, 'expect_response' => trim((string) ($form['expect_response'] ?? '')), 'expect_code' => (int) ($form['expect_http_code'] ?? 0),
            'final_url' => $state['url'] ?? '', 'navigated' => $navigated && rtrim((string) ($state['url'] ?? ''), '/') !== rtrim((string) ($before['url'] ?? ''), '/'), 'timed_out' => $timedOut, 'wait' => $wait,
            'responses' => $responses, 'messages' => array_values(array_diff($state['messages'] ?? [], $before['messages'] ?? [])), 'errors' => $state['errors'] ?? [], 'errors_before' => $before['errors'] ?? [],
            'body_text' => $state['text'] ?? '', 'body_before' => $before['text'] ?? '', 'form_hidden' => !($state['formVisible'] ?? true) && !$navigated,
            'popup_closed' => $popupSel !== '' && !empty($before['popup']) && ($state['popup'] ?? null) === false, 'captcha' => !empty($det['type']), 'ajax' => true, 'plugin' => $plugin, 'browser' => true,
        ];
        [$ok, $reason, $error, $excerpt] = self::judge($ctx);
        // Distinguish "nothing happened" causes: the site's own JavaScript crashed vs. a third-party widget swallowed the click
        if (!$ok && in_array($reason, ['No response after submit', 'Form submission timed out'], true) && !$responses && !$navigated) {
            $own = array_values(array_filter($jsErrors, fn($j) => !$j['third_party']));
            $third = array_values(array_filter($jsErrors, fn($j) => $j['third_party']));
            if ($own) {
                $reason = 'JavaScript error on submit';
                $error = 'The page\'s JavaScript threw an error when the form was submitted, so no request was sent: ' . truncate($own[0]['text'], 200) . ($own[0]['url'] ? ' (' . truncate($own[0]['url'], 80) . ')' : '') . (count($own) > 1 ? ' +' . (count($own) - 1) . ' more' : '');
                $r['steps'][] = self::step('js', false, $error);
            } elseif ($r['interference']) {
                $r['outcome'] = 'interference';
                $reason = 'Third-party widget blocked the submission';
                $error = 'No request, redirect or message followed the submit click, and the submit button is covered by ' . $r['interference'] . '. The form itself could not be verified – this is not reported as a form failure. Action: check the widget placement on the page, or set a submit button selector.';
                $r['steps'][] = self::step('widget', null, $error);
            } elseif ($third) {
                $r['steps'][] = self::step('js', null, 'Third-party script errors ignored: ' . truncate($third[0]['text'], 120));
            }
        } elseif ($jsErrors) {
            $r['steps'][] = self::step('js', null, count($jsErrors) . ' JavaScript error(s) during submission did not stop the form: ' . truncate($jsErrors[0]['text'], 120));
        }
        $r['success'] = $ok;
        $r['reason'] = $reason;
        $r['error'] = $error;
        $r['excerpt'] = $excerpt ?: truncate(implode(' | ', $ctx['messages']) ?: ($state['text'] ?? ''), 300);
        $r['steps'][] = self::step('response', $ok, $ok ? ($excerpt ?: 'accepted') : $reason . ' – ' . $error);
        if (!$ok) $r['screenshot'] = $b->screenshot();
        return $r;
    }

    /* =====================================================================
     * Email verification (IMAP)
     * ===================================================================== */

    public static function verifyEmail(string $token): string
    {
        if (!function_exists('imap_open') || !setting('imap_host') || !setting('imap_username')) return 'unknown';
        $wait = (int) setting('imap_wait_seconds', 20);
        if ($wait > 0) sleep(min($wait, 60));
        try {
            $enc = setting('imap_encryption', 'ssl');
            $mbox = '{' . setting('imap_host') . ':' . (int) setting('imap_port', 993) . '/imap' . ($enc === 'ssl' ? '/ssl' : ($enc === 'tls' ? '/tls' : '/notls')) . '}INBOX';
            $imap = @imap_open($mbox, setting('imap_username'), decrypt_value(setting('imap_password', '')) ?? '', 0, 1);
            if (!$imap) return 'unknown';
            $found = imap_search($imap, 'TEXT "' . $token . '" SINCE "' . date('d-M-Y') . '"');
            if ($found) imap_setflag_full($imap, implode(',', $found), '\\Seen');
            imap_close($imap);
            return $found ? 'yes' : 'no';
        } catch (Throwable $e) {
            return 'unknown';
        }
    }

    /** Store a failure screenshot under uploads/form-tests and return its relative path. */
    public static function saveScreenshot(?string $jpeg, int $formId): ?string
    {
        if (!$jpeg) return null;
        $dir = UPLOAD_PATH . '/form-tests';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        if (!is_file($dir . '/index.html')) @file_put_contents($dir . '/index.html', '');
        $name = 'form-' . $formId . '-' . date('Ymd-His') . '-' . substr(bin2hex(random_bytes(3)), 0, 6) . '.jpg';
        return @file_put_contents($dir . '/' . $name, $jpeg) ? 'form-tests/' . $name : null;
    }
}
