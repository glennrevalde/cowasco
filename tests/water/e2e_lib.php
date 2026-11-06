<?php
declare(strict_types=1);

/**
 * A small HTTP "browser" and check recorder for the end-to-end tests of the water module (tests/water/e2e_http.php).
 * Each Browser keeps its own cookie jar (its own session), so two Browsers are two operators at two terminals.
 */
final class Browser
{
    public string $base;
    private string $jar;
    public string $name;
    public int $status = 0;
    public string $body = '';
    public string $url = '';
    public array $json = [];

    public function __construct(string $base, string $name)
    {
        $this->base = rtrim($base, '/');
        $this->name = $name;
        $this->jar = sys_get_temp_dir() . '/e2e_' . preg_replace('/\W/', '', $name) . '_' . getmypid() . '.txt';
        @unlink($this->jar);
    }

    public function __destruct()
    {
        @unlink($this->jar);
    }

    /** A prepared curl handle (used directly by Browser::parallel) */
    public function handle(string $method, string $path, $data = null, bool $json = false, array $headers = [])
    {
        $ch = curl_init();
        $url = str_starts_with($path, 'http') ? $path : $this->base . '/' . ltrim($path, '/');
        curl_setopt_array($ch, [
            CURLOPT_URL => $url, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 6,
            CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_TIMEOUT => 120,
            CURLOPT_HTTPHEADER => array_merge(['Accept: text/html,application/json'], $headers),
        ]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($json) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge(['Content-Type: application/json', 'Accept: application/json', 'X-Requested-With: XMLHttpRequest'], $headers));
            } else {
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query((array)$data));
            }
        }
        return $ch;
    }

    public function finish($ch, ?string $body): self
    {
        $this->body = (string)$body;
        $this->status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $this->url = str_replace($this->base, '', (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL));
        $d = json_decode($this->body, true);
        $this->json = is_array($d) ? $d : [];
        curl_close($ch);
        return $this;
    }

    public function get(string $path): self
    {
        $ch = $this->handle('GET', $path);
        return $this->finish($ch, curl_exec($ch));
    }

    public function post(string $path, array $form, array $headers = []): self
    {
        $ch = $this->handle('POST', $path, $form, false, $headers);
        return $this->finish($ch, curl_exec($ch));
    }

    public function postJson(string $path, array $data): self
    {
        $data['csrf_token'] ??= $this->csrf();
        $ch = $this->handle('POST', $path, $data, true, ['X-CSRF-Token: ' . $data['csrf_token']]);
        return $this->finish($ch, curl_exec($ch));
    }

    /** The session's CSRF token, read from a page of the system (the same token serves both sets of pages) */
    public function csrf(): string
    {
        static $cache = [];
        if (!empty($cache[$this->jar])) {
            return $cache[$this->jar];
        }
        $ch = $this->handle('GET', 'index.php?p=auth.change_password');
        $html = (string)curl_exec($ch);
        curl_close($ch);
        if (!preg_match('/name="_csrf" value="([0-9a-f]+)"/', $html, $m)) {
            throw new RuntimeException($this->name . ': no CSRF token on the change-password page (not signed in?)');
        }
        return $cache[$this->jar] = $m[1];
    }

    public function login(string $user, string $pass, ?string $newPass = null): bool
    {
        $this->get('login.php');
        if (!preg_match('/name="_csrf" value="([0-9a-f]+)"/', $this->body, $m)) {
            return false;
        }
        $this->post('login.php', ['_csrf' => $m[1], 'username' => $user, 'password' => $pass]);
        if (str_contains($this->url, 'auth.change_password') && $newPass !== null) {
            preg_match('/name="_csrf" value="([0-9a-f]+)"/', $this->body, $m);
            $this->post($this->url, ['_csrf' => $m[1], 'current_password' => $pass, 'new_password' => $newPass, 'confirm_password' => $newPass]);
        }
        return !str_contains($this->url, 'login.php') && !str_contains($this->url, 'change_password');
    }

    /** Runs several prepared requests at the same moment (two cashiers pressing Pay together) */
    public static function parallel(array $jobs): array
    {
        $mh = curl_multi_init();
        $handles = [];
        foreach ($jobs as $i => [$browser, $ch]) {
            curl_multi_add_handle($mh, $ch);
            $handles[$i] = [$browser, $ch];
        }
        do {
            $st = curl_multi_exec($mh, $running);
            if ($running) {
                curl_multi_select($mh, 1.0);
            }
        } while ($running && $st === CURLM_OK);
        $out = [];
        foreach ($handles as $i => [$browser, $ch]) {
            $body = curl_multi_getcontent($ch);
            curl_multi_remove_handle($mh, $ch);
            $b = clone $browser;
            $out[$i] = $b->finish($ch, $body);
        }
        curl_multi_close($mh);
        return $out;
    }
}

/** The PASS/FAIL matrix: section => [[check, expected, actual, result]] */
final class Checks
{
    public static array $rows = [];
    public static string $section = '';
    public static int $fail = 0;

    public static function section(string $name): void
    {
        self::$section = $name;
        echo "\n== $name\n";
    }

    /** Records one check; $ok defaults to expected == actual (amounts compared to the cent) */
    public static function eq(string $what, $expected, $actual, ?bool $ok = null): bool
    {
        $fmt = function ($v) {
            if (is_bool($v)) {
                return $v ? 'yes' : 'no';
            }
            if (is_float($v)) {
                return number_format($v, 2, '.', ',');
            }
            if (is_array($v)) {
                return json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
            return (string)$v;
        };
        if ($ok === null) {
            $ok = (is_float($expected) || is_float($actual)) ? abs((float)$expected - (float)$actual) < 0.005 : $fmt($expected) === $fmt($actual);
        }
        self::$rows[] = [self::$section, $what, mb_substr($fmt($expected), 0, 120), mb_substr($fmt($actual), 0, 160), $ok ? 'PASS' : 'FAIL'];
        if (!$ok) {
            self::$fail++;
        }
        printf("  %s  %s  (expected %s, actual %s)\n", $ok ? 'PASS' : 'FAIL', $what, mb_substr($fmt($expected), 0, 80), mb_substr($fmt($actual), 0, 120));
        return $ok;
    }

    public static function true(string $what, bool $cond, string $detail = ''): bool
    {
        return self::eq($what, 'yes', $cond ? 'yes' : ('no' . ($detail !== '' ? ': ' . $detail : '')), $cond);
    }

    public static function markdown(string $title, array $meta): string
    {
        $pass = count(self::$rows) - self::$fail;
        $s = "# $title\n\n";
        foreach ($meta as $k => $v) {
            $s .= "- **$k:** $v\n";
        }
        $s .= "- **Result:** $pass passed, " . self::$fail . " failed, " . count(self::$rows) . " checks\n\n";
        $last = null;
        foreach (self::$rows as [$sec, $what, $exp, $act, $res]) {
            if ($sec !== $last) {
                $s .= "\n## $sec\n\n| Check | Expected | Actual | Result |\n|---|---|---|---|\n";
                $last = $sec;
            }
            $esc = fn($v) => str_replace(['|', "\n"], ['\\|', ' '], (string)$v);
            $s .= '| ' . $esc($what) . ' | ' . $esc($exp) . ' | ' . $esc($act) . ' | ' . $res . " |\n";
        }
        return $s;
    }
}
