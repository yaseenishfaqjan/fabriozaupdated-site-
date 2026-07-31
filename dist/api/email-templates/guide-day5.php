<?php
require_once __DIR__ . '/_shared.php';
return function (array $lead): array {
    $first = htmlspecialchars(explode(' ', $lead['name'])[0] ?: 'there', ENT_QUOTES);
    return [
        'subject' => 'After the guide: the cheapest way to test a manufacturer',
        'body' => fab_tpl_wrap("
<p>Hi $first,</p>
<p>Hope the guide you downloaded was useful. The most common question we get after it: <em>&ldquo;how do I test a factory without risking a full order?&rdquo;</em></p>
<p>Our answer is the <strong>20-piece trial order</strong>: 20 finished garments of your own design, produced on the same line with the same 4-stage QC as a 5,000-piece run. You judge the quality in your hands; the trial cost is credited when you scale to bulk.</p>
<p style='text-align:center;margin:22px 0'><a href='https://fabrioza.com/sample-order' style='background:#4A7C59;color:#fff;padding:12px 26px;border-radius:6px;text-decoration:none;font-weight:bold'>See how trial orders work</a></p>
<p>Or simply reply with what you are planning to make &mdash; we quote within 24 hours.</p>"),
    ];
};
