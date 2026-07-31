<?php
require_once __DIR__ . '/_shared.php';
return function (array $lead): array {
    $first = htmlspecialchars(explode(' ', $lead['name'])[0] ?: 'there', ENT_QUOTES);
    return [
        'subject' => 'Your 20-piece trial order - shall we lock in the production slot?',
        'body' => fab_tpl_wrap("
<p>Hi $first,</p>
<p>You asked about a <strong>20-piece trial order</strong> two days ago. Trial slots run on the same production lines as bulk orders, so they get scheduled quickly &mdash; if you send your design this week we can typically dispatch your 20 pieces within 2&ndash;3 weeks.</p>
<p>To move forward, just reply with any of these:</p>
<ul>
<li>a sketch, reference photo, or tech pack,</li>
<li>your preferred fabric/GSM (or ask us to recommend),</li>
<li>colors and size split for the 20 pieces.</li>
</ul>
<p>You will have an exact trial quote within 24 hours &mdash; and remember, the trial cost is <strong>credited toward your bulk order</strong>.</p>"),
    ];
};
