<?php
/** Shared wrapper for sequence emails. Each template file returns
 *  ['subject' => string, 'body' => html] via fab_tpl_wrap(). */
function fab_tpl_wrap(string $inner): string {
    return "<!DOCTYPE html><html><body style='font-family:Arial,sans-serif;line-height:1.6;color:#333'>
<div style='max-width:600px;margin:0 auto;padding:20px'>
<div style='background:#4A7C59;color:#fff;padding:24px;text-align:center'>
<h1 style='margin:0;font-size:22px'>FABRIOZA</h1>
<p style='margin:4px 0 0;font-size:13px'>Premium Private Label Clothing Manufacturer</p></div>
<div style='background:#fff;padding:28px 20px;border:1px solid #ddd'>$inner
<p>Best regards,<br><strong>The FABRIOZA Team</strong></p>
<p style='font-size:12px;color:#666'>USA Office: McDonough, Georgia (by appointment)<br>
Factory: Saro Street, near Fateh Garh Road, Sialkot 51310, Pakistan<br>Email: info@fabrioza.com</p></div>
<div style='text-align:center;padding:16px;color:#999;font-size:11px'>
&copy; 2026 FABRIOZA &middot; You received this because you contacted us via fabrioza.com.
Reply STOP and we will not follow up again.</div></div></body></html>";
}
