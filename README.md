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

Add a `.env` file to the plugin directory (`site/plugins/kirby-mux/.env`) with the following properties:

| Key              | Type      | Description                                   |
| ---------------- | --------- | --------------------------------------------- |
| MUX_TOKEN_ID     | `String`  | Your Mux API Access Token ID                  |
| MUX_TOKEN_SECRET | `String`  | Your Mux API Access Token Secret              |
| MUX_DEV          | `Boolean` | Use a test video instead of the actual upload |

> **NOTE:** A `.env.example` file is included in the plugin.

### Options

| Option              | Type      | Default | Description                                               |
| ------------------- | --------- | ------- | --------------------------------------------------------- |
| `optimizeDiskSpace` | `Boolean` | `false` | Download the low-res MP4 locally and replace the original |

Set options in your Kirby config:

```php
// site/config/config.php
return [
  'tristantbg.kirby-mux.optimizeDiskSpace' => true,
];
```

### MUX_DEV

Set this to `true` for local development. Instead of the actual video, the plugin will upload a test video to Mux. This is necessary since videos need to be publicly accessible for Mux to import them.

## Static Renditions

The plugin automatically requests static MP4 renditions at **270p**, **720p**, and **1080p** for every uploaded video.

## File Methods

All methods are available on Kirby `File` objects with a `mux` field.

### `$file->muxPlaybackId()`

Returns the Mux playback ID.

### `$file->muxUrlLow()`

Returns the URL to the **270p** static MP4 rendition. Waits for renditions to be ready if necessary.

### `$file->muxUrlHigh()`

Returns the URL to the **1080p** static MP4 rendition (falls back to **720p** if only one rendition file is available). Waits for renditions to be ready if necessary.

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

- **`file.create:after`** — Uploads the video to Mux, waits for processing, saves a thumbnail, and stores asset data.
- **`file.delete:before`** — Deletes the Mux asset and removes the local thumbnail.
- **`file.replace:before`** — Deletes the old Mux asset.
- **`file.replace:after`** — Uploads the replacement video to Mux and processes it.

## Blueprints

The plugin provides two blueprints:

- `files/mux-video` — File blueprint for Mux video files
- `blocks/mux-video` — Block blueprint for embedding Mux videos

## Caveats

The plugin does not include any frontend-facing code or snippets. To stream videos from Mux, implement your own video player. [HLS.js](https://github.com/video-dev/hls.js/) is a good option.

## License

MIT
