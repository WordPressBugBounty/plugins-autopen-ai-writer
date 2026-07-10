=== AutoPen – AI Content Writer ===
Contributors: lkimran
Tags: ai, content automation, blog generator, openai, chatgpt
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: autopen-ai-writer

Automate high-quality, SEO-focused blog posts using OpenAI's most advanced models, right inside WordPress.

== Description ==

**AutoPen** leverages the power of OpenAI's most advanced GPT models to automatically generate and publish long-form, SEO-optimized blog posts directly to your WordPress site.
Designed for content marketers, agencies, and busy site owners, this plugin provides a professional-grade automation engine that saves you time and scales your content strategy.
Simply add your topics, configure your settings, and let AutoPen handle the entire content pipeline—from drafting and SEO optimization to image generation and publishing.

This plugin requires a third-party service, OpenAI, to function.
* **Service:** OpenAI API (https://openai.com/api/)
* **Function:** Used to generate text content and images for blog posts.
* **Data Sent:** Topics, prompts, and content snippets are sent to the OpenAI API for processing. No user data is sent.
* **Terms of Service:** https://openai.com/policies/terms-of-use
* **Privacy Policy:** https://openai.com/policies/privacy-policy

== Installation ==

1.  Upload the `autopen-ai-writer` folder to your `/wp-content/plugins/` directory.
2.  Activate the plugin through the 'Plugins' menu in WordPress.
3.  Navigate to the "AutoPen" menu in your WordPress admin sidebar.
4.  Go to the "Settings" tab, enter your OpenAI API key, and click "Save Settings".
5.  Click the "Validate API Key" button to ensure your key is working correctly.
6.  Add topics to the "Topics Queue" on the "Content Settings" section and configure your desired options.
7.  Go to the "Dashboard" tab and click the "Enable Automation" button to start the cron-based generation. You can also use the "Generate Post Now" button for immediate results.

== Frequently Asked Questions ==

= Does this plugin work with the free OpenAI API? =

No. To ensure high-quality content generation, the plugin is designed to use premium models like GPT-4o, GPT-4, and DALL-E 3. You must have a funded OpenAI account.

= What happens if the generation process fails for a topic? =

The plugin will automatically re-queue the topic and try again up to two more times. If it fails a third time, it is moved to a "Failed Topics" list (visible in your settings) with the reason for failure, and the plugin moves on to the next topic. This prevents a single problematic topic from halting all content generation.

= Can I control the style and tone of the generated content? =

Absolutely. The "Prompts" tab in the settings allows you to fully customize the instructions given to the AI. You can define its persona, writing style, formatting requirements, and much more.

= Which SEO plugins are supported? =

The plugin has direct integration for **Rank Math**, **SEOPress**, and **Yoast SEO**. It will automatically populate the meta title, description, and focus keywords for your generated posts.

== Screenshots ==

1.  **Dashboard View**: The main dashboard showing system status, generation statistics, and quick actions.
2.  **Settings Panel**: Configuration options for API keys, content defaults, and automation frequency.
3.  **Prompts Editor**: The powerful prompts editor where you can customize the AI's instructions.
4.  **Logs Viewer**: The activity log for monitoring generation tasks and debugging issues.

== Changelog ==

= 1.0.0 =
* Initial release.