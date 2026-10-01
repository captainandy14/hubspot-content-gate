# HubSpot Content Gate

A WordPress plugin that gates article bodies behind a HubSpot form. Visitors see a teaser of the article, then a HubSpot form asks for their email to keep reading. Submitting the form unlocks the full article, and the browser remembers the unlock for next time.

It works site-wide through the `the_content` filter, so there are no shortcodes to place. Everything is configured under **Settings → Content Gate**.

## Features

- Gates posts and other public post types behind a HubSpot form
- Optional category filter for post types that use categories
- Configurable teaser length and a minimum article length below which nothing is gated
- Works with page builders such as Elementor, because the teaser is clipped in the browser instead of by splitting the HTML
- Hides extra elements (for example a table of contents) while the gate is up, restoring them on unlock
- Per-post checkbox to exempt a single post from the gate
- Logged-in users read freely by default, with an option to gate them too

## Requirements

- WordPress 6.0 or later
- PHP 7.4 or later
- A HubSpot account with a form (you need the Portal ID and Form ID)

## Installation

1. Download the latest release zip from the [Releases](../../releases) page.
2. In WordPress, go to **Plugins → Add New → Upload Plugin** and upload the zip.
3. Activate the plugin.
4. Go to **Settings → Content Gate**, enter your **Portal ID** and **Form ID**, choose your region, and save.

The gate does nothing until both a Portal ID and a Form ID are set.

## Settings

| Setting | What it does | Default |
| --- | --- | --- |
| Portal ID | Your HubSpot portal (account) ID | empty |
| Form ID | The HubSpot form shown in the gate | empty |
| Region | HubSpot data region, `na1` (US) or `eu1` (EU) | `na1` |
| Gate enabled | Master switch for the whole site | on |
| Gate these post types | Public post types to gate | `post` |
| Gate these categories | Only posts in a ticked category are gated. Applies to post types that use the standard `category` taxonomy. Tick none to gate nothing for those types. Post types without categories are gated on post type alone. | none |
| Free words | Approximate number of words shown before the gate | 120 |
| Minimum length | Posts shorter than this many words are not gated | 200 |
| Logged-in users | Also gate logged-in users | off |
| Load HubSpot script | Let the plugin load the HubSpot forms script. Turn this off if Google Tag Manager or another tool already loads it. | on |
| Prompt text | Text shown above the form | "Enter your email to keep reading." |
| Hide while gated | Comma-separated CSS selectors to hide while the gate is up, such as a table of contents. They are restored on unlock. | empty |

### Exempting a single post

Each gated post type gets a **Content Gate** box in the editor sidebar. Tick "Do not gate this post" to show that post in full.

## How it works

For an eligible post, the plugin outputs the full article inside a wrapper, followed by a container for the HubSpot form. In the browser, a small script walks the rendered text to the configured word count, clips the wrapper to that height with a fade, and renders the form below it. When HubSpot reports a form submission, the clip is removed, the gate is hidden, and `hsg_unlocked` is stored in the browser's `localStorage` so returning visitors see the full article.

Only the primary post of the page is gated. Related posts, sidebars and other secondary posts are left alone.

## Limitations

This is a **client-side lead-capture gate, not access control**. Please know what that means before using it:

- The full article text is present in the page source. Anyone who views the source, disables JavaScript, or uses reader mode can read it.
- The unlock is a flag in the visitor's browser. Clearing site data brings the gate back, and setting the flag by hand removes it.
- Search engines and other crawlers can read the full text.

Use it to encourage sign-ups, not to protect paid or confidential content. For real protection, the content must be withheld on the server.

## FAQ

**The form never appears.**
Check that the Portal ID and Form ID are correct, that the region matches your HubSpot account, and that the HubSpot forms script is loading. If Google Tag Manager already loads it, turn off "Load HubSpot script" so it isn't loaded twice.

**A post isn't being gated.**
Check that its post type is ticked, that it is in a ticked category (for post types that use categories), that it is longer than the minimum length, that "Do not gate this post" is not ticked, and that you are not logged in (logged-in users are not gated by default).

**Can I change the fade colour?**
The fade is white by default. Override `.hsg-content.hsg-clipped::after` in your theme CSS if your site has a different background.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).

## Author

Anando DasGupta
