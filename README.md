# Content Hub

A general-purpose social content workspace. The Laravel application is in [hub](hub).

## Implemented

- Owner login, rate-limited authentication and logout; no public registration.
- Create and edit application profiles: website, description, audience, tone, language and content instructions.
- Create and edit text drafts per application and intended channel.
- Saved preview and explicit review state. Editing returns reviewed content to draft.
- Filter posts by application.
- Optional settings for DeepSeek, OpenAI, Gemini and Anthropic, with encrypted API keys and per-application provider preferences.
- AI assistant for drafting, rewriting, translation and content ideas, using the selected application's audience, voice and language. Hindi uses Devanagari.
- Four provider text API integrations with configurable model identifiers. Generated text opens in a review screen and can be saved as a new draft.
- Daily request limits, output token limits and an estimated USD budget per provider. Requests reserve estimated costs before calling a provider and reconcile reported token usage afterward.
- Private request history, duplicate-submission protection and clear handling of failures, incomplete output and uncertain timeouts. No automatic retries or provider fallbacks.
- Owner-scoped access checks on profiles, posts and provider preferences.
- Facebook Page connections per application with encrypted tokens, identity verification, replacement and disconnection.
- Reviewed Facebook text/link publishing, explicit destination confirmation, optional link sharing, public links and immutable submission history.
- Duplicate protection: successful and uncertain submissions lock the saved post against editing and resubmission.
- Account setup for Instagram professional accounts, LinkedIn members/organizations, X, YouTube channels and WhatsApp Business Cloud API senders. Account labels and platform-specific IDs are stored per application; credentials are optional, encrypted, hidden and replaceable.
- Direct publishing and scheduling for Instagram images/Reels, LinkedIn text/images/video, X text/images/video, YouTube video uploads, and WhatsApp Business recipient messages. Credentials are verified before use.
- Application health monitoring: one configurable public endpoint per application, manual checks, optional 5/15/60-minute scheduled checks, online/offline/error/stale states, response timing, 30-day check history and observed outage/recovery records.
- Website context capture and per-application HTML, RSS/Atom or text-based PDF sources with captured evidence and optional comparison pages.
- AI research packages: headline, caption, hashtags, evidence quotes and concerns; deterministic branded PNG title cards with Hindi text support.
- Reviewed Facebook photo publishing and timezone-aware scheduled delivery, with a cancellable publishing queue and worker heartbeat.
- Opt-in automatic publishing from owner-approved official sources, exact-quote/date checks, duplicate detection and source revalidation before publishing.

No named application is built in. No application source-code or database connection is needed to use the workspace.

## Run locally

Requires PHP 8.3+ with PDO SQLite (local development), Composer and the usual Laravel extensions. This foundation uses plain Blade and CSS and requires no frontend asset build.

From the repository's `hub` directory:

    composer install
    Copy-Item .env.example .env
    php artisan key:generate

Copy the environment file and generate its key only on initial setup; preserve existing settings and APP_KEY. Create database/database.sqlite if it does not exist, then:

    php artisan migrate
    php artisan hub:create-owner
    php artisan serve --host=127.0.0.1 --port=8085

The owner command prompts for name, email and a hidden password. No default account or password is shipped. Open http://127.0.0.1:8085.

## Verify

    php artisan test
    vendor/bin/pint --test

Tests use an isolated in-memory SQLite database and fake external provider responses. They make no external AI or social requests. Actual account access, model support and generation quality still need verification using the owner's chosen provider.

## Enable AI when ready

Open AI providers. Supply an API key, exact text model identifier, daily request/output limits, daily estimated USD budget and the model's current input/output USD prices per million tokens. Then enable generation. Saving settings itself makes no request.

Open AI assistant, choose an application and provider (or its saved preference), choose a task and supply a topic and source text. Leave language blank to inherit the application language, or enter Hindi, English or another language. Links entered in the standalone AI assistant are references only. Use Research & automation to retrieve website context and source evidence.

Generation sends only the selected application's profile and the entered task/source material to the selected provider. It does not publish, overwrite an existing post or send other applications' data. Use Save as a draft after reviewing the result.

Budgets reset at midnight UTC. Reservations estimate input from prompt bytes plus overhead and the configured output limit; completed usage uses provider token counts where available. Estimates rely on the owner's model prices and do not guarantee the provider's invoice. Keep provider-side billing limits in place. Failed, pending and uncertain calls retain their reservation until the daily window ends. A 25-second timeout never triggers an automatic retry. Check provider usage before intentionally starting another request after an uncertain result.

## Connect Facebook when ready

All six platforms use Social accounts → verify identity → review a channel-specific draft → publishing preview → publish or schedule. Account IDs and encrypted tokens belong to one application. Choose the Instagram token login method explicitly. Blank replacement tokens preserve saved credentials. Saving setup makes no network request. Tokens are supplied manually; automatic OAuth refresh is not implemented, so expired tokens must be replaced and verified.

| Channel | Supported publication | Connection requirements |
| --- | --- | --- |
| Facebook | Text/link, one PNG image or MP4 video | Page token and Page publishing permissions |
| Instagram | One image (converted to JPEG) or video Reel | Professional account, correct login method, content publishing permission; public HTTPS APP_URL |
| LinkedIn | Text, one image or video | Member OAuth with w_member_social, or organization access with w_organization_social; openid/profile for member identity checks; organization ACL access for organization verification |
| X | Text, one image or video | OAuth2 user token with users.read, tweet.read, tweet.write and media.write; eligible API access |
| YouTube | MP4, title, description, privacy and audience selection | Channel OAuth with youtube.upload and youtube.readonly; unaudited apps may be limited to private uploads |
| WhatsApp Business | One recipient: service-window text/image/video, or approved text template with body parameters | Cloud API phone-number ID/token, recipient consent; last inbound time within 24 hours for free-form messages |

This does not send WhatsApp Status/Channel posts or bulk recipient campaigns. Templates replace the draft body and require explicit wording/parameter confirmation. Uploaded YouTube videos and accepted WhatsApp messages are recorded as platform-accepted; processing, delivery and read receipts are not independently confirmed. One saved post has one channel/destination; create a separate draft to adapt the same topic for another platform.

Instagram, LinkedIn and X media wait for processing before the final write. `hub:complete-publications` runs every minute through the existing Laravel scheduler. Processing expires after one hour. Credentials/source approval changes stop pending final writes. Uncertain writes are never automatically repeated. Use a persistent cache shared by the web and scheduler processes. Instagram uses a two-hour signed URL for the selected attachment; all other media remains private. LinkedIn defaults to API version 202606 (`LINKEDIN_API_VERSION`). Upload destinations are restricted to documented provider hosts and redirects are disabled. Live permissions and platform media restrictions still require account testing; automated tests fake provider responses.

The built-in workflow caps videos at 40 MB (WhatsApp 16 MB), images at 8 MB (X/WhatsApp 5 MB), and text according to channel limits. X uses a conservative weighted character count, without shortening URLs locally. Large or unsupported media is rejected without truncating the post.

Open Social accounts, select an application, and enter a numeric Facebook Page ID and its Page access token. Saving is local only. Choose Verify Page to check that the token identifies the expected Page. Identity verification does not prove publishing permission: the Meta app/token also needs pages_manage_posts, pages_read_engagement and appropriate Page access. Follow Meta's setup and app review requirements. This connection flow uses a manually supplied Page token, not OAuth login.

Create a Facebook draft, save it, optionally create a branded image, and mark it reviewed. Open publishing preview, choose a verified Page for that application, optionally include the saved link, and confirm Publish now or choose a scheduled date/time. The saved body and selected link are sent; when an image is present its title is visible and the link is included in the photo caption. Implementation tests use simulated Facebook responses; live account access still needs verification.

Each submission is reserved in the database before the API call. Repeated form submissions return the existing result. A known rejection permits another attempt from a fresh preview and retains the rejected attempt in history. Timeouts, malformed responses and unknown outcomes stay locked and are never automatically retried. Check the Page directly and reconcile with an administrator; automatic reconciliation is not implemented. Interrupted processes can leave a publishing record that also stays locked. Do not recreate an uncertain post merely to retry it.

Confirmed success is saved before retrieving its public link. Refresh public link only reads Facebook and cannot create another post. Disconnecting removes the token and blocks new submissions; already started requests may still finish. Existing Facebook posts and history remain.

The API version defaults to v25.0; configure FACEBOOK_GRAPH_VERSION in the environment when needed. Keep it on a supported version for your Meta app. Calls use a fixed Meta host, bearer-token headers, no redirects, a 25-second timeout and no automatic retries. Back up APP_KEY with the database to keep Page tokens decryptable.

## Research and scheduled publishing

In Research & automation, read an application's website to save context for AI assistance. Each URL reads one page; the reader does not crawl an entire site or follow article links. Add specific notice pages, feeds or PDFs for each topic/exam. Optional HTML element IDs scope extraction to a section. Text-based PDFs require `pdftotext`; scanned PDFs and JavaScript-only pages need an alternative readable URL.

Select a saved AI provider on the application and configure its budget before enabling source checks. Each changed source uses one budgeted AI generation; unchanged pages do not generate again. Source snapshots retain retrieved text, URLs, capture time, AI output and review concerns. Add a comparison URL to check an existing article against official evidence. This is evidence matching, not a guarantee that an authority's statements are correct.

Automatic publishing is opt-in per HTTPS official source and requires a verified matching Facebook, Instagram, LinkedIn or X account for the same application. Channel length/media requirements are checked before scheduling; YouTube and WhatsApp require manual audience/recipient settings. The first capture remains a review draft. Later changes can schedule exact source headlines/excerpts if a supported, unambiguous notice date is within seven days, quotes match and AI reports no concerns. When a comparison URL is supplied, both quotes must also occur there. Editorial rewrites, translations, uncertain dates and mismatches stay in review. Automatically published excerpts retain the source's language. AI suggestions are still available in the selected application language for manual review.

Automatic posts are re-fetched before submission; changed/unavailable evidence or changed source settings, post content or Page credentials hold publishing. Repeated selected headline/excerpt pairs for a source do not create another post. Each source check selects one announcement; broad feeds with many simultaneous notices should be split into specific source pages. Changing source settings resets its baseline and cancels queued automatic posts.

Images are 1200×900 branded title cards, not generated photographs. Server rendering uses ImageMagick with Pango for Unicode shaping and local fonts; set `HUB_IMAGE_CONVERT` if its executable is not `/usr/bin/convert`. Set `HUB_PDFTOTEXT` if the PDF executable is not `/usr/bin/pdftotext`. No PHP dependencies were added. Missing renderers hold automatic image posts; a draft remains available. Editing a post clears its stale image and cancels queued delivery. Create the image after final edits, then review the complete saved post.

The existing once-per-minute Laravel scheduler also runs `hub:run-content-workflow`. Run it as the application user with access to private storage and a persistent shared cache store (database or file, not array). Publishing queue shows the latest worker heartbeat. Schedules use UTC internally and accept a selected timezone in the UI. Due work runs in bounded batches and may be delayed when many items are due or the server is unavailable. Cancel queued posts in the queue; started/uncertain submissions cannot be cancelled or automatically retried.

Sources default to paused and automatic publishing defaults to off. Add and approve the intended sources after deployment; upgrading does not automatically publish existing drafts.

## Not implemented yet

AI connection/model-discovery testing, arbitrary media uploads, social OAuth/token refresh, WhatsApp delivery webhooks, automatic publishing retries/reconciliation, whole-site crawling and scanned-PDF OCR. Publishing requires owner review or an explicitly enabled content/official-source automation.

AI connections default to disabled. Model identifiers are configured manually and must support the chosen text API; not every provider model is compatible. Reconciliation of interrupted requests is future work.

## Deployment direction

### Application monitoring

Open Monitoring, save an application's public HTTP(S) health-check URL, expected 2xx status and interval. Saving makes no network request. Scheduling defaults to paused. Check now performs one HTTP HEAD request, even while the schedule is paused. Choose an endpoint that supports HEAD and evaluates the application components you want to monitor. A homepage returning 200 does not prove its database or background jobs work.

Enable scheduled checks only for endpoints you want this workspace to contact. The Laravel scheduler runs hub:check-monitors each minute and processes enabled monitors when due. For local automatic checks, run php artisan schedule:work in a separate terminal; stop that process when finished. It has not been started automatically. On the server, add Laravel's once-per-minute cron entry as the site's application user, using the actual deployed directory:

    * * * * * cd /absolute/path/to/content-hub/hub && /usr/bin/php artisan schedule:run >> /absolute/path/to/content-hub/hub/storage/logs/scheduler.log 2>&1

The dashboard shows the last sweep, last observed status and stale results when overdue. Checks use a 10-second HTTP timeout, verified TLS, no redirects, no credentials/cookies, and no response-body storage. Only public IPv4 hostnames on ports 80/443 are supported; literal IPs, private/reserved DNS results, URL credentials, query strings and fragments are blocked. DNS is validated for each check and the connection is pinned to the checked IP. PHP cURL is required.

Observed downtime starts at a failed check and ends at the recovery check. Pauses, settings changes and long gaps close the observation at its last sample, without claiming continuous downtime during unobserved periods. In-flight checks cannot replace results for changed settings, overlapping checks are skipped, and an abandoned check can be replaced after two minutes. Scheduled sweeps remove completed checks older than 30 days; outage records remain. Email/SMS/WhatsApp alert delivery and an independent external monitor are not part of this stage.

If Content Hub shares the monitored server, total server outages stop these checks as well. Use an independent external monitor for that case. Production DNS, HTTPS, cron and deployment have not been configured yet. The proposed production address is social.examelite.com.

### Server setup

Use a separate Virtualmin site, operating-system application user and MariaDB database on the existing server. Point the web root to hub/public. Set APP_ENV=production, APP_DEBUG=false, APP_URL to the HTTPS domain and SESSION_SECURE_COOKIE=true. Keep .env and application storage outside the public root. Configure the database and run migrations as the application user.

Back up APP_KEY securely with the database: changing or losing it makes saved provider keys unreadable. Do not commit .env, database files or logs.

The server audit was read-only. Deployment and HTTPS still need verification on the server.

### Deploy and update through Git

Repository: https://github.com/menhadi/socialmedia. This public repository contains source code only. Environment files, local databases, credentials and internal planning/server notes are excluded.

For the first deployment, clone the repository into a dedicated application directory outside the existing website's document root, as the application user:

    git clone https://github.com/menhadi/socialmedia.git content-hub
    cd content-hub/hub
    composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
    cp .env.example .env
    php artisan key:generate --no-interaction

Use PHP 8.4 for both the command line and this site's web handler. Configure the new `.env` with production settings and a dedicated database before continuing. Use a unique SESSION_COOKIE such as content_hub_session, leave SESSION_DOMAIN unset, and set SESSION_SECURE_COOKIE=true. Keep the application directory outside the public web root and point only the new subdomain's DocumentRoot to this clone's `hub/public`. Give the application user write access to `storage` and `bootstrap/cache`.

    php artisan migrate --force --no-interaction
    php artisan hub:create-owner
    php artisan config:cache
    php artisan view:cache

Do not run database seeders in production. Configure the scheduler using the deployed directory and PHP 8.4 binary. Verify login, HTTPS and a manual monitoring check before enabling scheduled checks. A fresh deployment starts with an empty database; local application profiles and accounts are not uploaded through Git.

For future updates, first test the intended commit and back up the production database, `.env` (including APP_KEY) and application storage. Pause this application's scheduler while updating and let any running checks or publishing requests finish. Record `git rev-parse HEAD` for recovery, then run from the deployed `hub` directory:

    php artisan down
    git pull --ff-only origin main
    composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
    php artisan migrate --force --no-interaction
    php artisan config:cache
    php artisan view:cache
    php artisan up

Run each command only if the preceding command succeeds. Resume the scheduler after verifying the application. Never regenerate APP_KEY or replace `.env` during updates. If an update fails, keep this application in maintenance mode while investigating; recovery may require restoring the matching database backup as well as the previous code. These commands affect the separate Content Hub checkout and database; configuration changes must target only its subdomain.

## AI images and short videos

After deploying the media migration, open **AI providers → Image & video providers**. Configure Google Gemini image generation and/or Veo video generation with your own Google API key, model access, a conservative per-request cost estimate, daily budget, and request limit. Media generation starts disabled with zero budget. No key or media is committed to Git.

Select an image and video provider separately in each application's edit screen. The existing text provider remains independent. From a saved post, choose **Create AI image or video**, enter visual direction, choose landscape/portrait (or square for images), and submit. Veo requests produce one eight-second 720p video; optionally use the attached image as its starting frame. The existing scheduler runs `hub:generate-media` every minute; no extra cron entry is needed. PHP GD is needed for validated PNG conversion.

The media page shows queued, processing, completed, failed, and uncertain requests. Completed files are private previews/downloads until the owner chooses **Use this image/video**. This replaces the current attachment, clears review, and cancels queued publishing. Editing the post also clears attachments. Review again to publish or schedule to the chosen channel. Video uploads may require processing before the public link becomes visible. Choose a channel-compatible format before generating, such as square Instagram images or portrait Reels/Shorts.

Research sources can select AI image or video generation alongside the researched draft. These media drafts require visual review before scheduling; exact-source automatic publishing with branded cards remains available. Media budgets are shared across the owner's applications per media service, independently of text budgets. One configured estimate is reserved per request; failed/uncertain submitted requests retain it and never automatically resubmit. Queued requests expire at the next UTC day without being submitted. Submitted video operations are polled using an encrypted key snapshot, removed when the job finishes. The configured estimate is not an invoice or a guaranteed provider billing cap.

Provider documentation: [Gemini images](https://ai.google.dev/gemini-api/docs/image-generation), [Veo videos](https://ai.google.dev/gemini-api/docs/veo), [Google API pricing](https://ai.google.dev/gemini-api/docs/pricing). Real paid generation and Facebook video publication must be verified with configured live accounts after deployment; automated tests use fake provider responses.

## API references

- Laravel installation: https://laravel.com/docs/13.x/installation
- OpenAI Responses: https://developers.openai.com/api/reference/cli/resources/responses/methods/create
- DeepSeek Chat Completions: https://api-docs.deepseek.com/
- Gemini GenerateContent: https://ai.google.dev/api/generate-content
- Claude Messages: https://platform.claude.com/docs/en/api/messages/create
- Meta Pages setup: https://developers.facebook.com/docs/pages-api/getting-started/
- Meta Page publishing reference: https://github.com/facebook/facebook-php-business-sdk/blob/25.0.0/src/FacebookAds/Object/Page.php

## Application content automation and analytics

Content automation stores an opt-in rule per application, platform and category. Each rule selects a verified account, minimum spacing, daily post limit (UTC), trusted intake, optional branded image and performance guidance. Changes invalidate queued deliveries under the older rule version. Existing drafts are never swept into automation: use **Assess and schedule automatically** on a saved draft after confirming its facts. English is the automated default; explicit manual Hindi generation remains available.

New content can be entered in Content automation or sent by an application backend to `POST /api/v1/content`, using its own Bearer token generated in that screen. Required JSON fields: `external_id`, `category` (`general` or `question` for automation), `channel`, `title`, `body`; optional `source_url`. Use a unique revision ID per item and one request per channel. Repeated identical requests are idempotent; changed content under the same ID returns 409. Tokens are hashed, application-scoped, and shown once; rotating revokes the previous token. The website backend integration must be installed separately: this feature does not automatically read another application's private database.

Approved or explicitly trusted content is assessed by AI under existing provider budgets. Automatic output uses exact approved excerpts, with AI-selected angles and hashtags, rather than unverifiable new factual claims. Unmatched evidence, AI concerns, provider errors, missing media and channel restrictions hold the item with a visible reason. Exam announcements/results use the Research official-source workflow. An enabled matching `official` rule permits the initial source capture when evidence checks pass; source approval and content are rechecked before sending. Unreviewed AI images/videos still require visual review. YouTube requires an attached video, and WhatsApp requires recipient/template options; unsupported combinations are held, not converted silently.

The existing scheduler also runs `hub:run-growth-workflow` each minute. It handles bounded content batches and refreshes published post metrics in batches (eligible every six hours, last 90 days). Analytics has application, platform and post filters and displays the latest lifetime counters, not sums of repeated snapshots. Facebook reactions/comments/shares, Instagram likes/comments, X public metrics, YouTube statistics and LinkedIn organization share statistics are requested where API permissions allow. Unavailable values remain unknown. LinkedIn personal analytics and WhatsApp delivery/read webhooks are not implemented.

Automated links pointing to the configured application hostname receive UTM parameters and `hub_publication`. The application backend can report `visit`, `registration` or `conversion` to `POST /api/v1/events` with `external_id`, `name`, optional `publication_id`, and the same Bearer token. It must deduplicate genuine events and send no personal data. These reported events are distinct from platform impressions/clicks; there is no automatic proof of a genuine visitor. Links to official third-party sources are not altered. Manual posts and previously published links are unchanged.

Performance guidance uses at least three posts on the same application/platform, published 1–30 days ago, with metrics no older than two days. It supplies examples to future content generation; different exposure and post ages mean these are tentative patterns, not causal claims or guaranteed improvements. It does not rewrite already published posts, fine-tune models, or automatically change configured limits/times.

**Archive in Content Hub** is reversible and cancels queued publishing. It never deletes a platform post. Deleting a platform post does not erase local history; an unavailable analytics response may also mean permissions/privacy changed and does not trigger republishing. Running or uncertain submissions must be resolved before archive.
