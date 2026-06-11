# 📼 Kirby Mux

A [Kirby](https://getkirby.com) plugin to upload video files to [Mux](https://mux.com).

## Installation

### Download

Download and copy this repository to `/site/plugins/kirby-mux`.

### Git submodule

```
git submodule add https://github.com/tristantbg/kirby-mux.git site/plugins/kirby-mux
```

### Composer

```
composer require tristantbg/kirby-mux
```

## Configuration

Add your Mux API credentials to your Kirby config file:

```php
// site/config/config.php
return [
  'tristantbg.kirby-mux' => [
    'tokenId' => 'XXX', // Your Mux API Access Token ID
    'tokenSecret' => 'XXX', // Your Mux API Secret Key
  ],
];
```

### Options

| Option              | Type      | Default | Description                                               |
| ------------------- | --------- | ------- | --------------------------------------------------------- |
| `tokenId`           | `String`  | `''`    | Your Mux API Access Token ID                              |
| `tokenSecret`       | `String`  | `''`    | Your Mux API Secret Key                                   |
| `webhookSecret`     | `String`  | `''`    | Your Mux webhook signing secret (required for webhooks)   |
| `dev`               | `Boolean` | `false` | Use a test video instead of the actual upload             |
| `optimizeDiskSpace` | `Boolean` | `false` | Download the low-res MP4 locally and replace the original |

### Dev Mode

Set `dev` to `true` for local development. Instead of the actual video, the plugin will upload a test video to Mux. This is necessary since videos need to be publicly accessible for Mux to import them.

```php
// site/config/config.php
return [
  'tristantbg.kirby-mux' => [
    'tokenId' => 'XXX',
    'tokenSecret' => 'XXX',
    'dev' => true,
  ],
];
```

## Webhooks

The plugin processes Mux assets **asynchronously via webhooks** instead of polling the Mux API during page rendering. This avoids `429 Too Many Requests` rate-limit errors that occur when many videos are rendered or when assets are still encoding.

The plugin exposes a webhook endpoint at:

```
https://your-domain.com/mux/webhook
```

This path is provided automatically by the plugin (a Kirby route). However, **the webhook itself must be created manually in the Mux dashboard** — Mux does not offer an API for managing webhooks, so the plugin cannot create, update, or restore it for you.

### Setup (one-time, per environment)

1. In the [Mux dashboard](https://dashboard.mux.com/), go to **Settings → Webhooks** and click **Create new webhook**.
2. Select the environment and set the URL to your site's endpoint:

   ```
   https://your-domain.com/mux/webhook
   ```

3. Copy the webhook **signing secret** Mux generates and add it to your config:

   ```php
   // site/config/config.php
   return [
     'tristantbg.kirby-mux' => [
       'tokenId' => 'XXX',
       'tokenSecret' => 'XXX',
       'webhookSecret' => 'XXX', // Mux webhook signing secret
     ],
   ];
   ```

When Mux finishes processing an asset (status `ready`) or its static renditions, it calls the webhook. The plugin verifies the signature, locates the matching Kirby file via the asset `passthrough`, stores the latest asset data, and saves the thumbnail. The front-end file methods then read only this stored data and never call the Mux API.

### Notes & maintenance

- **Public HTTPS required.** Mux must be able to reach the endpoint over the internet, so it cannot call `localhost`. For local development, expose your site with a tunnel (e.g. [ngrok](https://ngrok.com/) or Cloudflare Tunnel) and point the Mux webhook at the tunnel URL.
- **If your domain changes**, edit the existing webhook's URL in the Mux dashboard to the new domain. The signing secret stays the same, so `webhookSecret` does not need to change. The `mux/webhook` path is relative to the domain and works on whatever domain Kirby is served from.
- **If the webhook is deleted in Mux, it is _not_ recreated automatically.** Mux stops sending events, and stored asset data (thumbnails, rendition status) will no longer update. Re-create it in the dashboard to restore the flow.
- **Retries & recovery.** Mux retries failed deliveries for ~24h, so brief downtime or a late URL fix usually still delivers queued events. For anything missed beyond that window, re-saving or replacing the affected video re-triggers processing.
- **Assets uploaded before webhooks were configured** have no `passthrough` and are skipped by the handler. Re-saving or replacing those videos attaches the passthrough so future events are handled.

> **Note:** Without a configured `webhookSecret` and a reachable webhook URL, the stored asset data will not be updated after upload, and static-rendition URLs may not be available until Mux finishes encoding.

## Static Renditions

The plugin automatically requests static MP4 renditions at **270p**, **720p**, and **1080p** for every uploaded video.

## File Methods

All methods are available on Kirby `File` objects with a `mux` field.

### `$file->muxPlaybackId()`

Returns the Mux playback ID.

### `$file->muxUrlLow()`

Returns the URL to the **270p** static MP4 rendition. Reads stored asset data only (no API call); the rendition becomes available once Mux reports it via webhook.

### `$file->muxUrlHigh()`

Returns the URL to the **1080p** static MP4 rendition (falls back to **720p** if only one rendition file is available). Reads stored asset data only (no API call); the rendition becomes available once Mux reports it via webhook.

### `$file->muxUrlStream()`

Returns the HLS streaming URL (`.m3u8`).

### `$file->muxThumbnail($width, $height, $time, $extension)`

Returns a Mux thumbnail URL.

| Parameter    | Type     | Default | Description                          |
| ------------ | -------- | ------- | ------------------------------------ |
| `$width`     | `int`    | `null`  | Thumbnail width                      |
| `$height`    | `int`    | `null`  | Thumbnail height (enables smartcrop) |
| `$time`      | `float`  | `null`  | Time in seconds for the frame        |
| `$extension` | `string` | `jpg`   | Image format (`jpg`, `png`, `webp`)  |

### `$file->muxThumbnailAnimated($width, $height, $start, $end, $fps, $extension)`

Returns a Mux animated thumbnail URL.

| Parameter    | Type     | Default | Description              |
| ------------ | -------- | ------- | ------------------------ |
| `$width`     | `int`    | `null`  | Thumbnail width          |
| `$height`    | `int`    | `null`  | Thumbnail height         |
| `$start`     | `float`  | `null`  | Start time in seconds    |
| `$end`       | `float`  | `null`  | End time in seconds      |
| `$fps`       | `int`    | `null`  | Frames per second        |
| `$extension` | `string` | `gif`   | Format (`gif` or `webp`) |

### `$file->muxKirbyThumbnail()`

Returns a Kirby `File` object for the Mux thumbnail. Downloads and saves the thumbnail locally on first call.

## Hooks

The plugin handles the full lifecycle of video files automatically:

- **`file.create:after`** — Uploads the video to Mux and stores the initial asset data (non-blocking).
- **`file.delete:before`** — Deletes the Mux asset and removes the local thumbnail.
- **`file.replace:before`** — Deletes the old Mux asset.
- **`file.replace:after`** — Uploads the replacement video to Mux.

Thumbnails, static renditions, and optional disk optimization are finalized asynchronously by the [webhook](#webhooks) once Mux finishes processing.

## Blueprints

The plugin provides two blueprints:

- `files/mux-video` — File blueprint for Mux video files
- `blocks/mux-video` — Block blueprint for embedding Mux videos

## Caveats

The plugin does not include any frontend-facing code or snippets. To stream videos from Mux, implement your own video player. [HLS.js](https://github.com/video-dev/hls.js/) is a good option.

## License

MIT
