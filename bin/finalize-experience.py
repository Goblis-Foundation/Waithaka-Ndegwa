#!/usr/bin/env python3
"""Exact-match final integration on the dedicated feature branch; remove before merge."""
from pathlib import Path
import subprocess
ROOT=Path(__file__).resolve().parents[1]
if subprocess.check_output(['git','branch','--show-current'],cwd=ROOT,text=True).strip()!='feat/unified-journeys-seo-20261027':raise RuntimeError('Dedicated feature branch only')
files={}
def change(path,old,new):
    content=files.get(path,(ROOT/path).read_text())
    if content.count(old)!=1:raise RuntimeError('Expected one exact anchor in '+path+': '+old[:80])
    files[path]=content.replace(old,new)
home='''<section class="section personal-home-purpose"><div class="container personal-story-grid"><div><span class="eyebrow">Why this matters to me</span><h2>A meaningful life,<br>shared with others.</h2></div><div class="personal-story-copy"><p>I believe a fulfilling life is connected to the well-being of people and nature. The opportunities others helped me find have shaped a responsibility to create possibilities for others, too.</p><p>My Thoughts is where I share that journey honestly: personal experience, practical ideas, difficult lessons and questions worth exploring together. You do not need to have all the answers to take a useful next step.</p><div class="button-row"><a class="text-link" href="<?=url('/about#personal-values')?>">Read the values behind the work →</a><a class="text-link" href="<?=url('/explore')?>">Choose your next step →</a></div></div></div></section>\n\n'''
change('views/home.php',"<?php if(!empty($latest)): $featured=$latest[0];?>",home+"<?php if(!empty($latest)): $featured=$latest[0];?>")
change('views/about.php','<section class="section personal-build-section" id="building-together">',"<?php include __DIR__.'/personal_purpose.php'; ?>\n<section class=\"section personal-build-section\" id=\"building-together\">")
change('views/about.php','<a href="#building-together">Building together</a>','<a href="#personal-values">Values & purpose</a><a href="#why-i-write">Why I write</a><a href="#practical-collaboration">Practical collaboration</a><a href="#building-together">Building together</a>')
change('assets/js/unified-experience.js','if(headings.length>=3){',"if(headings.length>=3&&!content.querySelector('.personal-page-nav,[data-page-outline]')){")
change('assets/js/unified-experience.js',"if(form.matches('[data-promote-form]')",r"if(/\/write\/(?:integration|connections|security|account|google|microsoft|booking-mailboxes)(?:\/|$)/.test(location.pathname)||form.matches('[data-promote-form]')")
change('app/experience_workflows.php',"trim($p['standfirst'])","trim((string)($p['standfirst']??''))")
change('app/experience_workflows.php',"trim($p['featured_image_alt'])","trim((string)($p['featured_image_alt']??''))")
change('views/write/commercial_workflow.php',"'/write/desk/'.$r['id'].'#client-results'","'/write/desk/'.$r['id'].'#results'")
change('app/commercial_control.php',"if(!commercial_first_complete($o,desk_round_jobs((int)$a['pins'][0]['package'])))continue;\n  try{", "if(!commercial_first_complete($o,desk_round_jobs((int)$a['pins'][0]['package'])))continue;\n  $needsReview=false;foreach($a['pins']as$pin)foreach(desk_round_jobs((int)$pin['package'])as$job)if(in_array($job['status'],['failed','ambiguous','reconnect','cancelled'],true))$needsReview=true;\n  if($needsReview){$o['automatic_repeats']['state']='review';$o['automatic_repeats']['notice']='A delivery failed, needs reconnection or has an uncertain outcome. Review it before any further repeats.';comm_put('desk.order.'.$o['id'],$o);continue;}\n  try{")
p='assets/css/unified-experience.css';files[p]=(ROOT/p).read_text()+'\n.personal-values .ux-card h3{font:600 1.15rem/1.45 Arial,sans-serif;color:var(--ux-blue);margin:0 0 14px}.personal-values-bridge{max-width:85ch;line-height:1.8;color:var(--ux-ink);border-left:3px solid var(--ux-green);padding:12px 20px;margin-top:24px}.personal-home-purpose .personal-story-copy{line-height:1.8}#practical-collaboration .ux-card h3{font:600 1.15rem/1.45 Arial,sans-serif;color:var(--ux-blue)}#practical-collaboration .ux-card>a{display:inline-block;margin-top:12px;color:var(--ux-blue);text-underline-offset:4px}\n'
p='views/desk/suitability_fields.php';files[p]="<?php require_once __DIR__.'/../../app/commercial_control.php'; ?>\n"+(ROOT/p).read_text()
for path,content in files.items():(ROOT/path).write_text(content)
print('Integrated user-supplied personal purpose and review hardening:',len(files),'files. Existing profile_statement.php and impact_summary.php unchanged.')
