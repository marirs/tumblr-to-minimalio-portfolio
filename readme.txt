=== Tumblr to Minimalio Portfolio ===
Contributors: marirs
Tags: tumblr, import, portfolio, minimalio, ai
Requires at least: 5.8
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Import photos and videos from Tumblr into the Minimalio Portfolio custom post type with AI-powered SEO generation.

== Description ==

A WordPress plugin that imports photos and videos from a Tumblr blog into the [Minimalio Portfolio](https://minimalio.org/premium-plugin/) custom post type, with AI-powered SEO title, description, alt text, and category generation.

= Features =

* **Tumblr Import** — Fetch and selectively import photo, video, and text posts from any public Tumblr blog.
* **Image Sideloading** — Downloads Tumblr images into your WordPress media library and sets them as featured images.
* **Smart Image Handling** — Single image posts get a featured image only; multi-image posts get the first as featured and the rest as Gutenberg gallery blocks.
* **Video Support** — Embeds Vimeo and other video posts with proper Gutenberg embed blocks.
* **Duplicate Detection** — Skips posts that have already been imported.
* **Resume Import** — If the browser is closed mid-import, the fetched post list is restored on the next visit so you can continue where you left off.
* **Tag Preservation** — Maps Tumblr tags to Portfolio Tags taxonomy.
* **Original Date** — Preserves the original Tumblr post date.
* **Post Status** — Choose to import posts as Published (live immediately) or Draft (review first).
* **Post Author** — Assign all imported posts to a specific WordPress user.

= AI-Powered SEO Generation =

When enabled, the plugin uses AI to generate SEO-optimized metadata for imported posts:

* **SEO Title** — max 60 characters, concise and descriptive
* **SEO Description** — max 254 characters, engaging meta description
* **Image Alt Text** — max 125 characters, AI-generated for accessibility

The AI system uses a three-tier failover chain:

1. **Vision AI** (image + tags) — Sends the image to an AI vision model for the highest quality results.
   * OpenAI (GPT-4o-mini) — Pay-per-use (~$0.001/image). Highest quality.
   * Google Gemini — Free tier: 15 req/min, 1,500/day. Great balance of quality and cost.
   * Cloudflare Workers AI (LLaVA) — Free tier: ~100–200 images/day.

2. **Text AI** (tags only) — If vision AI fails or is rate-limited, sends only the post tags to generate titles.
   * ChatGPT (text) — Reuses your OpenAI API key. ~$0.0001/request.
   * Gemini (text) — Reuses your Gemini API key. Free.

3. **Tag Fallback** (no AI) — Last resort. Builds a title from tags using simple concatenation rules.

= AI Category Assignment =

* Assign categories to imported posts using AI.
* Choose between using existing categories only or allowing the AI to create new ones.

= Requirements =

* [Minimalio Portfolio](https://minimalio.org/premium-plugin/) plugin (must be active)
* A [Tumblr API key](https://www.tumblr.com/oauth/apps) (free)
* **For AI SEO generation (optional)** — at least one of the following:
  * [OpenAI API key](https://platform.openai.com/api-keys) (pay-per-use, also powers ChatGPT text fallback)
  * [Google Gemini API key](https://aistudio.google.com/apikey) (free tier available, also powers Gemini text fallback)
  * [Cloudflare Workers AI](https://dash.cloudflare.com) account ID + API token (free tier available)

== Installation ==

1. Upload the `tumblr-to-minimalio-portfolio` folder to the `/wp-content/plugins/` directory.
2. Activate the **Minimalio Portfolio** plugin first.
3. Activate **Tumblr to Minimalio Portfolio** through the 'Plugins' menu in WordPress.
4. Go to **Tools → Import → Tumblr to Minimalio Portfolio**.

== Configuration ==

= Tumblr API =

1. Go to [tumblr.com/oauth/apps](https://www.tumblr.com/oauth/apps) and register an application.
2. Copy the **OAuth Consumer Key** (this is your API key).
3. Enter it on the plugin settings page along with your Tumblr blog URL.

= AI Services (Optional) =

Configure one or more AI services to enable SEO generation:

* **Google Gemini** — Get a free API key from [aistudio.google.com/apikey](https://aistudio.google.com/apikey).
* **OpenAI** — Get an API key from [platform.openai.com/api-keys](https://platform.openai.com/api-keys).
* **Cloudflare Workers AI** — Log in to [dash.cloudflare.com](https://dash.cloudflare.com), find your Account ID in the sidebar, and create an API Token with `Account.Workers AI:Read` permission.

= Service Priority =

Drag and drop the AI service cards on the settings page to set the failover priority. The order determines which service is tried first. If it fails or is rate-limited, the next service in the chain is used.

= Import Settings =

* **Post Status** — Choose between Published (posts go live immediately) or Draft (review before publishing).
* **Post Author** — Select which WordPress user the imported posts should be assigned to.

== Frequently Asked Questions ==

= Does this plugin require the Minimalio Portfolio plugin? =

Yes. This plugin imports posts into the `portfolio` custom post type provided by [Minimalio Portfolio](https://minimalio.org/premium-plugin/).

= Do I need AI API keys? =

No. AI-powered SEO generation is optional. Without it, the plugin will use Tumblr post titles and tags. If no title exists, a random title is generated as a fallback.

= Can I resume an import if my browser closes? =

Yes. The plugin saves your fetched post list and tracks which posts have been imported. When you return, the list is restored with already-imported posts marked, so you can continue from where you left off.

= What happens if I import a post that already exists? =

The plugin detects duplicates by Tumblr post ID and skips them automatically.

= Which AI service should I use? =

For the best quality, use **OpenAI** (pay-per-use). For a free option, **Google Gemini** offers excellent results with a generous free tier. **Cloudflare Workers AI** is also free but lower quality. You can configure all three — the plugin will failover automatically.

== Screenshots ==

1. The settings page — enter your Tumblr blog URL, API key, and configure AI services.
2. AI service configuration — drag to reorder failover priority, test API keys, configure categories.
3. Import in progress — posts with AI-generated titles, source badges, and tabbed import log.

== Changelog ==

= 1.0.0 =
* Initial release.
* Tumblr photo, video, and text post import.
* AI-powered SEO title, description, alt text, and category generation.
* Three-tier AI failover chain (Vision AI → Text AI → Tag Fallback).
* Support for OpenAI, Google Gemini, and Cloudflare Workers AI.
* Drag-and-drop AI service priority ordering.
* Smart image handling with Gutenberg gallery blocks.
* Vimeo and video embed support.
* Duplicate detection and tag preservation.
* Resume import with progress tracking.
* Configurable post status (Published/Draft) and post author.
* Tabbed UI with Posts and Import Log views.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
