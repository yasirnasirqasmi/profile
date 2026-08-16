<?php
/**
 * contact.php — project brief handler
 * Upload next to index.html on any PHP host (Hostinger, cPanel, etc.).
 *
 * SETUP: change the three values in $CONFIG below. Nothing else needs editing.
 */

declare(strict_types=1);

$CONFIG = [
    // Where briefs are delivered.
    'to'        => 'you@example.com',

    // Must be an address on YOUR domain, or the mail will be rejected as spoofed.
    // Create it in cPanel → Email Accounts, e.g. noreply@yourdomain.com
    'from'      => 'noreply@yourdomain.com',

    // Set to false to stop writing briefs to briefs.log (email only).
    'keep_log'  => true,
];

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function out(bool $ok, string $error = '', int $code = 200): never {
    http_response_code($code);
    echo json_encode($ok ? ['ok' => true] : ['ok' => false, 'error' => $error]);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    out(false, 'Use POST.', 405);
}

/* ---------- read JSON body, fall back to normal form post ---------- */
$raw  = file_get_contents('php://input') ?: '';
$data = json_decode($raw, true);
if (!is_array($data)) {
    $data = $_POST;
}

$get = static function (string $key) use ($data): string {
    $v = $data[$key] ?? '';
    return is_string($v) ? trim($v) : '';
};

/* ---------- honeypot: bots fill hidden fields, humans don't ---------- */
if ($get('website') !== '') {
    out(true); // pretend success so the bot moves on
}

/* ---------- basic rate limit: one brief per IP per 60 seconds ---------- */
$ip    = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$stamp = sys_get_temp_dir() . '/brief_' . md5($ip);
if (is_file($stamp) && (time() - filemtime($stamp)) < 60) {
    out(false, 'Please wait a minute before sending another brief.', 429);
}
@touch($stamp);

/* ---------- validate ---------- */
$name    = $get('name');
$email   = $get('email');
$type    = $get('project_type');
$message = $get('message');

if (mb_strlen($name) < 2)                                out(false, 'Name is too short.', 422);
if (!filter_var($email, FILTER_VALIDATE_EMAIL))          out(false, 'Email address is not valid.', 422);
if ($type === '')                                        out(false, 'Choose what you need built.', 422);
if (mb_strlen($message) < 20)                            out(false, 'Describe the project in a little more detail.', 422);
if (mb_strlen($message) > 4000)                          out(false, 'Description is too long.', 422);

/* header injection guard */
foreach ([$name, $email] as $field) {
    if (preg_match('/[\r\n]/', $field)) out(false, 'Invalid characters submitted.', 422);
}

$phone    = mb_substr($get('phone'), 0, 40);
$company  = mb_substr($get('company'), 0, 120);
$budget   = mb_substr($get('budget'), 0, 60) ?: 'Not stated';
$timeline = mb_substr($get('timeline'), 0, 60) ?: 'Flexible';
$source   = mb_substr($get('source'), 0, 60) ?: '—';
$page     = mb_substr($get('page'), 0, 300);

/* ---------- compose ---------- */
$when = gmdate('Y-m-d H:i') . ' UTC';

$body = <<<TXT
NEW PROJECT BRIEF
=================

Name        : {$name}
Email       : {$email}
Phone       : {$phone}
Company     : {$company}

Needs built : {$type}
Budget      : {$budget}
Timeline    : {$timeline}
Found via   : {$source}

DESCRIPTION
-----------
{$message}

--
Received  : {$when}
From page : {$page}
IP        : {$ip}
TXT;

$subject = '[Brief] ' . $type . ' — ' . $name;

$headers = implode("\r\n", [
    'From: Website Brief <' . $CONFIG['from'] . '>',
    'Reply-To: ' . $name . ' <' . $email . '>',
    'Content-Type: text/plain; charset=UTF-8',
    'MIME-Version: 1.0',
    'X-Mailer: PHP/' . PHP_VERSION,
]);

/* ---------- optional local log ---------- */
if ($CONFIG['keep_log']) {
    @file_put_contents(
        __DIR__ . '/briefs.log',
        $body . "\n\n" . str_repeat('=', 60) . "\n\n",
        FILE_APPEND | LOCK_EX
    );
}

/* ---------- send ---------- */
$sent = @mail($CONFIG['to'], $subject, $body, $headers, '-f' . $CONFIG['from']);

if (!$sent) {
    // The brief is still in briefs.log — the front end falls back to mailto.
    out(false, 'Mail server did not accept the message.', 500);
}

out(true);
