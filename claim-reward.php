<?php
/**
 * claim-reward.php — minimal reference implementation of the
 * "anonymity-preserving reward redirect" pattern.
 *
 * THE PROBLEM THIS SOLVES
 * ------------------------
 * You're running an anonymous survey (LimeSurvey, Qualtrics, REDCap,
 * SurveyMonkey, Typeform...) and want to pay/reward each participant who
 * finishes it — but the moment you ask for an email address *inside* the
 * survey, it stops being anonymous.
 *
 * THE FIX
 * -------
 * Point your survey's own "End URL" / "redirect on completion" setting at
 * this script, passing along a per-response identifier that every one of
 * these platforms already generates for you:
 *
 *   LimeSurvey:    .../claim-reward.php?rid={TOKEN}&token=YOUR_SECRET
 *   Qualtrics:     .../claim-reward.php?rid=${e://Field/ResponseID}&token=YOUR_SECRET
 *   REDCap:        .../claim-reward.php?rid=[record_id]&token=YOUR_SECRET
 *   SurveyMonkey:  .../claim-reward.php?rid={{RespondentID}}&token=YOUR_SECRET
 *   Typeform:      .../claim-reward.php?rid={{response_id}}&token=YOUR_SECRET
 *
 * This script NEVER sees your survey's actual answers — only an opaque ID
 * that identifies "a completion happened," which is exactly what keeps the
 * two systems unlinked. See README.md for the full write-up.
 *
 * WHAT THIS IS (AND ISN'T)
 * -------------------------
 * This is a bare-bones, single-file reference you can read top to bottom
 * in five minutes — not a production system. No database, no queue, no
 * admin panel, no multi-platform auto-detection, no fraud/rate-limiting
 * beyond the one idempotency check below. Good enough for a few hundred
 * responses on a single study; past that, or if you want this handled for
 * you, see the last line of README.md.
 */

declare(strict_types=1);

// ---- Configuration -----------------------------------------------------
$codesFile   = __DIR__ . '/codes.csv';     // one unused reward code per line
$claimsFile  = __DIR__ . '/claims.json';   // rid => [code, email, claimed_at]
$sharedToken = 'change-me-to-something-long-and-random'; // see README

// ---- Helpers -------------------------------------------------------------
function load_claims(string $path): array {
    if (!file_exists($path)) return [];
    $raw = file_get_contents($path);
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function save_claims(string $path, array $claims): void {
    file_put_contents($path, json_encode($claims, JSON_PRETTY_PRINT), LOCK_EX);
}

/**
 * Pops the next unused code from the pool file and rewrites it without
 * that line — crude compared to a real database, but file locking (LOCK_EX)
 * is enough to stay correct under the request volume a single study
 * generates. Returns null if the pool is empty.
 */
function take_next_code(string $path): ?string {
    $fp = fopen($path, 'c+');
    if (!$fp || !flock($fp, LOCK_EX)) return null;

    $lines = [];
    while (($line = fgets($fp)) !== false) {
        $line = trim($line);
        if ($line !== '') $lines[] = $line;
    }
    if (empty($lines)) {
        flock($fp, LOCK_UN);
        fclose($fp);
        return null;
    }

    $code = array_shift($lines);
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, implode("\n", $lines) . (count($lines) ? "\n" : ''));
    flock($fp, LOCK_UN);
    fclose($fp);
    return $code;
}

// ---- Request handling ----------------------------------------------------
$rid = trim($_GET['rid'] ?? $_POST['rid'] ?? '');
$token = trim($_GET['token'] ?? $_POST['token'] ?? '');

if ($rid === '') {
    http_response_code(400);
    echo "Missing response identifier. This page is meant to be reached via your survey's own End URL redirect — see README.md.";
    exit;
}

// Without this, anyone who guesses/enumerates rid values could claim
// rewards for responses that weren't theirs. Put the same value in your
// survey's redirect URL (?token=...) and here — see README.md.
if (!hash_equals($sharedToken, $token)) {
    http_response_code(403);
    echo "Invalid or missing token.";
    exit;
}

$claims = load_claims($claimsFile);

// Idempotency: the same response finishing twice (a resubmit, a double
// click, a browser back-button) must return the SAME code, never mint a
// second one. This one check is most of what "prevents reward fraud" means
// in practice — see README.md for why.
if (isset($claims[$rid])) {
    $existing = $claims[$rid];
    render_result($existing['code'], alreadyClaimed: true);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        render_form($rid, $token, error: 'Please enter a valid email address.');
        exit;
    }

    $code = take_next_code($codesFile);
    if ($code === null) {
        http_response_code(503);
        echo "Sorry — we've run out of reward codes. Please contact the research team.";
        exit;
    }

    $claims[$rid] = ['code' => $code, 'email' => $email, 'claimed_at' => gmdate('c')];
    save_claims($claimsFile, $claims);

    // Extend here: send $code to $email with mail()/PHPMailer instead of
    // (or in addition to) just displaying it — kept out of this reference
    // to avoid tying it to one mail setup.
    render_result($code, alreadyClaimed: false);
    exit;
}

render_form($rid, $token);

// ---- Tiny inline views -----------------------------------------------------
function render_form(string $rid, string $token, ?string $error = null): void {
    ?><!DOCTYPE html><html><head><meta charset="utf-8"><title>Claim your reward</title>
    <style>body{font-family:system-ui,sans-serif;max-width:28rem;margin:4rem auto;padding:0 1.5rem}
    input{width:100%;padding:.6rem;margin:.5rem 0 1rem;box-sizing:border-box}
    button{padding:.6rem 1.2rem}.err{color:#a23b3b}</style></head><body>
    <h1>Thanks for completing the survey</h1>
    <p>Enter your email to receive your reward. This form is not linked to your survey answers.</p>
    <?php if ($error): ?><p class="err"><?= htmlspecialchars($error) ?></p><?php endif; ?>
    <form method="post">
      <input type="hidden" name="rid" value="<?= htmlspecialchars($rid) ?>">
      <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
      <label>Email<input type="email" name="email" required autofocus></label>
      <button type="submit">Send my reward</button>
    </form>
    </body></html><?php
}

function render_result(string $code, bool $alreadyClaimed): void {
    ?><!DOCTYPE html><html><head><meta charset="utf-8"><title>Your reward</title>
    <style>body{font-family:system-ui,sans-serif;max-width:28rem;margin:4rem auto;padding:0 1.5rem}
    code{font-size:1.3rem;background:#f0f0f0;padding:.4rem .8rem;border-radius:4px;display:inline-block}</style>
    </head><body>
    <h1><?= $alreadyClaimed ? 'You already claimed this' : 'Here is your reward' ?></h1>
    <p>Your code: <code><?= htmlspecialchars($code) ?></code></p>
    <?php if ($alreadyClaimed): ?><p>This response already received a reward — showing you the same code rather than issuing a new one.</p><?php endif; ?>
    </body></html><?php
}
