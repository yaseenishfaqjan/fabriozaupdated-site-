<?php
require_once __DIR__ . '/_shared.php';
return function (array $lead): array {
    $first = htmlspecialchars(explode(' ', $lead['name'])[0] ?: 'there', ENT_QUOTES);
    return [
        'subject' => 'Still planning your production run? Your FABRIOZA quote is ready when you are',
        'body' => fab_tpl_wrap("
<p>Hi $first,</p>
<p>A few days ago you asked us about custom manufacturing" . ($lead['product_type'] ? " (" . htmlspecialchars($lead['product_type'], ENT_QUOTES) . ")" : "") . " &mdash; just checking in.</p>
<p>If you are still comparing options, two things most brands find useful at this stage:</p>
<ul>
<li><strong>A free tech pack:</strong> send any sketch or reference photo and we build the production spec at no cost.</li>
<li><strong>A 20-piece trial order:</strong> test our real production quality before committing to bulk &mdash; the trial cost is credited to your bulk order. <a href='https://fabrioza.com/sample-order' style='color:#4A7C59'>How it works &rarr;</a></li>
</ul>
<p>Reply to this email with any question &mdash; a real person reads every reply within a few hours.</p>"),
    ];
};
