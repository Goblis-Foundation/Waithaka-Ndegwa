<?php
declare(strict_types=1);
/** Shared discovery, navigation and technical SEO. Never changes a transaction or consent. */
function ux_journeys(): array {
 return [
  ['key'=>'read','title'=>'Read and explore','intro'=>'Follow first-hand ideas, practical resources and the public record.','links'=>['/my-thoughts'=>'Read My Thoughts','/startup-journeys'=>'Follow Startup Journeys','/founders'=>'Read Founder Stories','/resources'=>'Use a practical resource','/watch'=>'Watch published videos','/monthly-roundup'=>'Read the monthly roundup']],
  ['key'=>'opportunities','title'=>'Find or share an opportunity','intro'=>'Check the source, eligibility and dates before taking the next step.','links'=>['/opportunities'=>'Find funding, programmes and roles','/opportunities/jobs'=>'Browse employment roles','/opportunities/submit'=>'Submit an employment opportunity','/enterprises'=>'Explore enterprise areas']],
  ['key'=>'participate','title'=>'Share an experience','intro'=>'Submit privately, review the facts and choose what may become public.','links'=>['/founders/submit'=>'Submit a founder story','/contact?type=correction'=>'Suggest a factual correction','/contact?type=reader_question'=>'Continue a conversation']],
  ['key'=>'promote','title'=>'Propose a sponsored feature','intro'=>'Check suitability, build a brief, agree the scope and follow verified delivery.','links'=>['/advertise'=>'Explore the publishing service','/advertising-policy'=>'Check advertising suitability','/promote'=>'Build an interactive brief','/partner-stories'=>'Read published partner stories']],
  ['key'=>'connect','title'=>'Connect and keep in touch','intro'=>'Choose a purposeful conversation or subscribe with control over your preferences.','links'=>['/book'=>'Request a meeting','/contact'=>'Send an enquiry','/professional-information'=>'Media and professional information','/newsletter'=>'Subscribe to My Thoughts','/newsletter/preferences/request'=>'Manage email preferences']],
  ['key'=>'support','title'=>'Understand and support the work','intro'=>'Read the goals and evidence, then choose whether and how to support the writing.','links'=>['/about'=>'Read the story and Goal 2030','/editorial-independence'=>'Understand editorial independence','/contribute'=>'Support My Thoughts']]
 ];
}
function ux_workspace_groups(): array {
 return [
  'Create and publish'=>['/write/new'=>'New thought','/write/studio'=>'Studio & AI','/write/desk'=>'Opportunities & sponsored features','/write/publishing'=>'Posting & calendar','/write/media'=>'Images & videos'],
  'People and messages'=>['/write/communication'=>'Communication Centre','/write/correspondence'=>'Email & response log','/write/readers'=>'Reader enquiries','/write/contact-controls'=>'Held enquiries & review','/write/meetings'=>'Meetings & booking'],
  'Plan and document'=>['/write/program'=>'Daily & weekly programme','/write/notes'=>'Notes & reports','/write/voice'=>'Voice notes & ideas','/write/journeys'=>'Startup Journeys','/write/enterprises'=>'Enterprise portfolio'],
  'Audience and discovery'=>['/write/monthly-roundup'=>'Automatic monthly newsletter','/write/newsletter'=>'Newsletter editions','/write/seo-checks'=>'SEO publishing checks','/write/discovery'=>'Search performance','/write/visitor-statistics'=>'Visitor statistics'],
  'Review and manage'=>['/write/desk/orders'=>'Commercial orders','/write/desk/settings'=>'Commercial configuration','/write/opportunities'=>'Employment reviews','/write/impact'=>'Impact reporting','/write/reconciliation'=>'Implementation register'],
  'Connections and security'=>['/write/connections'=>'Integration directory','/write/google'=>'Google Workspace','/write/microsoft'=>'Microsoft 365','/write/security'=>'Devices & passkeys','/write/operations'=>'Operations & delivery','/write/account'=>'My account']
 ];
}
function ux_public_metadata(): array {
 return [
  '/explore'=>['Explore the journeys · Waithaka Ndegwa','Read practical ideas, discover opportunities, share a founder story, propose a sponsored feature or connect with Waithaka Ndegwa.'],
  '/advertise'=>['Sponsored articles and social promotion · Waithaka Ndegwa','Explore reviewed sponsored articles and agreed social promotion. Compare publishing packages, check suitability and build a private business brief.'],
  '/advertising-policy'=>['Advertising suitability and editorial standards · Waithaka Ndegwa','Understand what is reviewed before a sponsored feature is accepted: relevance, truthful claims, safety, evidence, permissions and clear disclosure.'],
  '/opportunities'=>['Grants, funding and opportunities · Waithaka Ndegwa','Discover published grants, fellowships, conferences, programmes and enterprise opportunities. Check eligibility, deadlines and official application links.'],
  '/opportunities/jobs'=>['Employment opportunities · Waithaka Ndegwa','Browse founder-approved employment and volunteer roles. Apply through each employer, with no CV collection on this website.'],
  '/partner-stories'=>['Sponsored partner stories · Waithaka Ndegwa','Read clearly disclosed sponsored features about businesses, products and services, with information and links to the responsible providers.'],
  '/monthly-roundup'=>['Monthly roundups · Waithaka Ndegwa','Explore the monthly public record of writing, opportunities and practical updates, with a welcome to each new month.']
 ];
}
function ux_seo(array $seo,string $path,bool $private=false,array $query=[]): array {
 if($private||str_contains(strtolower((string)($seo['robots']??'')),'noindex'))return $seo;
 $meta=ux_public_metadata()[$path]??null;if($meta){$seo['title']=$meta[0];$seo['description']=$meta[1];}
 if(in_array($path,['/opportunities','/partner-stories'],true)){
  $page=archive_page_number($query['page']??null);if($page!==null&&$page>1){$seo['canonical']=url(archive_page_path($path,$page));$seo['title'].=' · Page '.$page;}
  foreach(['q','type','country','sector','status']as$key)if(isset($query[$key])&&is_string($query[$key])&&$query[$key]!==''){$seo['robots']='noindex,follow';break;}
 }
 return $seo;
}
function ux_breadcrumbs(array $seo,string $path,bool $private=false): array {
 if($private||$path==='/'||str_contains(strtolower((string)($seo['robots']??'')),'noindex')||(http_response_code()?:200)>=400||str_starts_with($path,'/write')||str_starts_with($path,'/client'))return [];
 $known=['/my-thoughts'=>'My Thoughts','/startup-journeys'=>'Startup Journeys','/founders'=>'Founder Stories','/resources'=>'Resources','/watch'=>'Watch','/opportunities'=>'Opportunities','/partner-stories'=>'Partner stories','/monthly-roundup'=>'Monthly roundups','/enterprises'=>'Enterprise areas','/explore'=>'Explore','/advertise'=>'Sponsored publishing','/advertising-policy'=>'Advertising policy','/about'=>'About','/start-here'=>'Start here','/professional-information'=>'Professional information'];
 $parts=explode('/',trim($path,'/'));$root='/'.($parts[0]??'');if(!isset($known[$root]))return [];
 $out=[['name'=>'Home','url'=>url('/')]];$out[]=['name'=>$known[$root],'url'=>url($root)];
 if($path!==$root){$name=preg_replace('/\s*[·|—]\s*Waithaka Ndegwa.*$/u','',(string)($seo['title']??''));if(!$name)return [];$out[]=['name'=>mb_substr(strip_tags($name),0,160),'url'=>(string)($seo['canonical']??url($path))];}
 return $out;
}
function ux_breadcrumb_schema(array $crumbs): array {
 $items=[];foreach($crumbs as$i=>$crumb)$items[]=['@type'=>'ListItem','position'=>$i+1,'name'=>$crumb['name'],'item'=>$crumb['url']];return ['@context'=>'https://schema.org','@type'=>'BreadcrumbList','itemListElement'=>$items];
}
function ux_sitemap_entries(): array {
 $entries=desk_sitemap();$extra=['/explore','/advertise','/advertising-policy','/monthly-roundup','/opportunities/jobs'];foreach($extra as$path)$entries[]=['loc'=>url($path)];
 foreach(comm_records('roundup.edition',240)as$r)if(!empty($r['public'])&&is_int($r['published_at']??null)&&$r['published_at']<=time()&&preg_match('/^[0-9]{4}-(0[1-9]|1[0-2])$/D',$r['key']??''))$entries[]=['loc'=>url('/monthly-roundup/'.$r['key']),'lastmod'=>gmdate(DATE_ATOM,$r['published_at'])];
 $out=[];foreach($entries as$entry){$path=(string)parse_url($entry['loc']??'',PHP_URL_PATH);if($path==='/promote'||str_starts_with($path,'/client')||str_starts_with($path,'/write')||str_contains($entry['loc']??'','?'))continue;$out[$entry['loc']]=$entry;}
 return array_values($out);
}
function ux_pending_counts(): array {
 $queries=['Enquiries awaiting a next step'=>"SELECT COUNT(*) FROM reader_enquiries WHERE status IN ('new','waiting')",'Email needing attention'=>"SELECT COUNT(*) FROM email_outbox WHERE status IN ('failed','ambiguous')",'Publishing jobs needing review'=>"SELECT COUNT(*) FROM publishing_jobs WHERE status IN ('failed','ambiguous','reconnect')",'Private article drafts'=>"SELECT COUNT(*) FROM posts WHERE status='draft'"];
 $out=[];foreach($queries as$label=>$sql)try{$out[$label]=(int)db()->query($sql)->fetchColumn();}catch(Throwable){$out[$label]=null;}return $out;
}
function ux_seo_audit(): array {
 $q=db()->query('SELECT p.id,p.title,p.slug,p.standfirst,p.featured_image,p.featured_image_alt FROM posts p WHERE '.published_where().' ORDER BY p.published_at DESC,p.id DESC LIMIT 200');$rows=$q->fetchAll();$seen=[];$out=[];
 foreach($rows as$p){$notes=[];$title=trim($p['title']);$key=mb_strtolower($title);if(isset($seen[$key]))$notes[]='Another sampled article uses the same title.';$seen[$key]=true;if(mb_strlen($title)>80)$notes[]='Review whether the title can be more focused.';if(trim((string)($p['standfirst']??''))==='')$notes[]='Add a useful original summary for readers and search previews.';if(!empty($p['featured_image'])&&trim((string)($p['featured_image_alt']??''))==='')$notes[]='Add an accurate featured-image description.';$r=desk_post_record((int)$p['id']);$out[]=['id'=>(int)$p['id'],'title'=>$title,'url'=>url($r?desk_path($r):'/my-thoughts/'.$p['slug']),'notes'=>$notes];}return $out;
}
function ux_dispatch(string $path): bool {
 if($path==='/publishing-sitemap.xml'){journeys_method(['GET','HEAD']);header('Content-Type: application/xml; charset=utf-8');if(($_SERVER['REQUEST_METHOD']??'GET')!=='HEAD')echo sitemap_xml(ux_sitemap_entries());return true;}
 if(in_array($path,['/explore','/advertise','/advertising-policy'],true)){journeys_method(['GET','HEAD']);$meta=ux_public_metadata()[$path];$view=match($path){'/explore'=>'hub','/advertise'=>'advertise',default=>'advertising_policy'};render('experience/'.$view,['page'=>'experience','seo'=>['title'=>$meta[0],'description'=>$meta[1],'canonical'=>url($path)]]);return true;}
 if(!in_array($path,['/write/control-centre','/write/seo-checks'],true))return false;
 require_auth();private_response_headers();journeys_method(['GET','HEAD']);try{desk_owner();}catch(Throwable){http_response_code(403);return true;}
 if($path==='/write/control-centre')render_write('control_centre',['writeTitle'=>'Control centre','counts'=>ux_pending_counts()]);else render_write('seo_checks',['writeTitle'=>'SEO publishing checks','checks'=>ux_seo_audit()]);return true;
}
