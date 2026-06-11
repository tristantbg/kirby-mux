<?php

namespace KirbyMux;

use Kirby\Cms\File;
use Kirby\Panel\Ui\FilePreview;

/**
 * Custom Panel file preview for Mux videos. Renders the Mux thumbnail (and an
 * HLS player) instead of trying to play the local source file, which may have
 * been removed when `optimizeDiskSpace` is enabled.
 */
class MuxFilePreview extends FilePreview
{
    public function __construct(
        public File $file,
        public string $component = 'k-mux-file-preview'
    ) {}

    /**
     * Use this preview for video files that have a Mux playback id stored.
     */
    public static function accepts(File $file): bool
    {
        if ($file->type() !== 'video' && $file->template() !== 'mux-video') {
            return false;
        }

        return $file->mux()->isNotEmpty() && $file->muxPlaybackId() !== null;
    }

    /**
     * Pass the Mux thumbnail and stream URLs to the Vue component on top of the
     * default file preview props (url, details, image).
     */
    public function props(): array
    {
        $playbackId = $this->file->muxPlaybackId();

        return [
            ...parent::props(),
            'thumbnail' => $playbackId ? $this->file->muxThumbnail() : null,
            'streamUrl' => $playbackId ? $this->file->muxUrlStream() : null,
        ];
    }
}
