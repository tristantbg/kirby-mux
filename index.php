<?php

@include_once __DIR__ . '/vendor/autoload.php';
Dotenv\Dotenv::createImmutable(kirby()->root('base'))->load(); // TODO: Add configurable path for .env

Kirby::plugin('robinscholz/kirby-mux', [
    'translations' => [
        'en' => [
            'field.blocks.mux-video.thumbnail' => 'Generate thumbnail from frame',
            'field.blocks.mux-video.thumbnail.help' => 'In seconds',
        ],
        'de' => [
            'field.blocks.mux-video.thumbnail' => 'Thumbnail aus Frame generieren',
            'field.blocks.mux-video.thumbnail.help' => 'In Sekunden',
        ],
    ],
    'blueprints' => [
        'files/mux-video' => __DIR__ . '/blueprints/files/mux-video.yml',
        'blocks/mux-video' => __DIR__ . '/blueprints/blocks/mux-video.yml'
    ],
    'fileMethods' => [
        'muxUrlLow' => function () {
            $playbackId = json_decode($this->mux())->playback_ids[0]->id;
            return "https://stream.mux.com/".$playbackId."/low.mp4";
        },
        'muxUrlHigh' => function () {
            $playbackId = json_decode($this->mux())->playback_ids[0]->id;
            return "https://stream.mux.com/".$playbackId."/high.mp4";
        },
        'muxUrlStream' => function () {
            $playbackId = json_decode($this->mux())->playback_ids[0]->id;
            return "https://stream.mux.com/".$playbackId.".m3u8";
        },
        'muxThumbnail' => function ($width = null, $height = null, Float $time = null) {
            $playbackId = json_decode($this->mux())->playback_ids[0]->id;
            $url = "https://image.mux.com/".$playbackId."/thumbnail.jpg";

            $params = [];
            if ($width) {
              $params['width'] = $width;
            }
            if ($height) {
              $params['height'] = $height;
              $params['fit_mode'] = 'smartcrop';
            }
            if ($time) {
              $params['time'] = $time;
            }
            if (count($params)) {
              $url .= '?'.http_build_query($params);
            }
            return $url;
        },
        'muxThumbnailAnimated' => function ($width = null, $height = null, Float $start = null, Float $end = null) {
            $playbackId = json_decode($this->mux())->playback_ids[0]->id;
            $url = "https://image.mux.com/".$playbackId."/animated.gif";

            $params = [];
            if ($width) {
              $params['width'] = $width;
            }
            if ($height) {
              $params['height'] = $height;
            }
            if ($start) {
              $params['start'] = $start;
            }
            if ($end) {
              $params['end'] = $end;
            }
            if (count($params)) {
              $url .= '?'.http_build_query($params);
            }
            return $url;
        }
    ],
    'hooks' => [
        'file.create:after' => function (Kirby\Cms\File $file) {
            if ($file->type() !== 'video') { return; }

            // Authenticate
            $assetsApi = KirbyMux\Auth::assetsApi();

            // Upload the file to mux
            $result = KirbyMux\Methods::upload($assetsApi, $file->url());

            // Save mux data
            try {
                $file = $file->update([
                  'mux' => $result->getData()
                ]);
            } catch(Exception $e) {
                throw new Exception($e->getMessage());
            }
        },
        'file.delete:before' => function (Kirby\Cms\File $file) {
            if ($file->type() !== 'video') { return; }

            // Authentication setup
            $assetsApi = KirbyMux\Auth::assetsApi();

            // Get mux Id
            $muxId = json_decode($file->mux())->id;

            // Delete Asset
            try {
                $assetsApi->deleteAsset($muxId);
            } catch (Exception $e) {
                throw new Exception($e->getMessage());
            }
        },
        'file.replace:before' => function (Kirby\Cms\File $file, Kirby\Filesystem\File $upload) {
            if ($upload->type() !== 'video') { return; }

            // Authentication setup
            $assetsApi = KirbyMux\Auth::assetsApi();

            // Get old mux Id
            $muxId = json_decode($file->mux())->id;

            // Delete old asset
            try {
                $assetsApi->deleteAsset($muxId);
            } catch (Exception $e) {
                throw new Exception($e->getMessage());
            }
        },
        'file.replace:after' => function (Kirby\Cms\File $newFile, Kirby\Cms\File $oldFile) {
            if ($newFile->type() !== 'video') { return; }

            // Authentication setup
            $assetsApi = KirbyMux\Auth::assetsApi();

            // Upload new file to mux
            $result = KirbyMux\Methods::upload($assetsApi, $newFile->url());

            // Save playback Id
            try {
                $newFile = $newFile->update([
                'mux' => $result->getData()
                ]);
            } catch(Exception $e) {
            throw new Exception($e->getMessage());
            }
        }
    ]
]);
