# Inaugural article — Impact-Driven Inspiration

This release uses the author's supplied autobiographical narrative as the source of the first editorial welcome article. It preserves the firsthand account and revises repetitive passages, spelling and presentation for a first-person, readable introduction.

- Canonical path: `/my-thoughts/impact-driven-inspiration`
- Article title: **Impact-Driven Inspiration: My Journey, Purpose and an Invitation to Connect**
- Author: Waithaka Ndegwa; publisher: existing active Writer owner
- Topic: Social Entrepreneurship
- Reading groups: Personal Experiences; Ideas & Perspectives
- Article image: existing site-owned `/assets/images/journey/speaking.webp`; no replacement portrait or fabricated photographs
- SEO: descriptive title, description, existing BlogPosting JSON-LD, Open Graph/Twitter cards and canonical URL
- Links: email newsletter, My Thoughts, Contact and the Facebook Waithaka Ndegwa Connect group

## Publishing and operational behaviour

The post-deploy hook runs only after the existing migrations. The CLI publisher acquires a MySQL advisory lock, checks the canonical slug, validates the existing Writer identity and the category, sanitizes the approved HTML, creates one published and selected post with its supplied tags, then commits. A subsequent deploy leaves the saved public post untouched. Editorial edits, archiving and future publication changes remain controlled through Writer.

If the owner is ambiguous or the category is missing, the hook fails rather than attribute the post to a random person. It never creates an alternate user, enables social channels or sends emails automatically.

Editorial policy (4 November 2026): no articles appear on the homepage, including the inaugural story, latest thought, selected articles or article-group cards. My Thoughts and the article's canonical page remain available.

Deployment acceptance should verify the article URL is HTTP 200, contains the introductory title and subscribe/contact links, has a canonical BlogPosting schema, and appears in Personal Experiences and My Thoughts archives, not the homepage. Verify the post row is unique across repeat deployment. Treat a successful CI run as source validation, not production proof. Production URL and owner DB publication must be verified after release.
