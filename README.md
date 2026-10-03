# survey-reward-redirect

A minimal, single-file reference implementation of the pattern researchers
use to **reward survey participants without breaking the survey's
anonymity** — the thing you reach for right after "no incentive" stops
being enough and before you've built (or bought) anything real.

No framework, no database, no dependencies. One PHP file you can read in
five minutes, built so you can either learn the pattern and write your own
version, or just drop this on any PHP hosting and go.

## The problem

The moment a survey needs to know *who* to pay, it stops being anonymous —
unless the reward request is kept structurally separate from the responses
themselves.

## The pattern

Split the flow into two parts that are never linked by a shared identifier:

1. **Participant completes the anonymous survey.** No name, no email, no
   identifying field anywhere in the response data.
2. **On submit, the survey redirects here** with an opaque per-response ID
   that every major survey platform already generates — not the survey
   data itself, just a token meaning "a completion happened."
3. **This script asks for an email, independently,** and sends/shows the
   reward. Because the two systems never share an identifier, there is no
   technical path from "who got paid" back to "what did they answer."

Every major platform supports the redirect this relies on, under its own name:

| Platform | Setting | Pass as |
|---|---|---|
| LimeSurvey | End URL | `?rid={TOKEN}&token=YOUR_SECRET` |
| Qualtrics | End of Survey → redirect | `?rid=${e://Field/ResponseID}&token=YOUR_SECRET` |
| REDCap | Survey Termination Options | `?rid=[record_id]&token=YOUR_SECRET` |
| SurveyMonkey | Custom redirect | `?rid={{RespondentID}}&token=YOUR_SECRET` |
| Typeform | Redirect on completion | `?rid={{response_id}}&token=YOUR_SECRET` |
| Castor EDC | End of survey URL | `?rid={record_id}&token=YOUR_SECRET` |

`YOUR_SECRET` is a long random string only you know — it stops someone
from guessing/enumerating `rid` values and claiming rewards that aren't
theirs. Set it in `$sharedToken` at the top of `claim-reward.php`.

## Setup

1. Upload `claim-reward.php`, `codes.csv` to any PHP host (no database
   required — `claims.json` is created automatically on first claim).
2. Open `claim-reward.php` and change `$sharedToken` to your own long
   random string.
3. Put your real reward codes in `codes.csv`, one per line.
4. Point your survey's End URL / redirect setting at
   `https://your-domain/claim-reward.php?rid={the token for your platform}&token=YOUR_SECRET`
   using the table above.
5. Done. Test it by finishing the survey yourself once.

## Why the idempotency check matters

The single biggest failure mode with DIY incentive scripts isn't the happy
path — it's what happens on a resubmit, a double-click, or a shared link.
If the same `rid` shows up twice, this script returns the **same** code it
already issued rather than minting a new one. That one check is most of
what "prevents duplicate/fraudulent claims" means in practice: it turns a
shared or replayed link into a no-op instead of a free second reward.

## What this is (and isn't)

This is deliberately bare — a teaching reference, not a production system.
Specifically missing, on purpose, to keep it readable:

- **No database** — a flat JSON file for claims and a CSV for the code
  pool. Fine for a single study with a few hundred responses; a real
  concurrency spike (a batch of participants finishing at once) can race
  on the file lock.
- **No rate limiting or bot detection.** A shared/leaked link can still be
  hammered quickly even though each hit only returns the one issued code.
- **No multi-study / multi-platform management** — one code pool, one
  study. Running several studies means copying this folder.
- **No email delivery built in** — the reward is shown on screen; wiring
  up `mail()` or a transactional-email API is left as an exercise (see
  the comment in `claim-reward.php`).
- **No admin dashboard** — checking how many codes are left means opening
  `codes.csv`.

If you outgrow this — multiple studies, need email delivery, want
duplicate/fraud protection and LimeSurvey/Qualtrics/REDCap/SurveyMonkey/
Castor/Typeform support without wiring up `rid` parameters by hand —
[REWARDR](https://ersan.eu/qr-sistem/) is a hosted version of this exact
pattern, built after running into this same problem managing incentives
for real studies. Priced per participant, not a subscription; first 5
rewards are free to try.

## License

MIT — do whatever you want with this, including shipping it as-is.
