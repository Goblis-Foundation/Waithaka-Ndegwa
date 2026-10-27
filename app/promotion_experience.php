<?php
declare(strict_types=1);
/** Presentation only: never changes pricing, permissions, payments or publication. */
function promote_groups(): array {
    return [
        'Your business'=>[
            'organisation'=>['Business or organisation','text',180,true,'The business that will be featured.'],
            'title'=>['Working title for your feature','text',180,true,'A simple description is enough; the final headline is reviewed later.'],
            'summary'=>['Your offer in a few sentences','textarea',800,true,'What do you offer, and why should someone care?'],
            'description'=>['Tell us about the business or product','textarea',16000,true,'Give the context the editor needs to understand your story.'],
            'sectors'=>['Sector or industry','text',1000,false,'For example: agriculture, education or technology.'],
            'audience'=>['Who should this reach?','textarea',2000,false,'Describe your customers and the places you serve. This is not a promise of targeting or reach.'],
        ],
        'The offer'=>[
            'offer'=>['Product, service and price','textarea',5000,false,'Include the currency and any offer conditions.'],
            'benefits'=>['Benefits you can substantiate','textarea',5000,false,'Use facts you can support, not guaranteed results.'],
            'conditions'=>['Conditions or limitations','textarea',5000,false,'Availability, exclusions, eligibility or delivery limits.'],
            'purchase_url'=>['Where should readers buy or enquire?','url',2000,true,'Use your public HTTPS website or enquiry page, not a private or signed link.'],
            'cta_label'=>['Preferred call to action','text',80,false,'For example: Enquire with the provider.'],
            'offer_expires'=>['Offer expiry, where applicable','datetime-local',16,false,'Leave blank when there is no fixed expiry. This does not reserve a publishing date.'],
            'timezone'=>['Offer timezone','text',80,true,'Use an IANA timezone, such as Africa/Nairobi or Europe/London.'],
            'source_url'=>['Supporting public website','url',2000,false,'An official public HTTPS page that supports the brief.'],
            'claims_evidence'=>['Private evidence for your claims','textarea',5000,false,'Notes for editorial review. Not automatically published or sent to AI.'],
        ],
        'Contact and files'=>[
            'sponsor_name'=>['Public sponsor name','text',180,false,'Leave blank to use the business name when the final feature is prepared.'],
            'client_name'=>['Your name','text',120,true,'The authorised contact for this brief.'],
            'client_email'=>['Your email address','email',254,true,'Private workspace links, quotations and review requests go here.'],
        ],
    ];
}
function promote_stages(): array {
    return [
        ['Choose and brief','Select a preferred package and explain your business, offer and audience. Sending a brief creates a private submission, not an order or charge.'],
        ['Review and quotation','The editor checks suitability and capacity, then provides the exact price, tax treatment, two named accounts, formats, dates and terms for your acceptance.'],
        ['Payment and preparation','Use only the verified payment options offered in your private workspace. Production follows accepted scope and verified payment; a checkout return alone is not confirmation.'],
        ['Review and approve','Review the exact article, claims, links and creatives. Request corrections or approve the facts. Final editorial approval is separate, and changed material needs renewed approval.'],
        ['Publish and report','Follow scheduled delivery and actual publication links in your workspace. Renewal needs a new agreed scope; nothing renews or charges automatically.'],
    ];
}
function promote_client_progress(array $record,array $post,array $orders,array $report): array {
    $order=null;
    foreach($orders as$candidate)if(($candidate['id']??null)===($record['order']??null)&&($candidate['record']??null)===($record['id']??null)){$order=$candidate;break;}
    $digest=desk_digest($record,$post);
    $clientApproved=($record['client_approval']??'')===$digest;
    $ownerApproved=($record['owner_approval']??'')===$digest;
    $reviewReady=($record['review_digest']??'')===$digest;
    $live=!empty($record['public_visible'])&&!empty($record['public'])&&($record['state']??'')!=='withdrawn';
    $paid=($order['state']??'')==='paid';
    $accepted=$order&&!empty($order['quote']['hash'])&&($order['accepted_hash']??'')===$order['quote']['hash'];
    $blocked=in_array($record['state']??'',['closed','withdrawn','cancelled'],true)||!empty($order['paused'])||in_array($order['state']??'',['cancelled','refunded','disputed'],true);
    $published=0;foreach($report as$job)if(($job['status']??'')==='published'&&!empty($job['external_url']))$published++;
    $steps=[
        ['title'=>'Brief received','status'=>'Saved privately','href'=>'#client-review'],
        ['title'=>'Quotation and scope','status'=>$order?($accepted?'Exact quote accepted':'Quotation available'):'Awaiting editorial review and quotation','href'=>'#client-orders'],
        ['title'=>'Payment','status'=>$paid?'Payment verified':($order?'Not yet verified — check the invoice status':'Not requested yet'),'href'=>'#client-orders'],
        ['title'=>'Article and creative review','status'=>$clientApproved?'Your approval matches this version':($reviewReady?'Ready for your factual review':'Awaiting a review-ready version'),'href'=>'#client-review'],
        ['title'=>'Editorial approval','status'=>$ownerApproved?'Approved version recorded':'Awaiting final editorial approval','href'=>'#client-review'],
        ['title'=>'Publication and delivery','status'=>($live?'Website version is public. ':'Website publication not confirmed. ').$published.' of '.count($report).' recorded social jobs have publication links.','href'=>'#client-results'],
    ];
    $next=['title'=>'Your brief is with the editor','text'=>'Suitability, capacity and the exact quotation are reviewed before payment is requested. You can add private supporting material below.','href'=>'#client-files','label'=>'Add supporting material'];
    if($blocked)$next=['title'=>'Future delivery needs review','text'=>'This record or its current order is paused, closed or withdrawn. Review the status and recorded work before arranging a replacement, renewal or settlement.','href'=>'#client-orders','label'=>'Review order status'];
    elseif($order&&!$accepted)$next=['title'=>'Review your quotation','text'=>'Check the exact deliverables, named accounts, price, dates and terms. An expired quotation needs review; this overview does not renew it.','href'=>'#client-orders','label'=>'View quotation and terms'];
    elseif($order&&!$paid)$next=['title'=>'Check payment arrangements','text'=>'Use only the options available on the invoice. An uncertain payment must be reconciled before paying again.','href'=>'#client-orders','label'=>'View payment status'];
    elseif($reviewReady&&!$clientApproved)$next=['title'=>'Your factual review is ready','text'=>'Read the article and check its claims, destination links and images. Approve that exact version or request corrections below.','href'=>'#client-review','label'=>'Review article and creatives'];
    elseif($live)$next=['title'=>'Follow your publication and promotion','text'=>'The report distinguishes scheduled work from posts with confirmed publication links. A live article does not mean every social placement is complete.','href'=>'#client-results','label'=>'View publication evidence'];
    elseif($clientApproved)$next=['title'=>$ownerApproved?'Awaiting eligible publication':'Awaiting final editorial approval','text'=>'Your factual approval is recorded for this version. Payment, editorial approval and valid publishing dates are checked separately before release.','href'=>'#client-review','label'=>'View the reviewed version'];
    return ['steps'=>$steps,'next'=>$next,'blocked'=>$blocked];
}
