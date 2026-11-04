<?php
declare(strict_types=1);
/* Build the actual new partial inside the real site layout, with no database. */
require __DIR__.'/experience_render_fixture.php';
require_once dirname(__DIR__).'/app/editorial.php';
require_once dirname(__DIR__).'/app/inaugural_story.php';
$_SERVER['REQUEST_URI']='/my-thoughts/impact-driven-inspiration';
$post=['id'=>1,'slug'=>'impact-driven-inspiration','title'=>'Impact-Driven Inspiration: My Journey, Purpose and an Invitation to Connect','standfirst'=>'From Endarasha in Nyeri County to the work of building social enterprises, this is where my story begins—and why I invite you to learn, question and create meaningful impact with me.','published_at'=>'2026-11-04 09:00:00','video_url'=>'','content_html'=>file_get_contents(dirname(__DIR__).'/app/content/impact-driven-inspiration.html')];
$articleToc=editorial_toc($post['content_html']);
$storyParts=inaugural_story_parts($post,$articleToc);
if(!$storyParts||count($storyParts['chapters'])!==8)throw new RuntimeException('Expected all eight original article chapters.');
if($storyParts['intro'].implode('',array_column($storyParts['chapters'],'html'))!==$articleToc['html'])throw new RuntimeException('Article content changed during presentation.');
$body='<article class="article article-journey" data-article data-inaugural-story>'.ef_render('partials_inaugural_story',compact('post','storyParts')).'</article>';
file_put_contents($out.'/story-article.html',ef_render('layout',['page'=>'post','post'=>$post,'content'=>$body,'suppressAnalytics'=>true,'seo'=>['title'=>$post['title'],'type'=>'article','canonical'=>url($_SERVER['REQUEST_URI'])]]));
echo "Rendered the actual story template inside the actual production layout.\n";
