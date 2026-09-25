<?php
/**
 * Minimal headless-browser driver (Chrome DevTools Protocol over WebSocket) with no external dependencies.
 *
 * Used by FormTester to test popup / AJAX / JavaScript forms exactly like a visitor would: open the page,
 * click the popup trigger, wait for the popup, fill the form, click submit, watch the AJAX / navigation
 * result and read the success or error message from the live DOM.
 *
 * Requirements: Google Chrome, Chromium or Microsoft Edge on the server (Settings → Monitoring → Browser engine
 * shows what was detected). Without a browser the HTTP engine is used instead.
 */
class Browser
{
    const UA_SUFFIX = ' OutlineMediaCRM-Monitor/1.6 (form-test)';

    /** @var resource|null */
    private $proc = null;
    /** @var resource|null */
    private $sock = null;
    private int $msgId = 0;
    private array $events = [];
    private string $profileDir = '';
    private int $port = 0;
    private string $targetId = '';
    private ?string $sessionUa = null;
    private static ?self $shared = null;
    private static ?string $lastError = null;

    /* =====================================================================
     * Detection
     * ===================================================================== */

    /** Path of the browser binary (setting override or auto-detected). */
    public static function binary(): ?string
    {
        $configured = trim((string) setting('browser_path', ''));
        if ($configured !== '') return is_file($configured) ? $configured : null;
        return Cache::remember('browser:binary', 600, function () {
            foreach (self::candidates() as $c) {
                if (str_contains($c, DIRECTORY_SEPARATOR) || str_contains($c, '/')) {
                    if (is_file($c)) return $c;
                } else {
                    foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $dir) {
                        $dir = rtrim($dir, '/\\');
                        if ($dir === '') continue;
                        foreach (DIRECTORY_SEPARATOR === '\\' ? [$c . '.exe', $c] : [$c] as $name) {
                            if (is_file($dir . DIRECTORY_SEPARATOR . $name)) return $dir . DIRECTORY_SEPARATOR . $name;
                        }
                    }
                }
            }
            return '';
        }) ?: null;
    }

    private static function candidates(): array
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $pf = getenv('ProgramFiles') ?: 'C:\\Program Files';
            $pf86 = getenv('ProgramFiles(x86)') ?: 'C:\\Program Files (x86)';
            $local = getenv('LOCALAPPDATA') ?: '';
            return array_filter([
                $pf . '\\Google\\Chrome\\Application\\chrome.exe',
                $pf86 . '\\Google\\Chrome\\Application\\chrome.exe',
                $local ? $local . '\\Google\\Chrome\\Application\\chrome.exe' : null,
                $pf . '\\Chromium\\Application\\chrome.exe',
                $pf86 . '\\Microsoft\\Edge\\Application\\msedge.exe',
                $pf . '\\Microsoft\\Edge\\Application\\msedge.exe',
                'chrome', 'msedge', 'chromium',
            ]);
        }
        return [
            'google-chrome', 'google-chrome-stable', 'chromium', 'chromium-browser', 'chrome', 'chrome-headless-shell', 'headless_shell',
            '/usr/bin/google-chrome', '/usr/bin/google-chrome-stable', '/usr/bin/chromium', '/usr/bin/chromium-browser', '/snap/bin/chromium',
            '/opt/google/chrome/chrome', '/opt/google/chrome/google-chrome', '/usr/lib/chromium/chromium', '/usr/lib/chromium-browser/chromium-browser',
            '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', '/Applications/Chromium.app/Contents/MacOS/Chromium',
        ];
    }

    public static function enabled(): bool
    {
        return (bool) setting('browser_enabled', 1) && function_exists('proc_open');
    }

    public static function available(): bool
    {
        return self::enabled() && self::binary() !== null;
    }

    /** Human readable status for the settings page. */
    public static function status(): array
    {
        if (!setting('browser_enabled', 1)) return ['available' => false, 'binary' => self::binary(), 'message' => 'Browser engine disabled in settings.'];
        if (!function_exists('proc_open')) return ['available' => false, 'binary' => null, 'message' => 'proc_open() is disabled in PHP – the browser engine cannot start a process.'];
        $bin = self::binary();
        if (!$bin) return ['available' => false, 'binary' => null, 'message' => 'No Chrome / Chromium / Edge binary found. Install Google Chrome or set the path in Settings → Monitoring.'];
        return ['available' => true, 'binary' => $bin, 'message' => 'Browser engine ready: ' . $bin];
    }

    public static function lastError(): ?string
    {
        return self::$lastError;
    }

    /* =====================================================================
     * Lifecycle
     * ===================================================================== */

    /** One shared browser per PHP process (cron run) – each test gets a fresh, isolated page. */
    public static function shared(): ?self
    {
        if (self::$shared && self::$shared->alive()) return self::$shared;
        self::$shared = null;
        if (!self::available()) return null;
        try {
            self::$shared = self::launch();
        } catch (Throwable $e) {
            self::$lastError = $e->getMessage();
            app_log('warning', 'Browser launch failed: ' . $e->getMessage());
            return null;
        }
        return self::$shared;
    }

    public static function shutdownShared(): void
    {
        if (self::$shared) { self::$shared->close(); self::$shared = null; }
    }

    public static function launch(int $startTimeout = 20): self
    {
        $bin = self::binary();
        if (!$bin) throw new RuntimeException('No browser binary available');
        $b = new self();
        $base = LOG_PATH . '/browser';
        if (!is_dir($base)) @mkdir($base, 0755, true);
        $b->profileDir = $base . '/profile-' . getmypid() . '-' . bin2hex(random_bytes(3));
        @mkdir($b->profileDir, 0755, true);
        $args = [
            $bin, '--headless=new', '--disable-gpu', '--no-sandbox', '--disable-dev-shm-usage', '--disable-extensions', '--no-first-run', '--no-default-browser-check',
            '--disable-background-networking', '--disable-sync', '--disable-translate', '--mute-audio', '--hide-scrollbars', '--disable-features=Translate,OptimizationHints',
            '--window-size=1366,900', '--remote-debugging-port=0', '--remote-allow-origins=*', '--user-data-dir=' . $b->profileDir, 'about:blank',
        ];
        $log = $base . '/chrome.log';
        $spec = [0 => ['file', DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']];
        $b->proc = @proc_open($args, $spec, $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($b->proc)) throw new RuntimeException('Could not start the browser process (' . $bin . ')');

        // Chrome writes the chosen debugging port to DevToolsActivePort inside the profile directory
        $portFile = $b->profileDir . '/DevToolsActivePort';
        $deadline = microtime(true) + $startTimeout;
        while (microtime(true) < $deadline) {
            if (is_file($portFile)) {
                $lines = @file($portFile, FILE_IGNORE_NEW_LINES);
                if ($lines && (int) $lines[0] > 0) { $b->port = (int) $lines[0]; break; }
            }
            $st = proc_get_status($b->proc);
            if (!$st['running']) { $b->cleanup(); throw new RuntimeException('The browser exited immediately (exit code ' . $st['exitcode'] . '). See logs/browser/chrome.log'); }
            usleep(150000);
        }
        if (!$b->port) { $b->close(); throw new RuntimeException('The browser did not open its DevTools port within ' . $startTimeout . ' s'); }

        // Open a page target and attach to it
        $target = $b->httpJson('PUT', '/json/new?about:blank') ?: $b->httpJson('GET', '/json/new?about:blank');
        if (!$target || empty($target['webSocketDebuggerUrl'])) {
            $list = $b->httpJson('GET', '/json/list') ?: [];
            foreach ($list as $t) if (($t['type'] ?? '') === 'page' && !empty($t['webSocketDebuggerUrl'])) { $target = $t; break; }
        }
        if (!$target || empty($target['webSocketDebuggerUrl'])) { $b->close(); throw new RuntimeException('Could not create a browser page target'); }
        $b->targetId = $target['id'] ?? '';
        $b->connect($target['webSocketDebuggerUrl']);
        $b->send('Page.enable');
        $b->send('Runtime.enable');
        $b->send('Network.enable', ['maxResourceBufferSize' => 5 * 1024 * 1024, 'maxTotalBufferSize' => 20 * 1024 * 1024]);
        $ua = $b->evaluate('navigator.userAgent');
        $b->sessionUa = (is_string($ua) ? preg_replace('~HeadlessChrome~', 'Chrome', $ua) : 'Mozilla/5.0') . self::UA_SUFFIX;
        $b->send('Network.setUserAgentOverride', ['userAgent' => $b->sessionUa, 'acceptLanguage' => 'en-US,en;q=0.9']);
        $b->send('Emulation.setDeviceMetricsOverride', ['width' => 1366, 'height' => 900, 'deviceScaleFactor' => 1, 'mobile' => false]);
        return $b;
    }

    public function alive(): bool
    {
        if (!is_resource($this->proc) || !is_resource($this->sock)) return false;
        $st = proc_get_status($this->proc);
        return (bool) $st['running'];
    }

    public function close(): void
    {
        if (is_resource($this->sock)) {
            try { $this->send('Browser.close', [], 3); } catch (Throwable $e) {}
            @fclose($this->sock);
            $this->sock = null;
        }
        if (is_resource($this->proc)) {
            $deadline = microtime(true) + 3;
            while (microtime(true) < $deadline && proc_get_status($this->proc)['running']) usleep(100000);
            $st = proc_get_status($this->proc);
            if ($st['running']) {
                if (DIRECTORY_SEPARATOR === '\\') { @exec('taskkill /PID ' . (int) $st['pid'] . ' /T /F >NUL 2>&1'); }
                else { @proc_terminate($this->proc, 9); }
            }
            @proc_close($this->proc);
            $this->proc = null;
        }
        $this->cleanup();
    }

    private function cleanup(): void
    {
        if ($this->profileDir && is_dir($this->profileDir)) {
            for ($i = 0; $i < 3; $i++) {
                if (self::rrmdir($this->profileDir)) break;
                usleep(400000);
            }
        }
    }

    public static function rrmdir(string $dir): bool
    {
        if (!is_dir($dir)) return true;
        $items = @scandir($dir) ?: [];
        foreach ($items as $it) {
            if ($it === '.' || $it === '..') continue;
            $p = $dir . '/' . $it;
            if (is_dir($p) && !is_link($p)) self::rrmdir($p); else @unlink($p);
        }
        return @rmdir($dir);
    }

    /** Remove profile directories left behind by crashed runs (called by housekeeping). */
    public static function gc(): int
    {
        $base = LOG_PATH . '/browser';
        if (!is_dir($base)) return 0;
        $n = 0;
        foreach (glob($base . '/profile-*') ?: [] as $d) {
            if (is_dir($d) && filemtime($d) < time() - 3600 && self::rrmdir($d)) $n++;
        }
        if (is_file($base . '/chrome.log') && filesize($base . '/chrome.log') > 2 * 1024 * 1024) @file_put_contents($base . '/chrome.log', '');
        return $n;
    }

    public function __destruct()
    {
        try { $this->close(); } catch (Throwable $e) {}
    }

    /* =====================================================================
     * HTTP (DevTools discovery endpoint)
     * ===================================================================== */

    private function httpJson(string $method, string $path): ?array
    {
        $ctx = stream_context_create(['http' => ['method' => $method, 'timeout' => 5, 'ignore_errors' => true, 'header' => "Connection: close\r\n"]]);
        $raw = @file_get_contents('http://127.0.0.1:' . $this->port . $path, false, $ctx);
        if ($raw === false || $raw === '') return null;
        $j = json_decode($raw, true);
        return is_array($j) ? $j : null;
    }

    /* =====================================================================
     * WebSocket transport
     * ===================================================================== */

    private function connect(string $wsUrl): void
    {
        $u = parse_url($wsUrl);
        $host = $u['host'] ?? '127.0.0.1';
        $port = (int) ($u['port'] ?? $this->port);
        $path = ($u['path'] ?? '/') . (isset($u['query']) ? '?' . $u['query'] : '');
        $sock = @stream_socket_client('tcp://' . $host . ':' . $port, $errno, $errstr, 5);
        if (!$sock) throw new RuntimeException('DevTools websocket connection failed: ' . $errstr);
        stream_set_timeout($sock, 10);
        $key = base64_encode(random_bytes(16));
        $req = "GET $path HTTP/1.1\r\nHost: $host:$port\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: $key\r\nSec-WebSocket-Version: 13\r\n\r\n";
        fwrite($sock, $req);
        $resp = '';
        while (!str_contains($resp, "\r\n\r\n")) {
            $chunk = fread($sock, 1024);
            if ($chunk === false || $chunk === '') break;
            $resp .= $chunk;
        }
        if (!preg_match('~^HTTP/1\.1 101~', $resp)) { @fclose($sock); throw new RuntimeException('DevTools websocket handshake failed: ' . trim(strtok($resp, "\r\n") ?: 'no response')); }
        $this->sock = $sock;
    }

    private function wsSend(string $payload): void
    {
        $len = strlen($payload);
        $head = chr(0x81);
        if ($len < 126) $head .= chr($len | 0x80);
        elseif ($len < 65536) $head .= chr(126 | 0x80) . pack('n', $len);
        else $head .= chr(127 | 0x80) . pack('J', $len);
        $mask = random_bytes(4);
        $masked = '';
        for ($i = 0; $i < $len; $i++) $masked .= $payload[$i] ^ $mask[$i % 4];
        $frame = $head . $mask . $masked;
        $written = 0;
        while ($written < strlen($frame)) {
            $n = @fwrite($this->sock, substr($frame, $written));
            if ($n === false || $n === 0) throw new RuntimeException('Browser connection lost while sending');
            $written += $n;
        }
    }

    private function readExact(int $n, float $deadline): string
    {
        $buf = '';
        while (strlen($buf) < $n) {
            if (microtime(true) > $deadline) throw new RuntimeException('Timed out waiting for the browser');
            stream_set_timeout($this->sock, 1);
            $chunk = @fread($this->sock, $n - strlen($buf));
            if ($chunk === false || $chunk === '') {
                // On Windows a timed-out socket read returns false (not '') – only a real error is a lost connection
                $meta = stream_get_meta_data($this->sock);
                if ($meta['eof']) throw new RuntimeException('Browser closed the connection');
                if ($chunk === false && empty($meta['timed_out'])) throw new RuntimeException('Browser connection lost');
                continue;
            }
            $buf .= $chunk;
        }
        return $buf;
    }

    /** Read one complete websocket message (handles continuation, ping, close). Returns null on timeout. */
    private function wsRead(float $deadline): ?string
    {
        $message = '';
        while (true) {
            if (microtime(true) > $deadline) return null;
            // Peek non-blocking for the first header byte so a deadline can be honoured without exceptions
            stream_set_timeout($this->sock, 0, 200000);
            $b1 = @fread($this->sock, 1);
            if ($b1 === false || $b1 === '') {
                // On Windows a timed-out socket read returns false (not '') – only a real error is a lost connection
                $meta = stream_get_meta_data($this->sock);
                if ($meta['eof']) throw new RuntimeException('Browser closed the connection');
                if ($b1 === false && empty($meta['timed_out'])) throw new RuntimeException('Browser connection lost');
                if ($message === '') return null;
                continue;
            }
            $b2 = $this->readExact(1, $deadline);
            $fin = (ord($b1) & 0x80) !== 0;
            $op = ord($b1) & 0x0F;
            $masked = (ord($b2) & 0x80) !== 0;
            $len = ord($b2) & 0x7F;
            if ($len === 126) $len = unpack('n', $this->readExact(2, $deadline))[1];
            elseif ($len === 127) $len = unpack('J', $this->readExact(8, $deadline))[1];
            $mask = $masked ? $this->readExact(4, $deadline) : '';
            $data = $len ? $this->readExact($len, $deadline) : '';
            if ($masked) { for ($i = 0; $i < $len; $i++) $data[$i] = $data[$i] ^ $mask[$i % 4]; }
            if ($op === 0x9) { $this->wsSendControl(0xA, $data); continue; }   // ping → pong
            if ($op === 0xA) continue;                                         // pong
            if ($op === 0x8) throw new RuntimeException('Browser closed the DevTools connection');
            $message .= $data;
            if ($fin) return $message;
        }
    }

    private function wsSendControl(int $op, string $payload): void
    {
        $mask = random_bytes(4);
        $masked = '';
        for ($i = 0; $i < strlen($payload); $i++) $masked .= $payload[$i] ^ $mask[$i % 4];
        @fwrite($this->sock, chr(0x80 | $op) . chr(strlen($payload) | 0x80) . $mask . $masked);
    }

    /* =====================================================================
     * CDP commands / events
     * ===================================================================== */

    /** Send a command and wait for its result. Throws on protocol error. */
    public function send(string $method, array $params = [], int $timeout = 20): array
    {
        if (!is_resource($this->sock)) throw new RuntimeException('Browser not connected');
        $id = ++$this->msgId;
        $this->wsSend(json_encode(['id' => $id, 'method' => $method, 'params' => $params ?: new stdClass()]));
        $deadline = microtime(true) + $timeout;
        while (true) {
            $raw = $this->wsRead($deadline);
            if ($raw === null) {
                // wsRead returns null after a short idle period as well as at the deadline – keep waiting until the deadline
                if (microtime(true) < $deadline) continue;
                throw new RuntimeException('Browser did not answer ' . $method . ' within ' . $timeout . ' s');
            }
            $msg = json_decode($raw, true);
            if (!is_array($msg)) continue;
            if (isset($msg['id']) && (int) $msg['id'] === $id) {
                if (isset($msg['error'])) throw new RuntimeException($method . ' failed: ' . ($msg['error']['message'] ?? 'unknown error'));
                return $msg['result'] ?? [];
            }
            if (isset($msg['method'])) $this->onEvent($msg);
        }
    }

    private function onEvent(array $msg): void
    {
        $m = $msg['method'];
        // Auto-dismiss alert()/confirm() dialogs so a test can never hang
        if ($m === 'Page.javascriptDialogOpening') {
            try { $this->wsSend(json_encode(['id' => ++$this->msgId, 'method' => 'Page.handleJavaScriptDialog', 'params' => ['accept' => true]])); } catch (Throwable $e) {}
        }
        if (count($this->events) < 4000) $this->events[] = ['method' => $m, 'params' => $msg['params'] ?? [], 'at' => microtime(true)];
    }

    /** Collect events for up to $ms milliseconds (or until $until returns true). */
    public function pump(int $ms, ?callable $until = null): void
    {
        $deadline = microtime(true) + $ms / 1000;
        while (microtime(true) < $deadline) {
            $raw = $this->wsRead(min($deadline, microtime(true) + 0.25));
            if ($raw !== null) {
                $msg = json_decode($raw, true);
                if (is_array($msg) && isset($msg['method'])) $this->onEvent($msg);
            }
            if ($until && $until()) return;
        }
    }

    /** Return and clear the events captured so far. */
    public function drain(): array
    {
        $e = $this->events;
        $this->events = [];
        return $e;
    }

    public function events(): array
    {
        return $this->events;
    }

    /** Evaluate a JS expression in the page and return its JSON value (promises are awaited). */
    public function evaluate(string $expression, int $timeout = 15)
    {
        $r = $this->send('Runtime.evaluate', ['expression' => $expression, 'returnByValue' => true, 'awaitPromise' => true, 'timeout' => $timeout * 1000], $timeout + 5);
        if (isset($r['exceptionDetails'])) {
            $d = $r['exceptionDetails'];
            $msg = $d['exception']['description'] ?? $d['text'] ?? 'JavaScript error';
            throw new RuntimeException('Page script error: ' . mb_substr(strtok($msg, "\n") ?: $msg, 0, 300));
        }
        return $r['result']['value'] ?? null;
    }

    /** Navigate and wait for the load event (or the timeout). Returns [loaded(bool), http status of the document, final url]. */
    public function navigate(string $url, int $timeout = 30): array
    {
        $this->events = [];
        $this->send('Page.setLifecycleEventsEnabled', ['enabled' => true]);
        $r = $this->send('Page.navigate', ['url' => $url], $timeout);
        if (!empty($r['errorText'])) return ['loaded' => false, 'status' => 0, 'url' => $url, 'error' => $r['errorText']];
        $frameId = $r['frameId'] ?? null;
        $loaded = false;
        $this->pump($timeout * 1000, function () use (&$loaded) {
            foreach ($this->events as $e) {
                if ($e['method'] === 'Page.loadEventFired') { $loaded = true; return true; }
            }
            return false;
        });
        $status = 0;
        $error = null;
        foreach ($this->events as $e) {
            if ($e['method'] === 'Network.responseReceived' && ($e['params']['type'] ?? '') === 'Document' && ($e['params']['frameId'] ?? null) === $frameId) {
                $status = (int) ($e['params']['response']['status'] ?? 0);
            }
            if ($e['method'] === 'Network.loadingFailed' && ($e['params']['type'] ?? '') === 'Document') $error = $e['params']['errorText'] ?? 'load failed';
        }
        $final = $this->evaluate('location.href', 5);
        return ['loaded' => $loaded, 'status' => $status, 'url' => is_string($final) ? $final : $url, 'error' => $error];
    }

    /** Poll a JS boolean expression until true or timeout. */
    public function waitFor(string $expression, int $ms, int $pollMs = 250): bool
    {
        $deadline = microtime(true) + $ms / 1000;
        do {
            try {
                if ($this->evaluate('!!(' . $expression . ')', 5)) return true;
            } catch (Throwable $e) {
                // evaluation can fail during navigation – keep polling
            }
            $this->pump($pollMs);
        } while (microtime(true) < $deadline);
        return false;
    }

    /** Body of a captured network response (text / JSON only, truncated). */
    public function responseBody(string $requestId, int $max = 4000): ?string
    {
        try {
            $r = $this->send('Network.getResponseBody', ['requestId' => $requestId], 5);
            $body = (string) ($r['body'] ?? '');
            if (!empty($r['base64Encoded'])) $body = base64_decode($body) ?: '';
            return mb_substr($body, 0, $max);
        } catch (Throwable $e) {
            return null;
        }
    }

    public function screenshot(): ?string
    {
        try {
            $r = $this->send('Page.captureScreenshot', ['format' => 'jpeg', 'quality' => 55], 15);
            return isset($r['data']) ? base64_decode($r['data']) : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /** Reset state between tests: cookies, storage, blank page. */
    public function reset(): void
    {
        try { $this->send('Network.clearBrowserCookies'); } catch (Throwable $e) {}
        try { $this->send('Network.clearBrowserCache'); } catch (Throwable $e) {}
        try { $this->send('Page.navigate', ['url' => 'about:blank'], 5); } catch (Throwable $e) {}
        $this->events = [];
    }

    public function setExtraHeaders(array $headers): void
    {
        $this->send('Network.setExtraHTTPHeaders', ['headers' => $headers ?: new stdClass()]);
    }

    public function userAgent(): ?string
    {
        return $this->sessionUa;
    }
}
