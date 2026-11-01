<?php
/**
 * mail-health.php - one line of machine-readable mail status, for verify-live.sh.
 *
 * Exists because the alternative was embedding this query in a shell string
 * nested through ssh -> docker exec -> php -r -> SQL, where every layer eats
 * a level of quoting. The first attempt had to encode the word "sent" as
 * char(115)||char(101)||char(110)||char(116) to survive the trip, which is
 * unreadable, unlintable and exactly where the next bug would hide. A file
 * can be read, linted and tested.
 *
 * Prints:  <status-of-newest-row>|<sent in 48h>|<failed in 48h>
 * e.g.     sent|3|0
 *
 * CLI only - the same guard process-sequences.php uses. Nothing here is
 * secret, but a status endpoint nobody asked for is still an endpoint.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require __DIR__ . '/db.php';

$db = crm_db();

// Status of the most recent entry. Ordered by id as well as sent_at because
// several rows share a timestamp to the second - a digest sends to two
// recipients in the same tick - and without the tiebreak "newest" is a coin
// toss between a success and a failure from the same run.
$last = $db->query(
    'SELECT status FROM email_log ORDER BY sent_at DESC, id DESC LIMIT 1'
)->fetchColumn();

$sent = (int) $db->query(
    "SELECT COUNT(*) FROM email_log
      WHERE status = 'sent' AND sent_at >= datetime('now', '-48 hours')"
)->fetchColumn();

$failed = (int) $db->query(
    "SELECT COUNT(*) FROM email_log
      WHERE status <> 'sent' AND sent_at >= datetime('now', '-48 hours')"
)->fetchColumn();

echo ($last === false ? 'empty' : $last), '|', $sent, '|', $failed, "\n";
