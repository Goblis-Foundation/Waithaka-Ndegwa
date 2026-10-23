<main class="write-main writer-editor-page">
<form data-editor-form data-post-id="<?=intval($post['id']??0)?>" method="post" enctype="multipart/form-data" action="<?=url('/write/save')?>"><?=csrf_field()?><input type="hidden" name="id" value="<?=intval($post['id']??0)?>"><input type="hidden" name="draft_version" value="<?=intval($post['_draft_version']??0)?>"><input type="hidden" name="content_html" value="<?=e($post['content_html']??'')?>">
  <div class="writer-head editor-page-head">
    <div><span class="writer-kicker"><?=empty($post['id'])?'New thought':'Editing'?></span><h1><?=empty($post['id'])?'Start with the idea':'Refine the thought'?></h1><div class="editor-health"><span class="save-state"><?=empty($post['id'])?'Save a draft once to enable autosave.':'Ready'?></span><span data-word-count>0 words</span><span data-read-time>0 min read</span></div></div>
    <div class="writer-actions"><a class="wbtn" href="<?=url('/write/studio#ai')?>" target="_blank" rel="noopener">Drafting help ↗</a><button class="wbtn" type="button" data-download-draft>Download copy</button><a class="wbtn mobile-settings" href="#post-settings">Post settings</a><?php if(!empty($post['id'])):?><a class="wbtn" href="<?=url('/write/preview/'.$post['id'])?>" target="_blank" rel="noopener noreferrer">Saved preview ↗</a><?php endif;?><button class="wbtn" type="submit" name="action" value="draft">Save draft</button><button class="wbtn primary" type="submit" name="action" value="publish"><?=!empty($post['_is_public'])?'Publish changes':'Publish'?></button></div>
  </div>

  <?php foreach(flashes() as $f):?><div class="notice <?=$f['type']==='error'?'error':''?>"><?=e($f['message'])?></div><?php endforeach;?>
  <?php if(!empty($post['_is_public'])):?><p class="notice">You are editing a private working copy. Save draft and autosave keep the current public article in place. Publish changes applies this version to the same public URL.</p><?php elseif(($post['_public_status']??'')==='scheduled'):?><p class="notice">The scheduled version is unchanged until you press Publish again. Private edits do not replace the scheduled article automatically.</p><?php endif;?>

  <div class="editor-layout editor-layout-refined">
    <section class="editor-canvas editor-canvas-refined" id="writing-canvas">
      <div class="editor-prompt"><span>Title</span><small>Say exactly what the reader is about to think through with you.</small></div>
      <input class="title-input" name="title" placeholder="Give the thought a clear title" value="<?=e($post['title']??'')?>" required maxlength="240">
      <textarea class="standfirst-input" name="standfirst" rows="2" placeholder="A short opening summary that gives the reader a reason to continue…"><?=e($post['standfirst']??'')?></textarea>
      <div class="toolbar" aria-label="Formatting toolbar"><button type="button" data-cmd="bold" title="Bold"><strong>B</strong></button><button type="button" data-cmd="italic" title="Italic"><em>I</em></button><span></span><button type="button" data-cmd="formatBlock" data-value="h2">H2</button><button type="button" data-cmd="formatBlock" data-value="h3">H3</button><button type="button" data-cmd="formatBlock" data-value="blockquote">Quote</button><span></span><button type="button" data-cmd="insertUnorderedList">• List</button><button type="button" data-cmd="insertOrderedList">1. List</button><button type="button" data-cmd="createLink" onclick="this.dataset.value=prompt('Link URL')||''">Link</button><button type="button" data-cmd="removeFormat">Clear</button></div>
      <div class="editor-body" contenteditable="true" role="textbox" aria-label="Article body" aria-multiline="true" spellcheck="true" data-editor-body><?=sanitize_html($post['content_html']??'<p></p>')?></div>
      <div class="editor-end-note"><strong>Write first. Optimise second.</strong><span>Tags and SEO assistance can be generated when you publish.</span></div>
    </section>

    <aside class="editor-side editor-side-refined" id="post-settings"><a class="mobile-back-to-writing" href="#writing-canvas">↑ Back to writing</a>
      <div class="editor-side-tabs" role="tablist" aria-label="Post settings"><button type="button" class="active" data-editor-tab="plan">Plan</button><button type="button" data-editor-tab="publish">Publish</button><button type="button" data-editor-tab="media">Media</button><button type="button" data-editor-tab="seo">SEO</button></div>

      <div class="panel editor-panel active" data-editor-panel="plan">
        <div class="panel-heading"><span>01</span><div><h3>Private plan</h3><p>Working notes that never appear on the public post.</p></div></div>
        <div class="field"><label>What is the central question?</label><textarea name="writer_note" rows="5" placeholder="The question, tension or observation I want to work through…"><?=e($post['writer_note']??'')?></textarea><div class="help">Use this for the angle, argument, people to follow up with, or what still feels unresolved.</div></div>
        <div class="field"><label>Sources & evidence <span class="optional">private</span></label><textarea name="source_notes" rows="8" placeholder="Links, document names, statistics to verify, interview notes, source reminders…"><?=e($post['source_notes']??'')?></textarea><div class="help">This is an internal research pad only. It is not rendered, indexed or sent into the public article.</div></div>
        <div class="writer-checklist"><strong>Before publishing</strong><span>□ Is the main claim clear?</span><span>□ What evidence could change my view?</span><span>□ Have people, photos and quotes been used with appropriate permission?</span><span>□ Does the conclusion say what I actually think?</span></div>
        <div class="editorial-public-editor-link"><h3>Public evidence & updates</h3><p>Prepare separate evidence cards and explain meaningful updates or corrections. These public fields never import your private notes.</p><?php if(!empty($post['id'])):?><a class="wbtn" href="<?=e(url('/write/editorial/posts/'.$post['id']))?>">Manage public evidence & updates →</a><?php else:?><p class="help">Save this article once to open its evidence and update editor.</p><?php endif;?></div>
      </div>

      <div class="panel editor-panel" data-editor-panel="publish" hidden>
        <div class="panel-heading"><span>02</span><div><h3>Publishing</h3><p>Where this thought belongs and when it goes live.</p></div></div>
        <div class="field"><label>Topic</label><select name="category_id" required><option value="">Choose a topic…</option><?php foreach($categories as $c):?><option value="<?=$c['id']?>" data-slug="<?=e($c['slug'])?>" <?=($post['category_id']??null)==$c['id']?'selected':''?>><?=e($c['name'])?></option><?php endforeach;?></select></div>
        <div class="field"><label>Publish date & time · Nairobi</label><input type="datetime-local" name="published_at" <?=!empty($post['_is_public'])?'readonly':''?> value="<?=!empty($post['published_at'])?e(date('Y-m-d\TH:i',strtotime($post['published_at']))):e(date('Y-m-d\TH:i'))?>"><div class="help"><?=!empty($post['_is_public'])?'The original publication date is retained. Publish changes updates the live article immediately.':'Choose a future time and Publish will schedule this version automatically.'?></div></div>
        <label class="toggle feature-toggle"><input type="checkbox" name="is_selected" value="1" <?=!empty($post['is_selected'])?'checked':''?>><span><strong>Selected thought</strong><small>Feature this post in highlighted areas of the public site.</small></span></label>
        <div class="field"><label>Series <span class="optional">optional</span></label><input name="series_name" maxlength="220" value="<?=e($post['series_name']??'')?>" placeholder="e.g. Rethinking regional infrastructure"></div>
        <div data-travel-fields hidden class="travel-fields"><div class="field"><label>Country</label><input name="travel_country" value="<?=e($post['travel_country']??'')?>" placeholder="Country"></div><div class="field"><label>City / region</label><input name="travel_city" value="<?=e($post['travel_city']??'')?>" placeholder="City or region"></div></div>
      </div>

      <div class="panel editor-panel" data-editor-panel="media" hidden>
        <div class="panel-heading"><span>03</span><div><h3>Media</h3><p>One strong image and an optional video link.</p></div></div>
        <?php if(!empty($post['featured_image'])):?><img class="preview-img" src="<?=e(featured_image_url($post['featured_image']))?>" alt=""><?php else:?><div class="media-placeholder">Featured image preview</div><?php endif;?>
        <div class="field"><label>Featured photo</label><input type="file" name="featured_image_file" accept="image/jpeg,image/png,image/webp"><div class="help">JPG, PNG or WebP. Large uploads are resized automatically.</div></div>
        <div class="field"><label>Alt text</label><input name="featured_image_alt" maxlength="500" value="<?=e($post['featured_image_alt']??'')?>" placeholder="Describe what matters in the image"></div>
        <div class="field"><label>Caption <span class="optional">optional</span></label><input name="featured_image_caption" maxlength="500" value="<?=e($post['featured_image_caption']??'')?>"></div>
        <div class="panel-divider"></div>
        <div class="field"><label>Video link <span class="optional">optional</span></label><input type="url" name="video_url" value="<?=e($post['video_url']??'')?>" placeholder="YouTube or Vimeo URL"><div class="help">Paste a link; the public post handles the responsive embed.</div></div>
      </div>

      <div class="panel editor-panel" data-editor-panel="seo" hidden>
        <div class="panel-heading"><span>04</span><div><h3>Discoverability</h3><p>Keep the writing natural; let the metadata support it.</p></div></div>
        <div class="seo-assist"><strong>Automatic assistance</strong><p>Publishing fills blank search metadata from your writing and adds topic tags. Your overrides are retained.</p></div>
        <div class="field"><label>SEO title override <span class="optional">optional</span></label><input name="seo_title" maxlength="255" value="<?=e($post['seo_title']??'')?>" placeholder="Leave blank to generate from the post"></div>
        <div class="field"><label>SEO description override <span class="optional">optional</span></label><textarea name="seo_description" maxlength="320" placeholder="Leave blank to generate from the standfirst and article"><?=e($post['seo_description']??'')?></textarea></div>
        <div class="seo-preview"><span>Search preview</span><strong data-seo-preview-title><?=e(($post['seo_title']??'') ?: ($post['title']??'Your title will appear here'))?></strong><p data-seo-preview-description><?=e(($post['seo_description']??'') ?: ($post['standfirst']??'Your standfirst will help form the search description.'))?></p><small>waithakandegwa.com/my-thoughts/…</small></div>
      </div>
    </aside>
  </div>
</form>
</main>
