<?php
declare(strict_types=1);

/** Shared email chrome. Callers supply trusted/escaped body HTML, never raw form HTML. */
function email_design(string $title,string $body,array $options=[]): string {
    $name=trim((string)($options['name']??''));
    $greeting=$name!==''?'Hello '.mb_substr($name,0,120).',':'Hello,';
    $site=(string)($options['site_url']??url('/'));
    $preheader=(string)($options['preheader']??$title);
    $links='';foreach(social_links() as $label=>$href)$links.='<a href="'.e($href).'" style="color:#18584e;display:inline-block;margin:4px 12px 4px 0;text-decoration:underline">'.e($label).'</a> ';
    $unsubscribe=$options['unsubscribe']??null;$preferences=$options['preferences']??null;
    $footer=$options['footer']??'<p>This is a service email about your request. It does not subscribe you to newsletter editions.</p><p>For newsletter emails, <a href="'.e(url('/newsletter/preferences/request')).'">manage your subscription or unsubscribe</a>.</p>';
    if($unsubscribe!==null){$footer='<p>You receive these emails because you confirmed your subscription to My Thoughts.</p><p><a href="'.e($unsubscribe).'">Unsubscribe</a>'.($preferences?' · <a href="'.e($preferences).'">Email preferences</a>':'').'</p>';}
    $postal=trim((string)($options['postal_address']??app_config('mail.postal_address','')));
    return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.e($title).'</title></head><body data-email-design="waithaka-v1" style="margin:0;padding:0;background:#f3f1eb;color:#243b36;font-family:Arial,Helvetica,sans-serif;line-height:1.7"><div style="display:none;max-height:0;overflow:hidden;mso-hide:all">'.e($preheader).'</div><table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f3f1eb"><tr><td align="center" style="padding:24px 12px"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:600px;background:#ffffff;border:1px solid #dedfd5;border-radius:16px;overflow:hidden"><tr><td style="background:#153f36;padding:30px 28px;border-bottom:4px solid #be955f"><a href="'.e($site).'" style="color:#fff;text-decoration:none;font-family:Georgia,serif;font-size:27px">Waithaka Ndegwa</a><p style="color:#e4d7bf;margin:6px 0 0;font-size:12px;letter-spacing:2px">MY THOUGHTS · IDEAS INTO IMPACT</p></td></tr><tr><td style="padding:28px;font-size:16px"><p style="margin:0 0 20px">'.e($greeting).'</p><h1 style="font-family:Georgia,serif;font-size:30px;line-height:1.25;font-weight:normal;color:#153f36;margin:0 0 20px">'.e($title).'</h1>'.$body.'</td></tr><tr><td style="padding:24px 28px;background:#f8f7f2;border-top:1px solid #e1e3da"><p style="font-family:Georgia,serif;font-size:22px;color:#153f36;margin:0 0 6px">Waithaka Ndegwa</p><p style="font-size:13px;line-height:2;margin:0 0 16px">'.$links.'</p><div style="font-size:12px;color:#52655e">'.$footer.'<p><a href="'.e(url('/contact')).'">Contact</a> · <a href="'.e(url('/privacy')).'">Privacy</a> · <a href="'.e($site).'">Visit website</a></p>'.($postal!==''?'<p>'.nl2br(e($postal)).'</p>':'').'</div></td></tr></table><p style="font-size:12px;color:#52655e">Practical ideas. Honest learning. Lasting value.</p></td></tr></table></body></html>';
}
function email_design_button(string $label,string $href): string {
    return '<p style="margin:26px 0"><a href="'.e($href).'" style="display:inline-block;background:#18584e;color:#fff;border:12px solid #18584e;border-left-width:22px;border-right-width:22px;border-radius:6px;text-decoration:none;font-weight:bold">'.e($label).'</a></p>';
}
function email_design_text(string $html): string {
    $html=preg_replace('~<div style="display:none[^>]*>.*?</div>~s','',$html)??$html;
    $html=preg_replace_callback('~<a\b[^>]*href="([^"]+)"[^>]*>(.*?)</a>~is',static fn($m)=>strip_tags($m[2]).' ('.html_entity_decode($m[1],ENT_QUOTES|ENT_HTML5,'UTF-8').')',$html)??$html;
    return trim(html_entity_decode(strip_tags(str_replace(['</p>','</h1>','</h2>','</tr>','<br>','<br />'],"\n",$html)),ENT_QUOTES|ENT_HTML5,'UTF-8'));
}
